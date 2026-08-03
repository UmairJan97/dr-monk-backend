<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmrAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (Permissions::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        foreach (Roles::all() as $roleName) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions(Permissions::matrix()[$roleName] ?? []);
        }
    }

    public function test_admin_dashboard_users_settings_and_no_rx(): void
    {
        [$clinic, $admin] = $this->world();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/dashboard')->assertOk();
        $this->getJson('/api/v1/admin/users')->assertOk();
        $this->getJson('/api/v1/admin/roles')->assertOk();
        $this->getJson('/api/v1/admin/oversight')->assertOk();
        $this->getJson('/api/v1/admin/settings')->assertOk();
        $this->getJson('/api/v1/admin/audit-logs')->assertOk();
        $this->getJson('/api/v1/admin/operational-suggestions')->assertOk();
        $this->getJson('/api/v1/admin/invitations')->assertOk();

        $this->postJson('/api/v1/admin/invitations', [
            'email' => 'newdesk@demo.local',
            'role' => Roles::FRONT_DESK,
        ])->assertCreated();

        $this->postJson('/api/v1/admin/invitations', [
            'email' => 'bad',
            'role' => Roles::FRONT_DESK,
        ])->assertStatus(422);

        $staff = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'password' => Hash::make('password'),
        ]);
        $staff->assignRole(Roles::FRONT_DESK);

        $this->patchJson('/api/v1/admin/users/'.$staff->id, [
            'is_active' => false,
            'role' => Roles::BILLING,
        ])->assertOk()->assertJsonPath('data.is_active', false);

        $this->patchJson('/api/v1/admin/settings', [
            'password' => 'wrong-password',
            'timezone' => 'America/Chicago',
        ])->assertStatus(422);

        $this->patchJson('/api/v1/admin/settings', [
            'password' => 'password',
            'timezone' => 'America/Chicago',
            'hipaa_settings' => [
                'privacy_officer' => 'Jane Doe',
                'security_officer' => 'John Doe',
                'breach_contact_email' => 'privacy@clinic.test',
            ],
            'notification_templates' => [
                ['key' => 'appointment_reminder', 'body' => 'Reminder only — no PHI'],
            ],
        ])->assertOk()->assertJsonPath('data.timezone', 'America/Chicago');

        // A9 — admin cannot write Rx (role middleware blocks clinical routes)
        $rx = $this->postJson('/api/v1/clinical/patients/1/prescriptions', [
            'medication_name' => 'X',
        ]);
        $this->assertContains($rx->status(), [403, 404]);
    }

    public function test_non_admin_cannot_access_admin_routes(): void
    {
        [$clinic] = $this->world();
        $billing = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'pin_hash' => Hash::make('1234'),
        ]);
        $billing->assignRole(Roles::BILLING);
        Sanctum::actingAs($billing);

        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
        $this->postJson('/api/v1/admin/invitations', [
            'email' => 'x@demo.local',
            'role' => Roles::FRONT_DESK,
        ])->assertForbidden();
    }

    /**
     * @return array{0: Clinic, 1: User}
     */
    private function world(): array
    {
        $plan = SubscriptionPlan::create([
            'name' => 'A',
            'slug' => 'a-plan',
            'billing_period' => 'monthly',
            'price_cents' => 100,
            'ai_credits_monthly' => 100,
            'storage_mb' => 100,
            'is_active' => true,
        ]);
        $clinic = Clinic::create([
            'name' => 'Admin Clinic',
            'slug' => 'admin-clinic',
            'status' => 'active',
            'subscription_plan_id' => $plan->id,
            'timezone' => 'America/New_York',
            'ai_credits_balance' => 10,
        ]);
        $admin = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'can_prescribe' => false,
            'password' => Hash::make('password'),
            'pin_hash' => Hash::make('1234'),
        ]);
        $admin->assignRole(Roles::CLINIC_ADMIN);

        return [$clinic, $admin];
    }
}
