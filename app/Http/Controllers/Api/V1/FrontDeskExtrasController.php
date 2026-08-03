<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ClinicMessageLog;
use App\Models\Patient;
use App\Models\PatientIntakeSession;
use App\Services\ClinicCommunicationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FrontDeskExtrasController extends Controller
{
    public function __construct(private ClinicCommunicationService $comms) {}

    public function messageTemplates(): JsonResponse
    {
        return ApiResponse::success(['items' => $this->comms->templates()]);
    }

    public function messageHistory(Request $request): JsonResponse
    {
        $items = ClinicMessageLog::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (ClinicMessageLog $log) => [
                'id' => $log->id,
                'channel' => $log->channel,
                'template_key' => $log->template_key,
                'recipient_hint' => $log->recipient_hint,
                'subject' => $log->subject,
                'body' => $log->body,
                'status' => $log->status,
                'patient_id' => $log->patient_id,
                'appointment_id' => $log->appointment_id,
                'created_at' => optional($log->created_at)?->toIso8601String(),
            ]);

        return ApiResponse::success(['items' => $items]);
    }

    public function sendMessage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_key' => ['required', 'string'],
            'channel' => ['required', 'in:email,sms'],
            'patient_id' => [
                'required',
                Rule::exists('patients', 'id')->where('clinic_id', $request->user()->clinic_id),
            ],
            'appointment_id' => [
                'nullable',
                Rule::exists('appointments', 'id')->where('clinic_id', $request->user()->clinic_id),
            ],
        ]);

        $patient = Patient::query()->findOrFail($data['patient_id']);
        $appointment = isset($data['appointment_id'])
            ? Appointment::query()->findOrFail($data['appointment_id'])
            : null;

        if ($appointment && (int) $appointment->patient_id !== (int) $patient->id) {
            throw ValidationException::withMessages([
                'appointment_id' => ['Appointment does not belong to this patient.'],
            ]);
        }

        $log = $this->comms->sendReminder(
            $request->user(),
            $data['template_key'],
            $data['channel'],
            $patient,
            $appointment
        );

        return ApiResponse::created([
            'id' => $log->id,
            'channel' => $log->channel,
            'status' => $log->status,
            'recipient_hint' => $log->recipient_hint,
            'body' => $log->body,
        ], 'Message sent (no PHI in payload)');
    }

    public function createIntakeSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => [
                'nullable',
                Rule::exists('patients', 'id')->where('clinic_id', $request->user()->clinic_id),
            ],
            'minutes' => ['nullable', 'integer', 'min:15', 'max:240'],
        ]);

        $token = PatientIntakeSession::issueToken();
        $session = PatientIntakeSession::create([
            'clinic_id' => $request->user()->clinic_id,
            'created_by' => $request->user()->id,
            'patient_id' => $data['patient_id'] ?? null,
            'token' => $token,
            'status' => 'open',
            'expires_at' => now()->addMinutes($data['minutes'] ?? 60),
        ]);

        $frontend = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000')), '/');

        return ApiResponse::created([
            'session_id' => $session->id,
            'token' => $token,
            'expires_at' => $session->expires_at->toIso8601String(),
            'intake_url' => $frontend.'/intake/'.$token,
            'patient_id' => $session->patient_id,
        ], 'Tablet intake session created');
    }

    public function intakeSessions(Request $request): JsonResponse
    {
        $items = PatientIntakeSession::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (PatientIntakeSession $s) => [
                'id' => $s->id,
                'patient_id' => $s->patient_id,
                'status' => $s->isOpen() ? $s->status : ($s->status === 'open' ? 'expired' : $s->status),
                'expires_at' => $s->expires_at->toIso8601String(),
                'completed_at' => optional($s->completed_at)?->toIso8601String(),
            ]);

        return ApiResponse::success(['items' => $items]);
    }

    /** Log a phone note (no clinical PHI — desk ops only). */
    public function phoneNote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => [
                'required',
                Rule::exists('patients', 'id')->where('clinic_id', $request->user()->clinic_id),
            ],
            'note' => ['required', 'string', 'max:500'],
        ]);

        $patient = Patient::query()->findOrFail($data['patient_id']);
        // Strip obvious clinical-looking content lightly
        $note = preg_replace('/\b(diagnosis|prescription|allergy|bp\s*\d)/i', '[redacted]', $data['note']) ?? $data['note'];

        $log = ClinicMessageLog::query()->create([
            'clinic_id' => $request->user()->clinic_id,
            'patient_id' => $patient->id,
            'sent_by' => $request->user()->id,
            'channel' => 'phone',
            'template_key' => 'phone_note',
            'recipient_hint' => $patient->phone ? '***'.substr(preg_replace('/\D/', '', (string) $patient->phone), -4) : 'phone',
            'subject' => 'Phone note',
            'body' => mb_substr($note, 0, 500),
            'status' => 'logged',
        ]);

        return ApiResponse::created([
            'id' => $log->id,
            'channel' => 'phone',
            'status' => 'logged',
            'body' => $log->body,
        ], 'Phone note logged');
    }

    /**
     * Mass reminder flow — sandbox. Uses same no-PHI templates.
     * Live blast waits on approved SMS/email vendor (OCI/HIPAA).
     */
    public function massMessage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_key' => ['required', 'string'],
            'channel' => ['required', 'in:email,sms'],
            'patient_ids' => ['required', 'array', 'min:1', 'max:50'],
            'patient_ids.*' => [
                'integer',
                Rule::exists('patients', 'id')->where('clinic_id', $request->user()->clinic_id),
            ],
        ]);

        $sent = 0;
        $failed = 0;
        $results = [];
        foreach ($data['patient_ids'] as $pid) {
            try {
                $patient = Patient::query()->findOrFail($pid);
                $log = $this->comms->sendReminder(
                    $request->user(),
                    $data['template_key'],
                    $data['channel'],
                    $patient,
                    null
                );
                $sent++;
                $results[] = ['patient_id' => $pid, 'status' => $log->status];
            } catch (\Throwable) {
                $failed++;
                $results[] = ['patient_id' => $pid, 'status' => 'failed'];
            }
        }

        return ApiResponse::success([
            'sent' => $sent,
            'failed' => $failed,
            'results' => $results,
            'note' => 'Sent via clinic mail/SMS sandbox (no PHI). Live Twilio only after OCI/HIPAA approval.',
        ], 'Mass reminders sent');
    }

    /**
     * Real on-file insurance check (expires_on + eligibility_snapshot).
     * Full X12 270/271 clearinghouse remains Billing go-live.
     */
    public function insuranceVerify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => [
                'required',
                Rule::exists('patients', 'id')->where('clinic_id', $request->user()->clinic_id),
            ],
        ]);

        $patient = Patient::query()->with('insurances')->findOrFail($data['patient_id']);
        $coverages = $patient->insurances->map(function ($ins) {
            $expires = $ins->expires_on;
            $active = $expires === null || $expires->isFuture() || $expires->isToday();

            return [
                'id' => $ins->id,
                'type' => $ins->type,
                'payer' => $ins->payer_name,
                'policy_last4' => $ins->policy_number
                    ? substr((string) $ins->policy_number, -4)
                    : null,
                'group_number' => $ins->group_number,
                'expires_on' => optional($expires)?->format('Y-m-d'),
                'status' => $active ? 'active_on_file' : 'expired_on_file',
                'has_card_front' => (bool) $ins->card_front_path,
                'has_card_back' => (bool) $ins->card_back_path,
            ];
        })->values();

        if ($coverages->isEmpty()) {
            return ApiResponse::success([
                'verified_at' => now()->toIso8601String(),
                'overall_status' => 'no_insurance_on_file',
                'patient' => [
                    'id' => $patient->id,
                    'mrn' => $patient->mrn,
                    'name' => trim($patient->first_name.' '.$patient->last_name),
                ],
                'coverages' => [],
                'message' => 'No insurance on file. Collect payer/policy in Registration, then verify again.',
            ], 'Insurance check complete');
        }

        $overall = $coverages->contains(fn ($c) => $c['status'] === 'active_on_file')
            ? 'verified_active'
            : 'verified_expired';

        foreach ($patient->insurances as $ins) {
            $ins->update([
                'eligibility_snapshot' => [
                    'checked_at' => now()->toIso8601String(),
                    'checked_by' => $request->user()->id,
                    'source' => 'front_desk_on_file',
                    'overall_status' => $overall,
                    'note' => 'On-file verification (not clearinghouse 270/271).',
                ],
            ]);
        }

        return ApiResponse::success([
            'verified_at' => now()->toIso8601String(),
            'overall_status' => $overall,
            'patient' => [
                'id' => $patient->id,
                'mrn' => $patient->mrn,
                'name' => trim($patient->first_name.' '.$patient->last_name),
            ],
            'coverages' => $coverages,
            'message' => $overall === 'verified_active'
                ? 'Insurance on file looks active. Clearinghouse eligibility (copay/deductible) is completed in Billing when go-live.'
                : 'Insurance on file is expired or past expires_on. Update registration / cards.',
        ], 'Insurance check complete');
    }

    /**
     * Hello Monk — typed intent mapper only (STT/TTS key missing; leave disabled).
     */
    public function voiceCommand(Request $request): JsonResponse
    {
        $data = $request->validate([
            'transcript' => ['required', 'string', 'max:500'],
        ]);

        $t = strtolower(trim($data['transcript']));
        $intent = 'unknown';
        $action = null;
        $hint = 'Try: find patient, show unpaid invoices, verify insurance, send appointment reminder.';

        if (str_contains($t, 'find patient') || preg_match('/find\s+patient/', $t)) {
            $intent = 'find_patient';
            $action = ['navigate' => '/panels/patients', 'open_search' => true];
            $hint = 'Opening patient search.';
        } elseif (str_contains($t, 'unpaid') || str_contains($t, 'invoice')) {
            $intent = 'show_unpaid';
            $action = ['navigate' => '/panels/front-desk#ledger'];
            $hint = 'Open payment history / ledger.';
        } elseif (str_contains($t, 'verify') && str_contains($t, 'insurance')) {
            $intent = 'verify_insurance';
            $action = ['navigate' => '/panels/front-desk#insurance-verify'];
            $hint = 'Open insurance verification.';
        } elseif (str_contains($t, 'reminder') || str_contains($t, 'appointment')) {
            $intent = 'send_reminder';
            $action = ['navigate' => '/panels/front-desk#communication'];
            $hint = 'Open communication center.';
        }

        return ApiResponse::success([
            'mode' => 'no_stt_key',
            'transcript' => $data['transcript'],
            'intent' => $intent,
            'action' => $action,
            'spoken_reply' => $hint,
            'note' => 'STT/TTS disabled until AI keys + BAA. Typed command still navigates.',
        ], 'Voice command interpreted');
    }
}
