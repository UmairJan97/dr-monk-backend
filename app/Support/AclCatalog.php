<?php

namespace App\Support;

/**
 * Clinic ACL catalog from Monk EMR SRD v1.2 §7 (Clinic Role Access Matrix)
 * plus Doctor / Clinic Admin panel modules (dashboard widgets, chart, plans, docs).
 *
 * Super Admin / saas.manage is intentionally omitted — clinic users cannot grant it.
 */
final class AclCatalog
{
    public const ACL_MANAGE = 'acl.manage';

    /** @return list<array{key: string, label: string, description: string, permissions: list<array{key: string, label: string}>}> */
    public static function modules(): array
    {
        return [
            [
                'key' => 'dashboard',
                'label' => 'Dashboard',
                'description' => 'Home widgets for this role’s panel.',
                'permissions' => [
                    ['key' => 'dashboard.view', 'label' => 'Open dashboard'],
                    ['key' => 'dashboard.schedule', 'label' => 'Today’s schedule'],
                    ['key' => 'dashboard.queue', 'label' => 'Patient queue'],
                    ['key' => 'dashboard.incomplete_notes', 'label' => 'Incomplete notes'],
                    ['key' => 'dashboard.follow_up_tasks', 'label' => 'Follow-up tasks'],
                    ['key' => 'dashboard.labs', 'label' => 'Labs widget'],
                    ['key' => 'dashboard.messages', 'label' => 'Messages widget'],
                    ['key' => 'dashboard.alerts', 'label' => 'Alerts'],
                    ['key' => 'dashboard.analytics', 'label' => 'Analytics'],
                    ['key' => 'dashboard.billing_kpis', 'label' => 'Billing KPIs'],
                ],
            ],
            [
                'key' => 'patients',
                'label' => 'Patients',
                'description' => 'Search, chart, and registration.',
                'permissions' => [
                    ['key' => 'patients.search', 'label' => 'Patient search'],
                    ['key' => 'patients.view_profile', 'label' => 'View profile'],
                    ['key' => 'patients.edit_profile', 'label' => 'Edit profile'],
                    ['key' => 'patients.register', 'label' => 'Register patient'],
                ],
            ],
            [
                'key' => 'appointments',
                'label' => 'Appointments',
                'description' => 'Scheduling, check-in, and queue.',
                'permissions' => [
                    ['key' => 'appointments.view', 'label' => 'View appointments'],
                    ['key' => 'appointments.create', 'label' => 'Create appointments'],
                    ['key' => 'appointments.edit', 'label' => 'Edit / reschedule'],
                    ['key' => 'appointments.check_in', 'label' => 'Check-in'],
                    ['key' => 'appointments.queue', 'label' => 'Front-desk / vitals queue'],
                ],
            ],
            [
                'key' => 'clinical_notes',
                'label' => 'Clinical notes',
                'description' => 'Progress and chart notes.',
                'permissions' => [
                    ['key' => 'clinical_notes.view', 'label' => 'View notes'],
                    ['key' => 'clinical_notes.write', 'label' => 'Write / sign notes'],
                ],
            ],
            [
                'key' => 'soap_notes',
                'label' => 'SOAP notes',
                'description' => 'Structured SOAP documentation.',
                'permissions' => [
                    ['key' => 'soap_notes.view', 'label' => 'View SOAP'],
                    ['key' => 'soap_notes.write', 'label' => 'Write SOAP'],
                ],
            ],
            [
                'key' => 'session_notes',
                'label' => 'Session notes',
                'description' => 'Counselor / therapist session documentation.',
                'permissions' => [
                    ['key' => 'session_notes.view', 'label' => 'View session notes'],
                    ['key' => 'session_notes.write', 'label' => 'Write session notes'],
                ],
            ],
            [
                'key' => 'diagnoses',
                'label' => 'Diagnoses',
                'description' => 'ICD-10 problems and visit diagnoses.',
                'permissions' => [
                    ['key' => 'diagnoses.view', 'label' => 'View diagnoses'],
                    ['key' => 'diagnoses.write', 'label' => 'Add / confirm diagnoses'],
                ],
            ],
            [
                'key' => 'prescriptions',
                'label' => 'Prescriptions',
                'description' => 'E-Rx. Write still requires prescribe eligibility (A9).',
                'permissions' => [
                    ['key' => 'prescriptions.view', 'label' => 'View prescriptions'],
                    ['key' => 'prescriptions.write', 'label' => 'Write prescriptions'],
                ],
            ],
            [
                'key' => 'lab_orders',
                'label' => 'Lab orders',
                'description' => 'Order and track labs.',
                'permissions' => [
                    ['key' => 'lab_orders.view', 'label' => 'View lab orders'],
                    ['key' => 'lab_orders.create', 'label' => 'Create lab orders'],
                ],
            ],
            [
                'key' => 'lab_results',
                'label' => 'Lab results',
                'description' => 'Results review and uploads.',
                'permissions' => [
                    ['key' => 'lab_results.view', 'label' => 'View results'],
                    ['key' => 'lab_results.upload', 'label' => 'Upload results'],
                ],
            ],
            [
                'key' => 'vitals',
                'label' => 'Vitals',
                'description' => 'Vital signs capture and review.',
                'permissions' => [
                    ['key' => 'vitals.view', 'label' => 'View vitals'],
                    ['key' => 'vitals.write', 'label' => 'Record vitals'],
                ],
            ],
            [
                'key' => 'treatment_plan',
                'label' => 'Treatment plan',
                'description' => 'Plans, counseling referral, and follow-up.',
                'permissions' => [
                    ['key' => 'treatment_plan.view', 'label' => 'View plans'],
                    ['key' => 'treatment_plan.write', 'label' => 'Write plans / follow-up'],
                ],
            ],
            [
                'key' => 'documents',
                'label' => 'Documents',
                'description' => 'Clinical documents and scans.',
                'permissions' => [
                    ['key' => 'documents.view', 'label' => 'View documents'],
                    ['key' => 'documents.upload', 'label' => 'Upload documents'],
                ],
            ],
            [
                'key' => 'billing',
                'label' => 'Billing',
                'description' => 'Codes, claims, and payments.',
                'permissions' => [
                    ['key' => 'billing.codes_view', 'label' => 'View billing codes'],
                    ['key' => 'billing.codes_suggest', 'label' => 'Suggest codes'],
                    ['key' => 'billing.codes_manage', 'label' => 'Manage / confirm codes'],
                    ['key' => 'billing.claims_view', 'label' => 'View claims'],
                    ['key' => 'billing.claims_manage', 'label' => 'Submit claims'],
                    ['key' => 'billing.payments_view', 'label' => 'View payments'],
                    ['key' => 'billing.payments_receive', 'label' => 'Receive payments'],
                ],
            ],
            [
                'key' => 'ai',
                'label' => 'AI',
                'description' => 'Hello Monk voice and chart summaries.',
                'permissions' => [
                    ['key' => 'ai.voice', 'label' => 'AI voice'],
                    ['key' => 'ai.summary_view', 'label' => 'View AI summary'],
                    ['key' => 'ai.summary_full', 'label' => 'Full AI summary tools'],
                ],
            ],
            [
                'key' => 'communication',
                'label' => 'Communication',
                'description' => 'Staff messenger and clinic alerts.',
                'permissions' => [
                    ['key' => 'communication.messages', 'label' => 'Staff messages'],
                    ['key' => 'communication.notifications', 'label' => 'Notifications'],
                ],
            ],
            [
                'key' => 'reports',
                'label' => 'Reports',
                'description' => 'Clinical and operational reports.',
                'permissions' => [
                    ['key' => 'reports.view', 'label' => 'View reports'],
                ],
            ],
            [
                'key' => 'admin',
                'label' => 'Clinic admin',
                'description' => 'Users, invites, oversight, audit, and settings.',
                'permissions' => [
                    ['key' => 'admin.users', 'label' => 'Manage clinic users'],
                    ['key' => 'admin.invites', 'label' => 'Invite staff'],
                    ['key' => 'admin.oversight', 'label' => 'Patient / appointment oversight'],
                    ['key' => 'admin.audit_view', 'label' => 'View audit log'],
                    ['key' => 'admin.settings', 'label' => 'Clinic settings'],
                ],
            ],
            [
                'key' => 'acl',
                'label' => 'ACL / Roles',
                'description' => 'Create and edit clinic roles and module permissions.',
                'permissions' => [
                    ['key' => self::ACL_MANAGE, 'label' => 'Manage clinic roles'],
                ],
            ],
        ];
    }

    /** @return list<string> */
    public static function permissionKeys(): array
    {
        $keys = [];
        foreach (self::modules() as $module) {
            foreach ($module['permissions'] as $permission) {
                $keys[] = $permission['key'];
            }
        }

        return $keys;
    }

    public static function isValidKey(string $key): bool
    {
        return in_array($key, self::permissionKeys(), true);
    }

    public static function roleLabel(string $slug): string
    {
        return match ($slug) {
            Roles::CLINIC_ADMIN => 'Clinic Admin',
            Roles::DOCTOR => 'Doctor',
            Roles::NP => 'Nurse Practitioner',
            Roles::VITAL_NURSE => 'Vital Nurse',
            Roles::FRONT_DESK => 'Front Desk',
            Roles::COUNSELOR => 'Counselor',
            Roles::THERAPIST => 'Therapist',
            Roles::BILLING => 'Billing',
            default => str_replace('_', ' ', ucfirst($slug)),
        };
    }

    public static function roleDescription(string $slug): string
    {
        return match ($slug) {
            Roles::CLINIC_ADMIN => 'Clinic operations, users, and role access (SRD Clinic Admin).',
            Roles::DOCTOR => 'Full clinical chart, SOAP, diagnoses, Rx, labs, and clinic role ACL.',
            Roles::NP => 'Same clinical chart as Doctor; Rx still gated by license / A9.',
            Roles::VITAL_NURSE => 'Vitals queue and limited patient profile.',
            Roles::FRONT_DESK => 'Registration, schedule, check-in, and payments.',
            Roles::COUNSELOR => 'Session notes and clinical read access.',
            Roles::THERAPIST => 'Same session workflow as Counselor.',
            Roles::BILLING => 'Codes, claims, payments, and billing dashboard.',
            default => 'Custom clinic role.',
        };
    }

    /** @return list<string> */
    public static function defaultsForRole(string $role): array
    {
        $keys = match ($role) {
            Roles::CLINIC_ADMIN => self::clinicAdminDefaults(),
            Roles::DOCTOR => self::doctorDefaults(),
            Roles::NP => self::npDefaults(),
            Roles::VITAL_NURSE => self::vitalNurseDefaults(),
            Roles::FRONT_DESK => self::frontDeskDefaults(),
            Roles::COUNSELOR, Roles::THERAPIST => self::counselorDefaults(),
            Roles::BILLING => self::billingDefaults(),
            default => [],
        };

        return array_values(array_unique(array_intersect($keys, self::permissionKeys())));
    }

    /** @return list<string> */
    private static function keysOf(string $moduleKey): array
    {
        foreach (self::modules() as $module) {
            if ($module['key'] === $moduleKey) {
                return array_column($module['permissions'], 'key');
            }
        }

        return [];
    }

    /** @return list<string> */
    private static function clinicAdminDefaults(): array
    {
        return [
            ...self::keysOf('dashboard'),
            'patients.search', 'patients.view_profile', 'patients.edit_profile', 'patients.register',
            ...self::keysOf('appointments'),
            'clinical_notes.view',
            'soap_notes.view',
            'session_notes.view',
            'diagnoses.view',
            'prescriptions.view',
            'lab_orders.view',
            'lab_results.view',
            'vitals.view',
            'treatment_plan.view',
            'documents.view',
            'billing.codes_view', 'billing.claims_view', 'billing.payments_view',
            'ai.voice', 'ai.summary_view',
            ...self::keysOf('communication'),
            'reports.view',
            ...self::keysOf('admin'),
            self::ACL_MANAGE,
        ];
    }

    /** @return list<string> */
    private static function doctorDefaults(): array
    {
        return [
            'dashboard.view', 'dashboard.schedule', 'dashboard.queue', 'dashboard.incomplete_notes',
            'dashboard.follow_up_tasks', 'dashboard.labs', 'dashboard.messages', 'dashboard.alerts',
            'dashboard.analytics',
            'patients.search', 'patients.view_profile', 'patients.edit_profile', 'patients.register',
            'appointments.view',
            ...self::keysOf('clinical_notes'),
            ...self::keysOf('soap_notes'),
            'session_notes.view',
            ...self::keysOf('diagnoses'),
            ...self::keysOf('prescriptions'),
            ...self::keysOf('lab_orders'),
            ...self::keysOf('lab_results'),
            'vitals.view',
            ...self::keysOf('treatment_plan'),
            ...self::keysOf('documents'),
            'billing.codes_view', 'billing.codes_suggest',
            ...self::keysOf('ai'),
            ...self::keysOf('communication'),
            'reports.view',
            self::ACL_MANAGE,
        ];
    }

    /** @return list<string> */
    private static function npDefaults(): array
    {
        // SRD: same clinical Full as Doctor; Rx Limited (still listed — API A9 gate remains).
        return array_values(array_filter(
            self::doctorDefaults(),
            fn (string $key) => $key !== self::ACL_MANAGE
        ));
    }

    /** @return list<string> */
    private static function vitalNurseDefaults(): array
    {
        return [
            'dashboard.view', 'dashboard.queue', 'dashboard.labs', 'dashboard.alerts', 'dashboard.messages',
            'patients.search', 'patients.view_profile',
            'appointments.queue', 'appointments.view',
            'lab_results.view', 'lab_results.upload',
            ...self::keysOf('vitals'),
            'documents.view', 'documents.upload',
            'ai.voice', 'ai.summary_view',
            ...self::keysOf('communication'),
        ];
    }

    /** @return list<string> */
    private static function frontDeskDefaults(): array
    {
        return [
            'dashboard.view', 'dashboard.schedule', 'dashboard.queue', 'dashboard.messages',
            'patients.search', 'patients.view_profile', 'patients.edit_profile', 'patients.register',
            ...self::keysOf('appointments'),
            'documents.view', 'documents.upload',
            'billing.payments_receive', 'billing.payments_view',
            'ai.voice',
            ...self::keysOf('communication'),
        ];
    }

    /** @return list<string> */
    private static function counselorDefaults(): array
    {
        return [
            'dashboard.view', 'dashboard.schedule', 'dashboard.follow_up_tasks', 'dashboard.messages',
            'dashboard.alerts',
            'patients.search', 'patients.view_profile',
            'appointments.view',
            'clinical_notes.view',
            ...self::keysOf('session_notes'),
            'diagnoses.view',
            'lab_orders.view',
            'lab_results.view',
            'vitals.view',
            ...self::keysOf('treatment_plan'),
            'documents.view',
            'ai.voice', 'ai.summary_view',
            ...self::keysOf('communication'),
        ];
    }

    /** @return list<string> */
    private static function billingDefaults(): array
    {
        return [
            'dashboard.view', 'dashboard.billing_kpis', 'dashboard.analytics',
            'patients.search', 'patients.view_profile',
            'appointments.view',
            'clinical_notes.view',
            'soap_notes.view',
            'diagnoses.view',
            'prescriptions.view',
            'lab_orders.view',
            'lab_results.view',
            'vitals.view',
            ...self::keysOf('billing'),
            'ai.voice', 'ai.summary_view',
            'admin.audit_view',
            'communication.notifications',
            'reports.view',
        ];
    }
}
