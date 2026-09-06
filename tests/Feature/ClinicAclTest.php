<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\ClinicAclRole;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\ClinicAclService;
use App\Support\AclCatalog;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClinicAclTest extends TestCase
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

    public function test_catalog_permission_keys_are_unique(): void
    {
        $keys = AclCatalog::permissionKeys();
        $this->assertNotEmpty($keys);
        $this->assertSame(count($keys), count(array_unique($keys)));
        $this->assertContains('acl.manage', $keys);
        $this->assertContains('dashboard.schedule', $keys);
        $this->assertContains(AclCatalog::ACL_MANAGE, AclCatalog::defaultsForRole(Roles::DOCTOR));
        $this->assertContains(AclCatalog::ACL_MANAGE, AclCatalog::defaultsForRole(Roles::CLINIC_ADMIN));
        $this->assertNotContains(AclCatalog::ACL_MANAGE, AclCatalog::defaultsForRole(Roles::BILLING));
    }

    public function test_admin_and_doctor_can_list_seeded_roles(): void
    {
        [$clinic, $admin, $doctor] = $this->world();

        Sanctum::actingAs($admin);
        $adminRes = $this->getJson('/api/v1/acl/roles')->assertOk();
        $adminRes->assertJsonPath('data.permission_count', count(AclCatalog::permissionKeys()));
        $this->assertCount(count(Roles::clinicAssignable()), $adminRes->json('data.roles'));
        $this->assertNotEmpty($adminRes->json('data.catalog.0.permissions'));

        $doctorRole = collect($adminRes->json('data.roles'))->firstWhere('slug', Roles::DOCTOR);
        $this->assertTrue($doctorRole['is_system']);
        $this->assertContains('dashboard.view', $doctorRole['permission_keys']);
        $this->assertContains('acl.manage', $doctorRole['permission_keys']);

        Sanctum::actingAs($doctor);
        $this->getJson('/api/v1/acl/roles')->assertOk()->assertJsonPath('success', true);

        $this->assertSame(
            count(Roles::clinicAssignable()),
            ClinicAclRole::query()->where('clinic_id', $clinic->id)->count()
        );
    }

    public function test_billing_cannot_access_acl(): void
    {
        [$clinic] = $this->world();
        $billing = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'pin_hash' => Hash::make('1234'),
        ]);
        $billing->assignRole(Roles::BILLING);
        Sanctum::actingAs($billing);

        $this->getJson('/api/v1/acl/roles')->assertForbidden();
        $this->postJson('/api/v1/acl/roles', [
            'name' => 'Night shift',
            'base_role' => Roles::DOCTOR,
            'permission_keys' => ['dashboard.view'],
        ])->assertForbidden();
    }

    public function test_doctor_can_create_and_edit_roles_module_ticks(): void
    {
        [, , $doctor] = $this->world();
        Sanctum::actingAs($doctor);

        $created = $this->postJson('/api/v1/acl/roles', [
            'name' => 'Float MD',
            'description' => 'Weekend coverage',
            'base_role' => Roles::DOCTOR,
            'permission_keys' => [
                'dashboard.view',
                'dashboard.schedule',
                'patients.search',
                'patients.view_profile',
                'clinical_notes.view',
                'clinical_notes.write',
            ],
        ])->assertCreated()->json('data');

        $this->assertFalse($created['is_system']);
        $this->assertSame(Roles::DOCTOR, $created['base_role']);
        $this->assertEqualsCanonicalizing([
            'dashboard.view',
            'dashboard.schedule',
            'patients.search',
            'patients.view_profile',
            'clinical_notes.view',
            'clinical_notes.write',
        ], $created['permission_keys']);

        $this->patchJson('/api/v1/acl/roles/'.$created['id'], [
            'name' => 'Float MD Plus',
            'permission_keys' => [
                'dashboard.view',
                'dashboard.schedule',
                'dashboard.queue',
                'patients.search',
                'patients.view_profile',
            ],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Float MD Plus')
            ->assertJsonPath('data.permission_count', 5);

        $systemDoctor = ClinicAclRole::query()->where('slug', Roles::DOCTOR)->first();
        $this->assertNotNull($systemDoctor);

        $this->patchJson('/api/v1/acl/roles/'.$systemDoctor->id, [
            'name' => 'Should stay Doctor',
            'permission_keys' => [
                'dashboard.view',
                'dashboard.schedule',
                'acl.manage',
            ],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Doctor')
            ->assertJsonPath('data.is_system', true);

        $systemDoctor->refresh()->load('permissionRows');
        $this->assertEqualsCanonicalizing(
            ['dashboard.view', 'dashboard.schedule', 'acl.manage'],
            $systemDoctor->permissionKeys()
        );
    }

    public function test_cannot_delete_system_role_or_unknown_permission(): void
    {
        [, $admin] = $this->world();
        Sanctum::actingAs($admin);

        $system = ClinicAclRole::query()->where('slug', Roles::FRONT_DESK)->first();
        $this->deleteJson('/api/v1/acl/roles/'.$system->id)->assertStatus(422);

        $this->postJson('/api/v1/acl/roles', [
            'name' => 'Bad perms',
            'base_role' => Roles::FRONT_DESK,
            'permission_keys' => ['dashboard.view', 'saas.manage'],
        ])->assertStatus(422);
    }

    public function test_cannot_strip_acl_manage_from_own_role(): void
    {
        [, $admin] = $this->world();
        Sanctum::actingAs($admin);
        $adminRole = ClinicAclRole::query()->where('slug', Roles::CLINIC_ADMIN)->first();

        $this->patchJson('/api/v1/acl/roles/'.$adminRole->id, [
            'permission_keys' => ['dashboard.view'],
        ])->assertStatus(422);
    }

    public function test_custom_role_can_be_deleted(): void
    {
        [, $admin] = $this->world();
        Sanctum::actingAs($admin);

        $id = $this->postJson('/api/v1/acl/roles', [
            'name' => 'Temp role',
            'base_role' => Roles::BILLING,
            'permission_keys' => ['dashboard.view', 'billing.codes_view'],
        ])->assertCreated()->json('data.id');

        $this->deleteJson('/api/v1/acl/roles/'.$id)->assertOk();
        $this->assertDatabaseMissing('clinic_acl_roles', ['id' => $id]);
    }

    public function test_admin_can_assign_custom_acl_role_to_user(): void
    {
        [$clinic, $admin] = $this->world();
        Sanctum::actingAs($admin);

        $staff = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'password' => Hash::make('password'),
        ]);
        $staff->assignRole(Roles::FRONT_DESK);

        $aclId = $this->postJson('/api/v1/acl/roles', [
            'name' => 'Lead desk',
            'base_role' => Roles::FRONT_DESK,
            'permission_keys' => ['dashboard.view', 'patients.search', 'appointments.view'],
        ])->assertCreated()->json('data.id');

        $this->patchJson('/api/v1/admin/users/'.$staff->id, [
            'acl_role_id' => $aclId,
        ])->assertOk()
            ->assertJsonPath('data.clinic_acl_role_id', $aclId)
            ->assertJsonPath('data.roles.0', Roles::FRONT_DESK);

        $this->assertDatabaseHas('users', [
            'id' => $staff->id,
            'clinic_acl_role_id' => $aclId,
        ]);
    }

    /**
     * @return array{0: Clinic, 1: User, 2: User}
     */
    private function world(): array
    {
        $plan = SubscriptionPlan::create([
            'name' => 'A',
            'slug' => 'acl-plan',
            'billing_period' => 'monthly',
            'price_cents' => 100,
            'ai_credits_monthly' => 100,
            'storage_mb' => 100,
            'is_active' => true,
        ]);
        $clinic = Clinic::create([
            'name' => 'ACL Clinic',
            'slug' => 'acl-clinic',
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

        $doctor = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'can_prescribe' => true,
            'password' => Hash::make('password'),
            'pin_hash' => Hash::make('1234'),
        ]);
        $doctor->assignRole(Roles::DOCTOR);

        app(ClinicAclService::class)->ensureForClinic((int) $clinic->id);

        return [$clinic, $admin, $doctor];
    }
}
