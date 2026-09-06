<?php

namespace App\Support;

final class Roles
{
    public const SUPER_ADMIN = 'super_admin';

    public const CLINIC_ADMIN = 'clinic_admin';

    public const DOCTOR = 'doctor';

    public const NP = 'nurse_practitioner';

    public const VITAL_NURSE = 'vital_nurse';

    public const FRONT_DESK = 'front_desk';

    public const COUNSELOR = 'counselor';

    public const THERAPIST = 'therapist';

    public const BILLING = 'billing';

    public static function all(): array
    {
        return [
            self::SUPER_ADMIN,
            self::CLINIC_ADMIN,
            self::DOCTOR,
            self::NP,
            self::VITAL_NURSE,
            self::FRONT_DESK,
            self::COUNSELOR,
            self::THERAPIST,
            self::BILLING,
        ];
    }

    /** Roles Clinic Admin may invite (never SaaS Super Admin). */
    public static function clinicAssignable(): array
    {
        return [
            self::CLINIC_ADMIN,
            self::DOCTOR,
            self::NP,
            self::VITAL_NURSE,
            self::FRONT_DESK,
            self::COUNSELOR,
            self::THERAPIST,
            self::BILLING,
        ];
    }

    public static function clinicalProviders(): array
    {
        return [self::DOCTOR, self::NP];
    }

    /** Roles selectable when booking / assigning a visit provider. */
    public static function schedulableProviders(): array
    {
        return [self::DOCTOR, self::NP, self::COUNSELOR, self::THERAPIST];
    }

    /** Roles that must never receive clinical PHI fields. */
    public static function demographicsOnly(): array
    {
        return [self::FRONT_DESK];
    }

    /** Roles that may write prescriptions (still gated by can_prescribe + state). */
    public static function canWritePrescriptions(): array
    {
        return [self::DOCTOR, self::NP];
    }
}
