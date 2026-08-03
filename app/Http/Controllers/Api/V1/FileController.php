<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Patient;
use App\Services\AuditService;
use App\Services\EncryptedStorageService;
use App\Support\ApiResponse;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    public function __construct(
        private EncryptedStorageService $files,
        private AuditService $audit,
    ) {}

    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'doc_type' => ['required', 'string', 'in:insurance_card,selfie,photo_id,lab_result,clinical_document,other'],
            'patient_id' => ['nullable', 'integer', 'exists:patients,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'side' => ['nullable', 'string', 'in:front,back'],
        ]);

        $patientId = $data['patient_id'] ?? null;
        if ($patientId) {
            $patient = Patient::query()->findOrFail($patientId);
            abort_unless($request->user()->canAccessPatient($patient), 403, 'PHI access denied for this patient.');
        }

        // Front Desk may only upload demographics-related docs.
        if ($request->user()->hasRole(Roles::FRONT_DESK)
            && ! in_array($data['doc_type'], ['insurance_card', 'selfie', 'photo_id'], true)) {
            abort(403, 'Front Desk cannot upload clinical documents.');
        }

        $document = $this->files->storeEncrypted(
            $request->file('file'),
            $request->user(),
            $patientId,
            $data['doc_type'],
            $data['title'] ?? null,
        );

        if ($patientId) {
            $patient = Patient::query()->find($patientId);
            if ($patient) {
                if ($data['doc_type'] === 'selfie') {
                    $patient->update(['photo_path' => 'document:'.$document->id]);
                }

                if ($data['doc_type'] === 'insurance_card') {
                    $side = $request->string('side')->toString() ?: 'front';
                    $insurance = $patient->insurances()->latest('id')->first();
                    if ($insurance) {
                        if ($side === 'back') {
                            $insurance->update(['card_back_path' => 'document:'.$document->id]);
                        } else {
                            $insurance->update(['card_front_path' => 'document:'.$document->id]);
                        }
                    }
                }
            }
        }

        $url = $this->files->temporaryUrl($document, $request->user());

        return ApiResponse::created([
            'id' => $document->id,
            'title' => $document->title,
            'doc_type' => $document->doc_type,
            'mime_type' => $document->mime_type,
            'byte_size' => $document->byte_size,
            'is_encrypted' => $document->is_encrypted,
            'signed_url' => $url,
            'expires_in_minutes' => 5,
        ], 'File uploaded and encrypted');
    }

    public function signedUrl(Request $request, Document $document): JsonResponse
    {
        abort_unless($request->user()->clinic_id === $document->clinic_id, 403);

        if ($document->patient_id) {
            $patient = Patient::query()->findOrFail($document->patient_id);
            abort_unless($request->user()->canAccessPatient($patient), 403, 'PHI access denied for this patient.');
        }

        $minutes = min(30, max(1, (int) $request->integer('minutes', 5)));
        $url = $this->files->temporaryUrl($document, $request->user(), $minutes);

        $this->audit->log(
            'file.signed_url',
            'allowed',
            $request->user(),
            $request,
            $document->patient_id,
            Document::class,
            $document->id,
            ['minutes' => $minutes]
        );

        return ApiResponse::success([
            'document_id' => $document->id,
            'signed_url' => $url,
            'expires_in_minutes' => $minutes,
        ]);
    }

    public function download(Request $request, Document $document): StreamedResponse|Response|JsonResponse
    {
        if (! URL::hasValidSignature($request)) {
            return ApiResponse::error('Signed URL is invalid or expired.', 403, 'FORBIDDEN');
        }

        $user = $request->user();
        abort_unless($user && $user->clinic_id === $document->clinic_id, 403);

        if ($document->patient_id) {
            $patient = Patient::query()->findOrFail($document->patient_id);
            abort_unless($user->canAccessPatient($patient), 403, 'PHI access denied for this patient.');
        }

        $plain = $this->files->decryptContents($document);

        $this->audit->log(
            'file.download',
            'allowed',
            $user,
            $request,
            $document->patient_id,
            Document::class,
            $document->id
        );

        return response($plain, 200, [
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addslashes($document->title).'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
