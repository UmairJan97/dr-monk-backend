<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmrSaasTest extends TestCase
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

    public function test_saas_dashboard_clinic_plan_credits_stripe_sandbox(): void
    {
        $super = $this->super();
        Sanctum::actingAs($super);

        $plan = $this->postJson('/api/v1/saas/plans', [
            'name' => 'Growth',
            'billing_period' => 'monthly',
            'price_cents' => 19900,
            'ai_credits_monthly' => 1000,
            'storage_mb' => 10240,
            'is_active' => true,
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/saas/plans', [
            'name' => 'Bad',
            'billing_period' => 'weekly',
            'price_cents' => 100,
            'ai_credits_monthly' => 10,
            'storage_mb' => 100,
        ])->assertStatus(422);

        $dash = $this->getJson('/api/v1/saas/dashboard')->assertOk();
        $this->assertArrayHasKey('trial_clinics', $dash->json('data'));
        $this->getJson('/api/v1/saas/plans')->assertOk();

        $clinic = $this->postJson('/api/v1/saas/clinics', [
            'name' => 'New Tenant',
            'subdomain' => 'newtenant',
            'subscription_plan_id' => $plan['id'],
            'admin_name' => 'Tenant Admin',
            'admin_email' => 'tenant.admin@demo.local',
            'admin_password' => 'password123',
        ])->assertCreated()->json('data.clinic');

        $this->assertDatabaseHas('users', [
            'email' => 'tenant.admin@demo.local',
            'clinic_id' => $clinic['id'],
        ]);

        $this->getJson('/api/v1/saas/clinics')->assertOk();
        $this->getJson('/api/v1/saas/clinics/'.$clinic['id'].'/usage')->assertOk();

        $this->patchJson('/api/v1/saas/clinics/'.$clinic['id'], [
            'name' => 'New Tenant Renamed',
            'status' => 'active',
            'subscription_plan_id' => $plan['id'],
        ])->assertOk()->assertJsonPath('data.name', 'New Tenant Renamed');

        $this->postJson('/api/v1/saas/clinics/'.$clinic['id'].'/credits', [
            'credits' => 0,
        ])->assertStatus(422);

        $this->postJson('/api/v1/saas/clinics/'.$clinic['id'].'/credits', [
            'credits' => 250,
        ])->assertOk();

        $this->postJson('/api/v1/saas/clinics/'.$clinic['id'].'/stripe-sandbox')
            ->assertOk()
            ->assertJsonPath('data.stripe_mode', 'sandbox');

        $this->patchJson('/api/v1/saas/clinics/'.$clinic['id'].'/status', [
            'status' => 'suspended',
        ])->assertOk()->assertJsonPath('data.status', 'suspended');
    }

    public function test_non_super_admin_cannot_access_saas_routes(): void
    {
        $admin = User::factory()->create([
            'clinic_id' => null,
            'is_active' => true,
            'pin_hash' => Hash::make('1234'),
        ]);
        $admin->assignRole(Roles::CLINIC_ADMIN);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/saas/dashboard')->assertForbidden();
        $this->postJson('/api/v1/saas/plans', [
            'name' => 'X',
            'billing_period' => 'monthly',
            'price_cents' => 100,
            'ai_credits_monthly' => 10,
            'storage_mb' => 100,
        ])->assertForbidden();
    }

    private function super(): User
    {
        $super = User::factory()->create([
            'clinic_id' => null,
            'is_active' => true,
            'password' => Hash::make('password'),
            'pin_hash' => Hash::make('1234'),
        ]);
        $super->assignRole(Roles::SUPER_ADMIN);

        return $super;
    }
}
