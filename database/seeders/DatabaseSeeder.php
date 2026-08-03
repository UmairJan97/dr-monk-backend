<?php

namespace Database\Seeders;

use App\Models\Clinic;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (Permissions::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (Roles::all() as $roleName) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions(Permissions::matrix()[$roleName] ?? []);
        }

        $plan = SubscriptionPlan::query()->firstOrCreate(
            ['slug' => 'growth-monthly'],
            [
                'name' => 'Growth Monthly',
                'billing_period' => 'monthly',
                'price_cents' => 49900,
                'ai_credits_monthly' => 2000,
                'storage_mb' => 10240,
                'is_active' => true,
                'features' => ['emr', 'ai', 'billing'],
            ]
        );

        $clinic = Clinic::query()->firstOrCreate(
            ['slug' => 'demo-clinic'],
            [
                'name' => 'Demo Family Clinic',
                'subdomain' => 'demo',
                'timezone' => 'America/New_York',
                'status' => 'active',
                'subscription_plan_id' => $plan->id,
                'ai_credits_balance' => 2000,
            ]
        );

        $super = User::query()->firstOrCreate(
            ['email' => 'super@drmonk.local'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'is_active' => true,
                'pin_hash' => Hash::make('1234'),
            ]
        );
        $super->syncRoles([Roles::SUPER_ADMIN]);

        $users = [
            ['email' => 'admin@demo.local', 'name' => 'Clinic Admin', 'role' => Roles::CLINIC_ADMIN, 'can_prescribe' => false],
            ['email' => 'doctor@demo.local', 'name' => 'Dr. Monk', 'role' => Roles::DOCTOR, 'can_prescribe' => true],
            ['email' => 'np@demo.local', 'name' => 'NP Ada', 'role' => Roles::NP, 'can_prescribe' => true],
            ['email' => 'vitals@demo.local', 'name' => 'Vital Nurse', 'role' => Roles::VITAL_NURSE, 'can_prescribe' => false],
            ['email' => 'desk@demo.local', 'name' => 'Front Desk', 'role' => Roles::FRONT_DESK, 'can_prescribe' => false],
            ['email' => 'counselor@demo.local', 'name' => 'Counselor Sam', 'role' => Roles::COUNSELOR, 'can_prescribe' => false],
            ['email' => 'billing@demo.local', 'name' => 'Billing Lead', 'role' => Roles::BILLING, 'can_prescribe' => false],
        ];

        foreach ($users as $row) {
            $user = User::query()->updateOrCreate(
                ['email' => $row['email']],
                [
                    'clinic_id' => $clinic->id,
                    'name' => $row['name'],
                    'password' => Hash::make('password'),
                    'phone' => '5550100',
                    'is_active' => true,
                    'can_prescribe' => $row['can_prescribe'],
                    'pin_hash' => Hash::make('1234'),
                    'npi' => $row['can_prescribe'] ? '1234567890' : null,
                    'license_state' => $row['can_prescribe']
                        ? config('drmonk.default_license_state', 'NY')
                        : null,
                ]
            );
            $user->syncRoles([$row['role']]);
        }

        $this->call(FrontDeskDemoSeeder::class);
        $this->call(VitalNurseDemoSeeder::class);
        $this->call(DoctorDemoSeeder::class);
        $this->call(NpDemoSeeder::class);
        $this->call(CounselorDemoSeeder::class);
        $this->call(BillingDemoSeeder::class);
        $this->call(AdminDemoSeeder::class);
        $this->call(SaasDemoSeeder::class);
    }
}
