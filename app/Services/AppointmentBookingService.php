<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentBookingService
{
    /**
     * Create appointment with DB-level double-booking prevention for the provider.
     */
    public function book(User $actor, array $data): Appointment
    {
        $startsAt = Carbon::parse($data['starts_at']);
        $endsAt = Carbon::parse($data['ends_at']);

        return DB::transaction(function () use ($actor, $data, $startsAt, $endsAt) {
            $overlap = Appointment::query()
                ->where('clinic_id', $actor->clinic_id)
                ->where('provider_id', $data['provider_id'])
                ->whereNotIn('status', ['cancelled', 'no_show'])
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->lockForUpdate()
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages([
                    'starts_at' => ['This provider already has an appointment in that time slot.'],
                ]);
            }

            return Appointment::create([
                'clinic_id' => $actor->clinic_id,
                'patient_id' => $data['patient_id'],
                'provider_id' => $data['provider_id'],
                'visit_type' => $data['visit_type'] ?? null,
                'room' => $data['room'] ?? null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => $data['status'] ?? 'scheduled',
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    /**
     * Update / reschedule an existing appointment (same double-book guard).
     */
    public function reschedule(User $actor, Appointment $appointment, array $data): Appointment
    {
        abort_unless($appointment->clinic_id === $actor->clinic_id, 403);

        if (in_array($appointment->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Completed or cancelled appointments cannot be rescheduled. Rebook instead.'],
            ]);
        }

        $providerId = (int) ($data['provider_id'] ?? $appointment->provider_id);
        $startsAt = Carbon::parse($data['starts_at'] ?? $appointment->starts_at);
        $endsAt = Carbon::parse($data['ends_at'] ?? $appointment->ends_at);

        return DB::transaction(function () use ($actor, $appointment, $data, $providerId, $startsAt, $endsAt) {
            $overlap = Appointment::query()
                ->where('clinic_id', $actor->clinic_id)
                ->where('provider_id', $providerId)
                ->where('id', '!=', $appointment->id)
                ->whereNotIn('status', ['cancelled', 'no_show'])
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->lockForUpdate()
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages([
                    'starts_at' => ['This provider already has an appointment in that time slot.'],
                ]);
            }

            $appointment->update([
                'provider_id' => $providerId,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'visit_type' => $data['visit_type'] ?? $appointment->visit_type,
                'room' => array_key_exists('room', $data) ? $data['room'] : $appointment->room,
                'status' => $data['status'] ?? ($appointment->status === 'no_show' ? 'scheduled' : $appointment->status),
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $appointment->notes,
            ]);

            return $appointment->fresh();
        });
    }
}
