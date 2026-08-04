<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientInsurance;
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

class EmrBillingTest extends TestCase
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

    public function test_billing_dashboard_claim_eligibility_expense_flow(): void
    {
        [, $billing, $patient] = $this->world();
        Sanctum::actingAs($billing);

        PatientInsurance::create([
            'clinic_id' => $patient->clinic_id,
            'patient_id' => $patient->id,
            'type' => 'primary',
            'payer_name' => 'Aetna',
            'policy_number' => 'POL-123',
            'group_number' => 'G1',
        ]);

        $this->getJson('/api/v1/billing/dashboard')->assertOk();
        $this->getJson('/api/v1/billing/ledger')->assertOk();
        $this->getJson('/api/v1/billing/insurances')->assertOk();
        $this->getJson('/api/v1/billing/payments')->assertOk();

        $this->postJson('/api/v1/billing/codes/suggest', [
            'patient_id' => $patient->id,
            'text' => 'office visit cough',
        ])->assertOk();

        $pending = $this->getJson('/api/v1/billing/codes/pending')->assertOk();
        $items = $pending->json('data.items');
        $this->assertNotEmpty($items);
        $this->postJson('/api/v1/billing/codes/'.$items[0]['id'].'/confirm', [
            'status' => 'confirmed',
        ])->assertOk();

        if (count($items) > 1) {
            $this->postJson('/api/v1/billing/codes/'.$items[1]['id'].'/confirm', [
                'status' => 'dismissed',
            ])->assertOk()->assertJsonPath('data.status', 'dismissed');
        }

        $this->postJson('/api/v1/billing/claims', [
            'patient_id' => $patient->id,
            'billed_amount' => 0,
            'submit' => false,
        ])->assertStatus(422);

        $this->postJson('/api/v1/billing/claims', [
            'patient_id' => $patient->id,
            'billed_amount' => 125.50,
            'submit' => false,
        ])->assertCreated()->assertJsonPath('data.status', 'draft');

        $this->postJson('/api/v1/billing/claims', [
            'patient_id' => $patient->id,
            'billed_amount' => 125.50,
            'submit' => true,
        ])->assertCreated()->assertJsonPath('data.status', 'submitted');

        $this->postJson('/api/v1/billing/eligibility', [
            'payer_name' => 'Aetna',
            'policy_number' => 'POL-123',
            'patient_id' => $patient->id,
        ])->assertOk()->assertJsonPath('data.transaction', '270/271');

        $this->postJson('/api/v1/billing/expenses', [
            'category' => 'not-a-real-category',
            'amount' => 40,
        ])->assertStatus(422);

        $this->postJson('/api/v1/billing/expenses', [
            'category' => 'supplies',
            'amount' => 40,
            'description' => 'Gloves',
        ])->assertCreated();

        $this->getJson('/api/v1/billing/expenses')->assertOk();
        $this->getJson('/api/v1/billing/codes/pending')->assertOk();
    }

    public function test_non_billing_cannot_access_billing_routes(): void
    {
        [, , $patient] = $this->world();
        $counselor = User::factory()->create([
            'clinic_id' => $patient->clinic_id,
            'is_active' => true,
            'pin_hash' => Hash::make('1234'),
        ]);
        $counselor->assignRole(Roles::COUNSELOR);
        Sanctum::actingAs($counselor);

        $this->getJson('/api/v1/billing/dashboard')->assertForbidden();
        $this->postJson('/api/v1/billing/claims', [
            'patient_id' => $patient->id,
            'billed_amount' => 50,
            'submit' => false,
        ])->assertForbidden();
    }

    /**
     * @return array{0: Clinic, 1: User, 2: Patient}
     */
    private function world(): array
    {
        $plan = SubscriptionPlan::create([
            'name' => 'B',
            'slug' => 'b-plan',
            'billing_period' => 'monthly',
            'price_cents' => 100,
            'ai_credits_monthly' => 100,
            'storage_mb' => 100,
            'is_active' => true,
        ]);
        $clinic = Clinic::create([
            'name' => 'Bill Clinic',
            'slug' => 'bill-clinic',
            'status' => 'active',
            'subscription_plan_id' => $plan->id,
            'ai_credits_balance' => 10,
        ]);
        $billing = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'can_prescribe' => false,
            'pin_hash' => Hash::make('1234'),
        ]);
        $billing->assignRole(Roles::BILLING);

        $patient = Patient::create([
            'clinic_id' => $clinic->id,
            'mrn' => 'MRN-B1',
            'first_name' => 'Bill',
            'last_name' => 'Patient',
            'date_of_birth' => '1980-01-01',
        ]);

        return [$clinic, $billing, $patient];
    }
}
