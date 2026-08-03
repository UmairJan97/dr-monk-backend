<?php

namespace App\Support;

final class Permissions
{
    public const PATIENTS_VIEW = 'patients.view';

    public const PATIENTS_CREATE = 'patients.create';

    public const PATIENTS_UPDATE = 'patients.update';

    public const APPOINTMENTS_MANAGE = 'appointments.manage';

    public const VITALS_WRITE = 'vitals.write';

    public const CLINICAL_READ = 'clinical.read';

    public const CLINICAL_WRITE = 'clinical.write';

    public const PRESCRIPTIONS_WRITE = 'prescriptions.write';

    public const COUNSELOR_WRITE = 'counselor.write';

    public const BILLING_MANAGE = 'billing.manage';

    public const ADMIN_MANAGE = 'admin.manage';

    public const AUDIT_READ = 'audit.read';

    public const SAAS_MANAGE = 'saas.manage';

    public static function all(): array
    {
        return [
            self::PATIENTS_VIEW,
            self::PATIENTS_CREATE,
            self::PATIENTS_UPDATE,
            self::APPOINTMENTS_MANAGE,
            self::VITALS_WRITE,
            self::CLINICAL_READ,
            self::CLINICAL_WRITE,
            self::PRESCRIPTIONS_WRITE,
            self::COUNSELOR_WRITE,
            self::BILLING_MANAGE,
            self::ADMIN_MANAGE,
            self::AUDIT_READ,
            self::SAAS_MANAGE,
        ];
    }

    /** @return array<string, list<string>> */
    public static function matrix(): array
    {
        return [
            Roles::SUPER_ADMIN => [
                self::SAAS_MANAGE,
                self::AUDIT_READ,
            ],
            Roles::CLINIC_ADMIN => [
                self::PATIENTS_VIEW,
                self::PATIENTS_CREATE,
                self::PATIENTS_UPDATE,
                self::APPOINTMENTS_MANAGE,
                self::CLINICAL_READ,
                self::BILLING_MANAGE,
                self::ADMIN_MANAGE,
                self::AUDIT_READ,
            ],
            Roles::DOCTOR => [
                self::PATIENTS_VIEW,
                self::CLINICAL_READ,
                self::CLINICAL_WRITE,
                self::PRESCRIPTIONS_WRITE,
            ],
            Roles::NP => [
                self::PATIENTS_VIEW,
                self::CLINICAL_READ,
                self::CLINICAL_WRITE,
                self::PRESCRIPTIONS_WRITE,
            ],
            Roles::VITAL_NURSE => [
                self::PATIENTS_VIEW,
                self::VITALS_WRITE,
            ],
            Roles::FRONT_DESK => [
                self::PATIENTS_VIEW,
                self::PATIENTS_CREATE,
                self::PATIENTS_UPDATE,
                self::APPOINTMENTS_MANAGE,
            ],
            Roles::COUNSELOR => [
                self::PATIENTS_VIEW,
                self::CLINICAL_READ,
                self::COUNSELOR_WRITE,
            ],
            Roles::BILLING => [
                self::PATIENTS_VIEW,
                self::CLINICAL_READ,
                self::BILLING_MANAGE,
                self::AUDIT_READ,
            ],
        ];
    }
}
