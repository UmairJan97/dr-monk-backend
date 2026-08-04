<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Diagnosis;
use App\Models\Patient;
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

class EmrCounselorTest extends TestCase
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

    public function test_counselor_dashboard_session_assessment_and_dx_readonly(): void
    {
        [, $counselor, $patient] = $this->world();
        Sanctum::actingAs($counselor);

        Diagnosis::create([
            'clinic_id' => $patient->clinic_id,
            'patient_id' => $patient->id,
            'description' => 'Anxiety',
            'icd10_code' => 'F41.1',
            'status' => 'active',
            'recorded_by' => $counselor->id,
        ]);

        $this->getJson('/api/v1/counselor/dashboard')->assertOk();
        $this->getJson('/api/v1/counselor/schedule')->assertOk();

        $this->getJson('/api/v1/counselor/patients/'.$patient->id.'/doctor-diagnosis')
            ->assertOk()
            ->assertJsonPath('data.read_only', true);

        $this->postJson('/api/v1/counselor/patients/'.$patient->id.'/sessions', [
            'notes' => 'short',
        ])->assertStatus(422);

        $session = $this->postJson('/api/v1/counselor/patients/'.$patient->id.'/sessions', [
            'notes' => 'CBT session focusing on coping skills and sleep hygiene.',
            'goals' => ['Reduce anxiety', 'Sleep hygiene'],
            'session_type' => 'individual',
            'modality' => 'telehealth',
            'duration_minutes' => 60,
        ])->assertCreated();

        $suggestions = $session->json('data.coding_suggestions');
        $this->assertNotEmpty($suggestions);
        $codes = array_column($suggestions, 'code');
        $this->assertContains('F41.1', $codes);
        $this->assertContains('90837', $codes);

        $this->patchJson(
            '/api/v1/counselor/patients/'.$patient->id.'/sessions/'.$session->json('data.session.id').'/goals',
            ['goals' => ['Updated goal']]
        )->assertOk();

        $this->postJson('/api/v1/counselor/patients/'.$patient->id.'/assessments', [
            'instrument' => 'PHQ-9',
            'responses' => ['q1' => 1],
            'score' => 8,
        ])->assertCreated();

        $this->postJson('/api/v1/counselor/patients/'.$patient->id.'/assessments', [
            'instrument' => 'GAD-7',
            'responses' => ['q1' => 2, 'q2' => 1, 'q3' => 0, 'q4' => 1, 'q5' => 0, 'q6' => 1, 'q7' => 0],
            'score' => 5,
        ])->assertCreated();

        $this->postJson('/api/v1/counselor/patients/'.$patient->id.'/assessments', [
            'instrument' => 'custom',
            'responses' => ['note' => 'Clinician observation only'],
            'score' => null,
        ])->assertCreated();

        $codesResp = $this->getJson('/api/v1/counselor/patients/'.$patient->id.'/billing-codes')->assertOk();
        $items = $codesResp->json('data.items');
        if (! empty($items)) {
            $this->postJson('/api/v1/counselor/billing-codes/'.$items[0]['id'].'/confirm', [
                'status' => 'confirmed',
            ])->assertOk();
        }

        $this->postJson('/api/v1/ai/monk', ['transcript' => 'Monk, start therapy session'])
            ->assertOk()
            ->assertJsonPath('intent', 'start_counseling_session');
    }

    public function test_counselor_cannot_write_clinical_dx_or_rx(): void
    {
        [, $counselor, $patient] = $this->world();
        Sanctum::actingAs($counselor);

        $this->getJson('/api/v1/clinical/dashboard')->assertForbidden();

        $this->postJson('/api/v1/clinical/patients/'.$patient->id.'/diagnoses', [
            'description' => 'Should fail',
            'icd10_code' => 'F32.1',
            'status' => 'active',
        ])->assertForbidden();

        $this->postJson('/api/v1/clinical/patients/'.$patient->id.'/prescriptions', [
            'medication_name' => 'Sertraline',
            'sig' => '50mg daily',
            'quantity' => 30,
            'refills' => 0,
        ])->assertForbidden();

        $this->getJson('/api/v1/billing/dashboard')->assertForbidden();
        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
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
            'name' => 'C',
            'slug' => 'c-plan',
            'billing_period' => 'monthly',
            'price_cents' => 100,
            'ai_credits_monthly' => 100,
            'storage_mb' => 100,
            'is_active' => true,
        ]);
        $clinic = Clinic::create([
            'name' => 'Counsel Clinic',
            'slug' => 'counsel-clinic',
            'status' => 'active',
            'subscription_plan_id' => $plan->id,
            'ai_credits_balance' => 50,
        ]);
        $counselor = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'can_prescribe' => false,
            'pin_hash' => Hash::make('1234'),
        ]);
        $counselor->assignRole(Roles::COUNSELOR);

        $patient = Patient::create([
            'clinic_id' => $clinic->id,
            'mrn' => 'MRN-C1',
            'first_name' => 'Casey',
            'last_name' => 'Client',
            'date_of_birth' => '1992-02-02',
        ]);

        return [$clinic, $counselor, $patient];
    }
}
