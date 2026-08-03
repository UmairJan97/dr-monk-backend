<?php

namespace App\Services\Ai;

use App\Models\AiUsageLog;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class HelloMonkService
{
    /**
     * Transcribe in-memory audio (never persisted) then route intent.
     * PHI is only sent externally when AI_EXTERNAL_ENABLED=true and BAAs are in place.
     */
    public function handleCommand(User $user, string $transcript, ?int $patientId = null): array
    {
        $intent = $this->resolveIntent($transcript, $user);
        $this->debitCredit($user, 'voice', $patientId, ['intent' => $intent['name']]);

        return [
            'intent' => $intent['name'],
            'transcript' => $transcript,
            'response_text' => $intent['message'],
            'actions' => $intent['actions'],
        ];
    }

    public function transcribe(string $audioBinary): string
    {
        if (! config('drmonk.ai.external_enabled') && ! config('services.ai.external_enabled')) {
            return 'open next patient';
        }

        $response = Http::withToken(config('drmonk.ai.openai_key') ?: config('services.openai.key'))
            ->attach('file', $audioBinary, 'audio.webm')
            ->post('https://api.openai.com/v1/audio/transcriptions', [
                'model' => config('drmonk.ai.openai_model', 'whisper-1'),
            ]);

        return (string) data_get($response->json(), 'text', '');
    }

    private function resolveIntent(string $transcript, User $user): array
    {
        $t = Str::lower($transcript);

        if (Str::contains($t, 'next patient')) {
            return [
                'name' => 'open_next_patient',
                'message' => 'Opening the next patient in your queue.',
                'actions' => [['type' => 'navigate', 'target' => 'next_patient']],
            ];
        }

        if (Str::contains($t, 'soap')) {
            return [
                'name' => 'create_soap_note',
                'message' => 'Ready to create a SOAP note via dictation.',
                'actions' => [['type' => 'open_form', 'target' => 'soap_note']],
            ];
        }

        if ($user->hasAnyRole([Roles::DOCTOR, Roles::NP]) && Str::contains($t, ['lab', 'labs'])) {
            return [
                'name' => 'show_labs',
                'message' => 'Showing the latest lab orders for this patient.',
                'actions' => [['type' => 'navigate', 'target' => 'labs']],
            ];
        }

        if ($user->hasAnyRole([Roles::DOCTOR, Roles::NP, Roles::VITAL_NURSE]) && Str::contains($t, 'vital')) {
            return [
                'name' => 'show_vitals',
                'message' => 'Showing the latest vital signs.',
                'actions' => [['type' => 'navigate', 'target' => 'vitals']],
            ];
        }

        if ($user->hasAnyRole([Roles::DOCTOR, Roles::NP]) && Str::contains($t, ['diagnos', 'icd'])) {
            return [
                'name' => 'add_diagnosis',
                'message' => 'Opening diagnosis entry with ICD-10 assist.',
                'actions' => [['type' => 'open_form', 'target' => 'diagnosis']],
            ];
        }

        if ($user->hasRole(Roles::NP) && Str::contains($t, ['prescrib', 'refill', 'medication'])) {
            return [
                'name' => 'np_prescribe',
                'message' => $user->canPrescribe()
                    ? 'Opening e-prescribe (state-licensed).'
                    : 'Prescribing is blocked for your license state or permission.',
                'actions' => [['type' => 'open_form', 'target' => 'prescription']],
            ];
        }

        if ($user->hasRole(Roles::COUNSELOR) && Str::contains($t, ['session', 'therapy', 'counsel'])) {
            return [
                'name' => 'start_counseling_session',
                'message' => 'Opening counseling session notes.',
                'actions' => [['type' => 'open_form', 'target' => 'counseling_session']],
            ];
        }

        if ($user->hasRole(Roles::COUNSELOR) && Str::contains($t, ['phq', 'gad', 'pcl', 'assessment'])) {
            return [
                'name' => 'run_assessment',
                'message' => 'Opening PHQ-9 / GAD-7 / PCL-5 assessment.',
                'actions' => [['type' => 'open_form', 'target' => 'assessment']],
            ];
        }

        if ($user->hasRole(Roles::FRONT_DESK) && Str::contains($t, 'reminder')) {
            return [
                'name' => 'send_appointment_reminder',
                'message' => 'Queued appointment reminder (no PHI in email payload).',
                'actions' => [['type' => 'job', 'target' => 'appointment_reminder']],
            ];
        }

        return [
            'name' => 'unknown',
            'message' => 'I did not understand that command.',
            'actions' => [],
        ];
    }

    private function debitCredit(User $user, string $feature, ?int $patientId, array $meta): void
    {
        if ($user->clinic_id) {
            $user->clinic()->decrement('ai_credits_balance');
            AiUsageLog::create([
                'clinic_id' => $user->clinic_id,
                'user_id' => $user->id,
                'feature' => $feature,
                'credits_used' => 1,
                'patient_id' => $patientId,
                'meta' => $meta,
            ]);
        }
    }
}
