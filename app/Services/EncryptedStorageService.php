<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EncryptedStorageService
{
    public const DISK = 'phi';

    public const MAX_BYTES = 10_485_760; // 10 MB

    /** @var list<string> */
    public const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    public function __construct(private AuditService $audit) {}

    public function storeEncrypted(
        UploadedFile $file,
        User $uploader,
        ?int $patientId,
        string $docType,
        ?string $title = null,
    ): Document {
        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'file' => ['File exceeds the 10 MB limit.'],
            ]);
        }

        $mime = $file->getMimeType() ?: 'application/octet-stream';
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages([
                'file' => ['File type is not allowed.'],
            ]);
        }

        $clinicId = $uploader->clinic_id;
        abort_unless($clinicId, 403, 'Clinic context required for file uploads.');

        $raw = file_get_contents($file->getRealPath());
        if ($raw === false) {
            throw ValidationException::withMessages([
                'file' => ['Unable to read uploaded file.'],
            ]);
        }

        // AES-256-CBC via Laravel Crypt (APP_KEY). Ciphertext never stored in plaintext.
        $encrypted = Crypt::encrypt($raw);
        $relative = sprintf(
            'clinic_%d/%s/%s.enc',
            $clinicId,
            $docType,
            Str::uuid()->toString()
        );

        Storage::disk(self::DISK)->put($relative, $encrypted);

        if ($clinic = Clinic::query()->find($clinicId)) {
            $clinic->increment('storage_used_mb', max(1, (int) ceil(strlen($encrypted) / 1_048_576)));
        }

        $document = Document::create([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'uploaded_by' => $uploader->id,
            'title' => $title ?: $file->getClientOriginalName(),
            'file_path' => $relative,
            'doc_type' => $docType,
            'is_encrypted' => true,
            'mime_type' => $mime,
            'byte_size' => strlen($raw),
            'checksum_sha256' => hash('sha256', $raw),
            'is_signed' => false,
        ]);

        $this->audit->log(
            'file.upload',
            'allowed',
            $uploader,
            request(),
            $patientId,
            Document::class,
            $document->id,
            ['doc_type' => $docType, 'mime' => $mime]
        );

        return $document;
    }

    public function decryptContents(Document $document): string
    {
        abort_unless($document->is_encrypted, 500, 'Document is not encrypted.');

        $cipher = Storage::disk(self::DISK)->get($document->file_path);
        if ($cipher === null) {
            abort(404, 'File not found.');
        }

        $plain = Crypt::decrypt($cipher);

        if ($document->checksum_sha256 && hash('sha256', $plain) !== $document->checksum_sha256) {
            abort(500, 'File integrity check failed.');
        }

        return $plain;
    }

    public function temporaryUrl(Document $document, User $user, int $minutes = 5): string
    {
        if (! $user->clinic_id || $user->clinic_id !== $document->clinic_id) {
            abort(403, 'File access denied.');
        }

        return URL::temporarySignedRoute(
            'api.v1.files.download',
            now()->addMinutes($minutes),
            ['document' => $document->id]
        );
    }
}
