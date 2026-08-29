<?php

namespace App\Support;

use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Throwable;

final class PhiGate
{
    public static function demographicsPayload(Patient $patient): array
    {
        if (! $patient->relationLoaded('insurances')) {
            try {
                $patient->load('insurances');
            } catch (Throwable) {
                // Missing relation/table must not break the patient list.
            }
        }

        $primary = null;
        $secondary = null;
        try {
            $insurances = $patient->relationLoaded('insurances')
                ? $patient->insurances
                : collect();
            $primary = $insurances->firstWhere('type', 'primary');
            $secondary = $insurances->firstWhere('type', 'secondary');
        } catch (Throwable) {
            $primary = null;
            $secondary = null;
        }

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
            'insurance' => self::safeInsurancePayload($primary, 'primary'),
            'secondary_insurance' => self::safeInsurancePayload($secondary, 'secondary'),
            'created_at' => optional($patient->created_at)?->toIso8601String(),
        ];
    }

    /** Safe roster row for every role — never serialize raw encrypted models. */
    public static function listPayload(Patient $patient): array
    {
        try {
            return self::demographicsPayload($patient);
        } catch (Throwable) {
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
                'insurance' => null,
                'secondary_insurance' => null,
                'created_at' => optional($patient->created_at)?->toIso8601String(),
            ];
        }
    }

    /**
     * Encrypted insurance columns throw if APP_KEY changed after write.
     * Never let that 500 the whole patients index.
     */
    private static function safeInsurancePayload(?PatientInsurance $row, string $type): ?array
    {
        if (! $row) {
            return null;
        }

        try {
            return [
                'id' => $row->id,
                'type' => $type,
                'payer_name' => $row->payer_name,
                'policy_number' => $row->policy_number,
                'group_number' => $row->group_number,
                'expires_on' => $row->expires_on instanceof \DateTimeInterface
                    ? $row->expires_on->format('Y-m-d')
                    : $row->expires_on,
            ];
        } catch (DecryptException|Throwable) {
            return [
                'id' => $row->id,
                'type' => $type,
                'payer_name' => null,
                'policy_number' => null,
                'group_number' => null,
                'expires_on' => null,
            ];
        }
    }

    public static function scrubForUser(User $user, Patient $patient): array
    {
        if ($user->hasAnyRole(Roles::demographicsOnly())) {
            return self::demographicsPayload($patient);
        }

        try {
            $row = $patient->toArray();
            unset($row['insurances']);
            if (isset($row['date_of_birth'])) {
                $dob = $patient->date_of_birth;
                $row['date_of_birth'] = $dob instanceof \DateTimeInterface
                    ? $dob->format('Y-m-d')
                    : (is_string($dob) ? substr($dob, 0, 10) : $row['date_of_birth']);
            }
            $row['insurance'] = self::safeInsurancePayload(
                $patient->relationLoaded('insurances')
                    ? $patient->insurances->firstWhere('type', 'primary')
                    : null,
                'primary'
            );
            $row['secondary_insurance'] = self::safeInsurancePayload(
                $patient->relationLoaded('insurances')
                    ? $patient->insurances->firstWhere('type', 'secondary')
                    : null,
                'secondary'
            );

            return $row;
        } catch (Throwable) {
            return self::listPayload($patient);
        }
    }
}
