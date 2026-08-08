<?php

namespace App\Support;

use App\Models\Patient;
use App\Models\User;

final class PhiGate
{
    public static function demographicsPayload(Patient $patient): array
    {
        if (! $patient->relationLoaded('insurances')) {
            $patient->load('insurances');
        }

        $primary = $patient->insurances->firstWhere('type', 'primary');
        $secondary = $patient->insurances->firstWhere('type', 'secondary');

        return [
            'id' => $patient->id,
            'mrn' => $patient->mrn,
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'date_of_birth' => $patient->date_of_birth instanceof \DateTimeInterface
                ? $patient->date_of_birth->format('Y-m-d')
                : $patient->date_of_birth,
            'gender' => $patient->gender,
            'phone' => $patient->phone,
            'email' => $patient->email,
            'address' => $patient->address,
            'photo_path' => $patient->photo_path,
            'primary_provider_id' => $patient->primary_provider_id,
            'emergency_contact' => $patient->emergency_contact,
            'insurance' => $primary ? [
                'id' => $primary->id,
                'type' => 'primary',
                'payer_name' => $primary->payer_name,
                'policy_number' => $primary->policy_number,
                'group_number' => $primary->group_number,
                'expires_on' => $primary->expires_on instanceof \DateTimeInterface
                    ? $primary->expires_on->format('Y-m-d')
                    : $primary->expires_on,
            ] : null,
            'secondary_insurance' => $secondary ? [
                'id' => $secondary->id,
                'type' => 'secondary',
                'payer_name' => $secondary->payer_name,
                'policy_number' => $secondary->policy_number,
                'group_number' => $secondary->group_number,
                'expires_on' => $secondary->expires_on instanceof \DateTimeInterface
                    ? $secondary->expires_on->format('Y-m-d')
                    : $secondary->expires_on,
            ] : null,
        ];
    }

    public static function scrubForUser(User $user, Patient $patient): array
    {
        if ($user->hasAnyRole(Roles::demographicsOnly())) {
            return self::demographicsPayload($patient);
        }

        return $patient->toArray();
    }
}
