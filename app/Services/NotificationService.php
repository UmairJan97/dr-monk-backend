<?php

namespace App\Services;

use App\Events\ClinicNotificationCreated;
use App\Models\ClinicNotification;
use App\Models\User;
use App\Support\Roles;

class NotificationService
{
    public function __construct(private AuditService $audit) {}

    public function notifyUser(
        User $recipient,
        string $type,
        string $title,
        string $body,
        array $data = [],
    ): ClinicNotification {
        abort_unless($recipient->clinic_id, 422, 'Recipient has no clinic.');

        // Never put clinical free-text PHI into notification body.
        $safeBody = $this->scrubPhi($body);

        $notification = ClinicNotification::create([
            'clinic_id' => $recipient->clinic_id,
            'user_id' => $recipient->id,
            'type' => $type,
            'title' => $title,
            'body' => $safeBody,
            'data' => $data,
        ]);

        event(new ClinicNotificationCreated($notification));

        $this->audit->log(
            'notification.created',
            'allowed',
            $recipient,
            request(),
            $data['patient_id'] ?? null,
            ClinicNotification::class,
            $notification->id,
            ['type' => $type]
        );

        return $notification;
    }

    /**
     * Notify all clinic providers (Doctor + NP) — e.g. vitals ready.
     *
     * @return list<ClinicNotification>
     */
    public function notifyProviders(
        int $clinicId,
        string $type,
        string $title,
        string $body,
        array $data = [],
        ?int $preferUserId = null,
    ): array {
        $providers = User::query()
            ->where('clinic_id', $clinicId)
            ->where('is_active', true)
            ->role(Roles::clinicalProviders())
            ->get();

        if ($preferUserId) {
            $preferred = $providers->firstWhere('id', $preferUserId);
            if ($preferred) {
                $providers = collect([$preferred]);
            }
        }

        $created = [];
        foreach ($providers as $provider) {
            $created[] = $this->notifyUser($provider, $type, $title, $body, $data);
        }

        return $created;
    }

    /**
     * Notify Vital Nurses — e.g. Front Desk check-in.
     *
     * @return list<ClinicNotification>
     */
    public function notifyVitalNurses(
        int $clinicId,
        string $type,
        string $title,
        string $body,
        array $data = [],
    ): array {
        $nurses = User::query()
            ->where('clinic_id', $clinicId)
            ->where('is_active', true)
            ->role(Roles::VITAL_NURSE)
            ->get();

        $created = [];
        foreach ($nurses as $nurse) {
            $created[] = $this->notifyUser($nurse, $type, $title, $body, $data);
        }

        return $created;
    }

    private function scrubPhi(string $body): string
    {
        // Strip obvious PHI patterns from notification text.
        $body = preg_replace('/\b\d{3}-\d{2}-\d{4}\b/', '[redacted]', $body) ?? $body;
        $body = preg_replace('/\b\d{10,}\b/', '[redacted]', $body) ?? $body;

        return mb_substr($body, 0, 500);
    }
}
