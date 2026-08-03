<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\PatientIntakeSession;
use App\Services\AuditService;
use App\Support\ApiResponse;
use App\Support\PhiGate;
use App\Support\UsValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IntakeController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function show(string $token): JsonResponse
    {
        $session = $this->findOpenSession($token);
        $clinic = $session->clinic;

        $prefill = null;
        if ($session->patient_id) {
            $patient = Patient::query()->find($session->patient_id);
            $prefill = $patient ? PhiGate::demographicsPayload($patient) : null;
        }

        return ApiResponse::success([
            'clinic_name' => $clinic?->name,
            'expires_at' => $session->expires_at->toIso8601String(),
            'patient_id' => $session->patient_id,
            'prefill' => $prefill,
        ]);
    }

    public function submit(Request $request, string $token): JsonResponse
    {
        $session = $this->findOpenSession($token);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => UsValidation::dateOfBirth(),
            'gender' => ['nullable', 'string', 'max:40'],
            'phone' => UsValidation::phone(true),
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => UsValidation::state(),
            'zip' => UsValidation::zip(),
            'emergency_contact' => ['nullable', 'array'],
            'emergency_contact.name' => ['nullable', 'string', 'max:120'],
            'emergency_contact.phone' => UsValidation::phone(),
            'emergency_contact.relation' => ['nullable', 'string', 'max:60'],
            'insurance' => ['nullable', 'array'],
            'insurance.payer_name' => ['nullable', 'string', 'max:255'],
            'insurance.policy_number' => ['required_with:insurance.payer_name', 'nullable', 'string', 'max:255'],
            'insurance.group_number' => ['nullable', 'string', 'max:255'],
            // Explicitly reject clinical fields from tablet intake.
            'allergies' => ['prohibited'],
            'active_medications' => ['prohibited'],
            'conditions' => ['prohibited'],
        ]);

        $data['phone'] = UsValidation::normalizePhone($data['phone'] ?? null);
        $data['state'] = UsValidation::normalizeState($data['state'] ?? null);
        $data['zip'] = UsValidation::normalizeZip($data['zip'] ?? null);
        if (! empty($data['emergency_contact']['phone'])) {
            $data['emergency_contact']['phone'] = UsValidation::normalizePhone($data['emergency_contact']['phone']);
        }

        $addressParts = array_filter([
            $data['address'] ?? null,
            $data['city'] ?? null,
            isset($data['state'], $data['zip'])
                ? trim(($data['state'] ?? '').' '.($data['zip'] ?? ''))
                : ($data['state'] ?? $data['zip'] ?? null),
        ]);
        $address = $addressParts !== [] ? implode(', ', $addressParts) : ($data['address'] ?? null);

        $payload = collect($data)->except(['city', 'state', 'zip', 'insurance'])->all();
        $payload['address'] = $address;

        if ($session->patient_id) {
            $patient = Patient::query()->findOrFail($session->patient_id);
            $patient->update($payload);
        } else {
            $patient = Patient::create([
                ...$payload,
                'clinic_id' => $session->clinic_id,
                'mrn' => 'MRN-'.strtoupper(Str::random(8)),
            ]);
            $session->patient_id = $patient->id;
        }

        if (! empty($data['insurance']['payer_name'])) {
            PatientInsurance::updateOrCreate(
                [
                    'patient_id' => $patient->id,
                    'type' => 'primary',
                ],
                [
                    'clinic_id' => $session->clinic_id,
                    'payer_name' => $data['insurance']['payer_name'],
                    'policy_number' => $data['insurance']['policy_number'] ?? '',
                    'group_number' => $data['insurance']['group_number'] ?? null,
                ]
            );
        }

        $session->update([
            'status' => 'completed',
            'completed_at' => now(),
            'submitted_payload' => [
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'has_insurance' => ! empty($data['insurance']['payer_name']),
                // no clinical fields stored in session payload
            ],
            'patient_id' => $patient->id,
        ]);

        $this->audit->log(
            'intake.completed',
            'allowed',
            null,
            $request,
            $patient->id,
            PatientIntakeSession::class,
            $session->id,
            ['clinic_id' => $session->clinic_id]
        );

        return ApiResponse::success([
            'patient_id' => $patient->id,
            'mrn' => $patient->mrn,
            'message' => 'Thank you. Please return the tablet to the front desk.',
        ], 'Intake submitted');
    }

    private function findOpenSession(string $token): PatientIntakeSession
    {
        $session = PatientIntakeSession::query()->where('token', $token)->first();

        if (! $session || ! $session->isOpen()) {
            throw ValidationException::withMessages([
                'token' => ['This intake link is invalid or expired.'],
            ]);
        }

        return $session->load('clinic');
    }
}
