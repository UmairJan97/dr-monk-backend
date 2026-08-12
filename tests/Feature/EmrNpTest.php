<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Clinic;
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

class EmrNpTest extends TestCase
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

    public function test_np_uses_clinical_dashboard_and_chart(): void
    {
        [$clinic, $np, $patient] = $this->world(canPrescribe: true, license: 'NY');
        $np->assignedPatients()->attach($patient->id, ['clinic_id' => $clinic->id]);
        Sanctum::actingAs($np);

        $this->getJson('/api/v1/clinical/dashboard')->assertOk();
        $this->getJson('/api/v1/clinical/patients/'.$patient->id.'/chart')
            ->assertOk()
            ->assertJsonPath('data.patient.id', $patient->id);

        $this->postJson('/api/v1/clinical/patients/'.$patient->id.'/prescriptions', [
            'medication_name' => 'Amoxicillin',
            'sig' => '1 tab BID',
            'quantity' => '14',
            'refills' => '0',
        ])->assertCreated();
    }

    public function test_np_blocked_when_license_state_not_allowed(): void
    {
        config(['drmonk.prescribe_allowed_states' => 'CA,TX']);
        [$clinic, $np, $patient] = $this->world(canPrescribe: true, license: 'NY');
        $np->assignedPatients()->attach($patient->id, ['clinic_id' => $clinic->id]);
        Sanctum::actingAs($np);

        $this->postJson('/api/v1/clinical/patients/'.$patient->id.'/prescriptions', [
            'medication_name' => 'Ibuprofen',
            'sig' => '1 tab PRN',
        ])->assertForbidden();
    }

    public function test_np_ready_queue_and_complete_visit(): void
    {
        [$clinic, $np, $patient] = $this->world(canPrescribe: true, license: 'NY');
        $np->assignedPatients()->attach($patient->id, ['clinic_id' => $clinic->id]);
        Sanctum::actingAs($np);

        $this->getJson('/api/v1/clinical/dashboard')
            ->assertOk()
            ->assertJsonPath('data.stats.ready_queue', 1);

        $apptId = Appointment::query()->where('provider_id', $np->id)->value('id');
        $this->postJson('/api/v1/clinical/appointments/'.$apptId.'/start')
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
        $this->postJson('/api/v1/clinical/appointments/'.$apptId.'/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_np_hello_monk_prescribe_intent(): void
    {
        [, $np] = $this->world(canPrescribe: true, license: 'NY');
        Sanctum::actingAs($np);

        $this->postJson('/api/v1/ai/monk', ['transcript' => 'Monk, prescribe medication'])
            ->assertOk()
            ->assertJsonPath('intent', 'np_prescribe');
    }

    public function test_appointment_provider_np_can_open_chart_without_pivot(): void
    {
        [$clinic, $np] = $this->world(canPrescribe: true, license: 'NY');
        $patient = Patient::create([
            'clinic_id' => $clinic->id,
            'mrn' => 'MRN-NP2',
            'first_name' => 'Visit',
            'last_name' => 'Only',
            'date_of_birth' => '1991-02-02',
            'primary_provider_id' => null,
        ]);
        Appointment::create([
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'provider_id' => $np->id,
            'starts_at' => now()->setTime(15, 0),
            'ends_at' => now()->setTime(15, 30),
            'status' => 'ready_for_provider',
        ]);
        Sanctum::actingAs($np);

        $this->getJson('/api/v1/clinical/patients/'.$patient->id.'/chart')
            ->assertOk()
            ->assertJsonPath('data.patient.id', $patient->id);
    }

    /**
     * @return array{0: Clinic, 1: User, 2: Patient}
     */
    private function world(bool $canPrescribe, string $license): array
    {
        $plan = SubscriptionPlan::create([
            'name' => 'NP',
            'slug' => 'np-plan',
            'billing_period' => 'monthly',
            'price_cents' => 100,
            'ai_credits_monthly' => 100,
            'storage_mb' => 100,
            'is_active' => true,
        ]);
        $clinic = Clinic::create([
            'name' => 'NP Clinic',
            'slug' => 'np-clinic',
            'status' => 'active',
            'subscription_plan_id' => $plan->id,
            'ai_credits_balance' => 100,
        ]);
        $np = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'can_prescribe' => $canPrescribe,
            'license_state' => $license,
            'pin_hash' => Hash::make('1234'),
        ]);
        $np->assignRole(Roles::NP);

        $patient = Patient::create([
            'clinic_id' => $clinic->id,
            'mrn' => 'MRN-NP1',
            'first_name' => 'Nina',
            'last_name' => 'Patient',
            'date_of_birth' => '1990-01-01',
            'primary_provider_id' => $np->id,
        ]);

        Appointment::create([
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'provider_id' => $np->id,
            'starts_at' => now()->setTime(10, 0),
            'ends_at' => now()->setTime(10, 30),
            'status' => 'ready_for_provider',
        ]);

        return [$clinic, $np, $patient];
    }
}
