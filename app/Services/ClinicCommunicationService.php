<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicMessageLog;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class ClinicCommunicationService
{
    /** Templates contain NO clinical PHI — clinic name + date/time only. */
    public const TEMPLATES = [
        'appointment_reminder' => [
            'label' => 'Appointment reminder',
            'channels' => ['email', 'sms'],
            'email_subject' => 'Appointment reminder — {{clinic}}',
            'body' => 'This is a reminder of your appointment at {{clinic}} on {{date}} at {{time}}. If you need to reschedule, please call the clinic. Do not reply with personal health information.',
        ],
        'check_in_ready' => [
            'label' => 'Ready for check-in',
            'channels' => ['email', 'sms'],
            'email_subject' => 'You can check in — {{clinic}}',
            'body' => 'You may check in for your visit at {{clinic}}. Arrive a few minutes early. Do not reply with personal health information.',
        ],
        'general_notice' => [
            'label' => 'General notice (no PHI)',
            'channels' => ['email', 'sms'],
            'email_subject' => 'Message from {{clinic}}',
            'body' => 'You have a message from {{clinic}}. Please contact the front desk for details. Do not reply with personal health information.',
        ],
    ];

    public function __construct(private AuditService $audit) {}

    public function templates(): array
    {
        return collect(self::TEMPLATES)
            ->map(fn (array $t, string $key) => [
                'key' => $key,
                'label' => $t['label'],
                'channels' => $t['channels'],
                'preview' => $t['body'],
            ])
            ->values()
            ->all();
    }

    public function sendReminder(
        User $actor,
        string $templateKey,
        string $channel,
        Patient $patient,
        ?Appointment $appointment = null,
    ): ClinicMessageLog {
        if (! isset(self::TEMPLATES[$templateKey])) {
            throw ValidationException::withMessages([
                'template_key' => ['Unknown message template.'],
            ]);
        }

        if (! in_array($channel, self::TEMPLATES[$templateKey]['channels'], true)) {
            throw ValidationException::withMessages([
                'channel' => ['Channel not allowed for this template.'],
            ]);
        }

        $clinic = Clinic::query()->findOrFail($actor->clinic_id);
        $when = $appointment?->starts_at ?? now()->addDay()->setTime(9, 0);

        $vars = [
            'clinic' => $clinic->name,
            'date' => $when->timezone($clinic->timezone ?? 'America/New_York')->format('M j, Y'),
            'time' => $when->timezone($clinic->timezone ?? 'America/New_York')->format('g:i A'),
        ];

        $template = self::TEMPLATES[$templateKey];
        $body = $this->render($template['body'], $vars);
        $subject = $this->render($template['email_subject'] ?? 'Clinic message', $vars);

        // Hard PHI guard — reject if body somehow includes clinical markers.
        $this->assertNoPhi($body);

        $recipient = $channel === 'email' ? $patient->email : $patient->phone;
        if (! $recipient) {
            throw ValidationException::withMessages([
                'patient_id' => ['Patient has no '.$channel.' on file.'],
            ]);
        }

        $log = ClinicMessageLog::create([
            'clinic_id' => $clinic->id,
            'sent_by' => $actor->id,
            'patient_id' => $patient->id,
            'appointment_id' => $appointment?->id,
            'channel' => $channel,
            'template_key' => $templateKey,
            'recipient_hint' => $this->maskRecipient($channel, $recipient),
            'subject' => $channel === 'email' ? $subject : null,
            'body' => $body,
            'status' => 'queued',
        ]);

        try {
            if ($channel === 'email') {
                Mail::raw($body, function ($message) use ($recipient, $subject) {
                    $message->to($recipient)->subject($subject);
                });
                $log->update([
                    'status' => 'sent',
                    'provider_ref' => 'mail:'.config('mail.default'),
                ]);
            } else {
                // SMS sandbox — log only until Twilio/etc. BAA is in place.
                Log::info('sms.sandbox', [
                    'clinic_id' => $clinic->id,
                    'template' => $templateKey,
                    'hint' => $log->recipient_hint,
                    // never log raw phone or body with PHI
                ]);
                $log->update([
                    'status' => 'sent',
                    'provider_ref' => 'sms:sandbox',
                    'meta' => ['mode' => config('services.sms.mode', 'sandbox')],
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
            $log->update(['status' => 'failed']);
            throw ValidationException::withMessages([
                'channel' => ['Unable to send message. Please try again.'],
            ]);
        }

        $this->audit->log(
            'communication.send',
            'allowed',
            $actor,
            request(),
            $patient->id,
            ClinicMessageLog::class,
            $log->id,
            ['channel' => $channel, 'template' => $templateKey]
        );

        return $log->fresh();
    }

    private function render(string $template, array $vars): string
    {
        $out = $template;
        foreach ($vars as $key => $value) {
            $out = str_replace('{{'.$key.'}}', $value, $out);
        }

        return $out;
    }

    private function assertNoPhi(string $body): void
    {
        $blocked = ['allerg', 'diagnos', 'prescription', 'medication', 'lab result', 'ssn', 'dob'];
        $lower = strtolower($body);
        foreach ($blocked as $word) {
            if (str_contains($lower, $word)) {
                throw ValidationException::withMessages([
                    'body' => ['Message blocked: possible PHI content.'],
                ]);
            }
        }
    }

    private function maskRecipient(string $channel, string $value): string
    {
        if ($channel === 'email') {
            [$user, $domain] = array_pad(explode('@', $value, 2), 2, '');

            return substr($user, 0, 1).'***@'.$domain;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return '***-***-'.substr($digits, -4);
    }
}
