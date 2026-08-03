<?php

namespace Database\Seeders;

use App\Models\Clinic;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SaasDemoSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug' => 'starter-monthly',
                'name' => 'Starter Monthly',
                'billing_period' => 'monthly',
                'price_cents' => 19900,
                'ai_credits_monthly' => 500,
                'storage_mb' => 5120,
            ],
            [
                'slug' => 'growth-yearly',
                'name' => 'Growth Yearly',
                'billing_period' => 'yearly',
                'price_cents' => 499000,
                'ai_credits_monthly' => 3000,
                'storage_mb' => 20480,
            ],
            [
                'slug' => 'trial-14',
                'name' => 'Trial 14-Day',
                'billing_period' => 'trial',
                'price_cents' => 0,
                'ai_credits_monthly' => 200,
                'storage_mb' => 2048,
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'billing_period' => 'enterprise',
                'price_cents' => 129900,
                'ai_credits_monthly' => 10000,
                'storage_mb' => 102400,
            ],
        ];

        $planModels = [];
        foreach ($plans as $row) {
            $planModels[$row['slug']] = SubscriptionPlan::query()->updateOrCreate(
                ['slug' => $row['slug']],
                [
                    ...$row,
                    'is_active' => true,
                    'features' => ['emr', 'ai', 'billing'],
                ]
            );
        }

        $growth = SubscriptionPlan::query()->where('slug', 'growth-monthly')->first()
            ?? $planModels['starter-monthly'];

        Clinic::query()
            ->where('slug', 'like', 'saas-demo-%')
            ->each(function (Clinic $clinic) {
                User::query()->where('clinic_id', $clinic->id)->where('email', 'like', '%@saas-demo.local')->delete();
                $clinic->forceDelete();
            });

        $tenants = [
            ['Harbor Pediatrics', 'harbor', 'active', 'starter-monthly', 800, 120],
            ['Summit Ortho', 'summit', 'active', 'growth-yearly', 2500, 890],
            ['Lakeside Family', 'lakeside', 'trial', 'trial-14', 180, 40],
            ['Metro Urgent Care', 'metro', 'suspended', 'starter-monthly', 100, 210],
            ['Pacific Behavioral', 'pacific', 'active', 'enterprise', 9000, 2400],
            ['Desert Dermatology', 'desert', 'expired', 'starter-monthly', 0, 55],
            ['Riverbend Cardiology', 'riverbend', 'active', 'growth-yearly', 3100, 1100],
            ['Oak Street Primary', 'oakstreet', 'trial', 'trial-14', 150, 22],
            ['Northwind ENT', 'northwind', 'active', 'starter-monthly', 600, 300],
            ['Cedar Hills Wellness', 'cedarhills', 'suspended', 'growth-yearly', 400, 150],
            ['Bayview Imaging', 'bayview', 'active', 'enterprise', 7500, 4200],
            ['Prairie Allergy', 'prairie', 'active', 'starter-monthly', 520, 95],
        ];

        foreach ($tenants as $i => $row) {
            [$name, $sub, $status, $planSlug, $credits, $storage] = $row;
            $plan = $planModels[$planSlug] ?? $growth;
            $slug = 'saas-demo-'.$sub;

            $clinic = Clinic::query()->create([
                'name' => $name,
                'slug' => $slug,
                'subdomain' => 'sd-'.$sub,
                'timezone' => 'America/New_York',
                'status' => $status,
                'subscription_plan_id' => $plan->id,
                'ai_credits_balance' => $credits,
                'storage_used_mb' => $storage,
                'trial_ends_at' => $status === 'trial' ? now()->addDays(10) : null,
                'stripe_customer_id' => in_array($status, ['active', 'suspended'], true)
                    ? 'cus_sandbox_demo_'.($i + 1)
                    : null,
                'stripe_subscription_id' => $status === 'active'
                    ? 'sub_sandbox_demo_'.($i + 1)
                    : null,
            ]);

            $admin = User::query()->create([
                'clinic_id' => $clinic->id,
                'name' => $name.' Admin',
                'email' => $sub.'@saas-demo.local',
                'password' => Hash::make('password'),
                'is_active' => $status !== 'suspended',
                'pin_hash' => Hash::make('1234'),
                'can_prescribe' => false,
            ]);
            $admin->assignRole(Roles::CLINIC_ADMIN);
        }

        // Ensure demo clinic has stripe sandbox IDs for SaaS list richness
        $demo = Clinic::query()->where('slug', 'demo-clinic')->first();
        if ($demo && ! $demo->stripe_customer_id) {
            $demo->update([
                'stripe_customer_id' => 'cus_sandbox_demo_main',
                'stripe_subscription_id' => 'sub_sandbox_demo_main',
            ]);
        }

        $this->command?->info('SaaS demo: 4 plans + 12 tenant clinics seeded.');
    }
}
