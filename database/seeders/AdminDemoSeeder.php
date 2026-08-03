<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Clinic;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminDemoSeeder extends Seeder
{
    public function run(): void
    {
        $clinic = Clinic::query()->where('slug', 'demo-clinic')->first();
        $admin = User::query()->where('email', 'admin@demo.local')->first();

        if (! $clinic || ! $admin) {
            $this->command?->warn('Demo clinic/admin missing.');

            return;
        }

        $clinic->update([
            'timezone' => $clinic->timezone ?: 'America/New_York',
            'working_hours' => [
                'mon' => ['09:00', '17:00'],
                'tue' => ['09:00', '17:00'],
                'wed' => ['09:00', '17:00'],
                'thu' => ['09:00', '17:00'],
                'fri' => ['09:00', '15:00'],
                'sat' => null,
                'sun' => null,
            ],
            'notification_templates' => [
                [
                    'key' => 'appointment_reminder',
                    'body' => 'Reminder: your appointment is coming up. Reply STOP to opt out. [demo-admin]',
                ],
                [
                    'key' => 'no_show_followup',
                    'body' => 'We missed you today. Call the clinic to reschedule. [demo-admin]',
                ],
            ],
            'hipaa_settings' => [
                'privacy_officer' => 'Alex Rivera',
                'security_officer' => 'Jordan Lee',
                'breach_contact_email' => 'privacy@demo.local',
                'last_risk_assessment_on' => now()->subMonths(2)->toDateString(),
            ],
        ]);

        UserInvitation::query()
            ->where('clinic_id', $clinic->id)
            ->where('email', 'like', '%@invite.demo.local')
            ->delete();

        AuditLog::query()
            ->where('clinic_id', $clinic->id)
            ->where('action', 'like', 'demo.admin.%')
            ->delete();

        $roles = [
            Roles::FRONT_DESK,
            Roles::VITAL_NURSE,
            Roles::DOCTOR,
            Roles::NP,
            Roles::COUNSELOR,
            Roles::BILLING,
            Roles::FRONT_DESK,
            Roles::DOCTOR,
            Roles::VITAL_NURSE,
            Roles::BILLING,
            Roles::COUNSELOR,
            Roles::FRONT_DESK,
        ];

        foreach ($roles as $i => $role) {
            $open = $i < 8;
            UserInvitation::query()->create([
                'clinic_id' => $clinic->id,
                'email' => sprintf('staff%02d@invite.demo.local', $i + 1),
                'role' => $role,
                'token' => hash('sha256', Str::random(64)),
                'invited_by' => $admin->id,
                'expires_at' => $open ? now()->addHours(36) : now()->subDay(),
                'accepted_at' => $i === 10 ? now()->subDays(3) : null,
            ]);
        }

        // Toggle target — inactive float staff for activate demo
        $inactive = User::query()->firstOrCreate(
            ['email' => 'inactive.staff@demo.local'],
            [
                'clinic_id' => $clinic->id,
                'name' => 'Inactive Staff',
                'password' => Hash::make('password'),
                'pin_hash' => Hash::make('1234'),
                'is_active' => false,
                'can_prescribe' => false,
            ]
        );
        $inactive->update(['clinic_id' => $clinic->id, 'is_active' => false]);
        $inactive->syncRoles([Roles::FRONT_DESK]);

        $actions = [
            'demo.admin.login',
            'demo.admin.invite',
            'demo.admin.user_update',
            'demo.admin.settings_update',
            'demo.admin.oversight_view',
            'demo.admin.audit_export',
            'demo.admin.role_assign',
            'demo.admin.hipaa_review',
            'demo.admin.template_edit',
            'demo.admin.deactivate_user',
            'demo.admin.reactivate_user',
            'demo.admin.invite_expire',
        ];

        foreach ($actions as $i => $action) {
            AuditLog::query()->create([
                'clinic_id' => $clinic->id,
                'user_id' => $admin->id,
                'role' => Roles::CLINIC_ADMIN,
                'action' => $action,
                'entity_type' => Clinic::class,
                'entity_id' => $clinic->id,
                'endpoint' => '/api/v1/admin/demo',
                'ip_address' => '127.0.0.1',
                'result' => 'allowed',
                'meta' => ['seed' => 'admin-demo', 'n' => $i + 1],
                'created_at' => now()->subMinutes(5 * ($i + 1)),
            ]);
        }

        $this->command?->info('Admin demo: HIPAA/templates, 12 invites, 12 audit rows, inactive staff.');
    }
}
