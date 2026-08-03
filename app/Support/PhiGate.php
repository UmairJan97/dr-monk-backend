<?php

namespace App\Support;

use App\Models\Patient;
use App\Models\User;

final class PhiGate
{
    public static function demographicsPayload(Patient $patient): array
    {
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
