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

class EmrDoctorTest extends TestCase
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

    public function test_doctor_dashboard_chart_note_dx_rx_lab_flow(): void
    {
        [$clinic, $doctor, $patient, $appointment] = $this->world();
        $doctor->assignedPatients()->attach($patient->id, ['clinic_id' => $clinic->id]);
        Sanctum::actingAs($doctor);

        $this->getJson('/api/v1/clinical/dashboard')
            ->assertOk()
            ->assertJsonPath('data.stats.ready_queue', 1);

        $this->getJson('/api/v1/clinical/patients/'.$patient->id.'/chart')
            ->assertOk()
            ->assertJsonPath('data.patient.id', $patient->id);

        $note = $this->postJson('/api/v1/clinical/patients/'.$patient->id.'/notes', [
            'note_type' => 'soap',
            'content' => 'SOAP note content',
            'structured' => [
                'subjective' => 'Cough',
                'objective' => 'Lungs clear',
                'assessment' => 'URI',
                'plan' => 'Supportive care',
            ],
        ])->assertCreated();

        $noteId = $note->json('data.id');
        $this->postJson('/api/v1/clinical/patients/'.$patient->id.'/notes/'.$noteId.'/sign')
            ->assertOk()
            ->assertJsonPath('data.is_signed', true);

        $dx = $this->postJson('/api/v1/clinical/patients/'.$patient->id.'/diagnoses', [
            'description' => 'Acute URI',
            'icd10_code' => 'J06.9',
        ])->assertCreated();
        $this->assertNotEmpty($dx->json('data.coding_suggestions'));

        $this->postJson('/api/v1/clinical/patients/'.$patient->id.'/prescriptions', [
            'medication_name' => 'Amoxicillin',
            'sig' => '1 tab BID',
            'quantity' => '14',
            'refills' => '0',
        ])->assertCreated();

        $lab = $this->postJson('/api/v1/clinical/patients/'.$patient->id.'/lab-orders', [
            'test_name' => 'CBC',
        ])->assertCreated();

        $this->patchJson('/api/v1/clinical/lab-orders/'.$lab->json('data.id').'/result', [
            'status' => 'resulted',
            'result_summary' => 'WNL',
        ])->assertOk();

        $this->postJson('/api/v1/clinical/patients/'.$patient->id.'/treatment-plans', [
            'recommendations' => 'Rest and fluids',
            'referrals' => 'ENT if persists',
        ])->assertCreated();

        $this->postJson('/api/v1/clinical/patients/'.$patient->id.'/follow-ups', [
            'instructions' => 'Return in 7 days',
            'due_at' => now()->addDays(7)->toIso8601String(),
        ])->assertCreated();

        $this->getJson('/api/v1/clinical/patients/'.$patient->id.'/chart')
            ->assertOk()
            ->assertJsonPath('data.treatment_plans.0.recommendations', 'Rest and fluids')
            ->assertJsonPath('data.follow_ups.0.status', 'open')
            ->assertJsonStructure(['data' => ['documents']]);

        $this->getJson('/api/v1/clinical/schedule?from='.now()->toDateString().'&to='.now()->addDays(7)->toDateString())
            ->assertOk()
            ->assertJsonStructure(['data' => ['items']]);

        $this->getJson('/api/v1/clinical/analytics')->assertOk();
        $this->postJson('/api/v1/clinical/appointments/'.$appointment->id.'/start')
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->postJson('/api/v1/clinical/appointments/'.$appointment->id.'/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_soap_and_icd10_validation(): void
    {
        [, $doctor, $patient] = $this->world();
        $doctor->assignedPatients()->attach($patient->id, ['clinic_id' => $patient->clinic_id]);
        Sanctum::actingAs($doctor);

        $this->postJson('/api/v1/clinical/patients/'.$patient->id.'/notes', [
            'note_type' => 'soap',
            'content' => 'incomplete',
            'structured' => ['subjective' => 'only S'],
        ])->assertStatus(422);

        $this->postJson('/api/v1/clinical/patients/'.$patient->id.'/diagnoses', [
            'description' => 'URI',
            'icd10_code' => 'BAD',
        ])->assertStatus(422);
    }

    public function test_unassigned_doctor_denied_chart(): void
    {
        [$clinic, $doctor, $patient] = $this->world();
        $other = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'can_prescribe' => true,
            'pin_hash' => Hash::make('1234'),
        ]);
        $other->assignRole(Roles::DOCTOR);
        Sanctum::actingAs($other);

        $this->getJson('/api/v1/clinical/patients/'.$patient->id.'/chart')->assertForbidden();
    }

    public function test_hello_monk_doctor_intents(): void
    {
        [, $doctor] = $this->world();
        Sanctum::actingAs($doctor);

        $this->postJson('/api/v1/ai/monk', ['transcript' => 'Monk, show labs'])
            ->assertOk()
            ->assertJsonPath('intent', 'show_labs');
    }

    /**
     * @return array{0: Clinic, 1: User, 2: Patient, 3: Appointment}
     */
    private function world(): array
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Doc',
            'slug' => 'doc-plan',
            'billing_period' => 'monthly',
            'price_cents' => 100,
            'ai_credits_monthly' => 100,
            'storage_mb' => 100,
            'is_active' => true,
        ]);
        $clinic = Clinic::create([
            'name' => 'Doc Clinic',
            'slug' => 'doc-clinic',
            'status' => 'active',
            'subscription_plan_id' => $plan->id,
            'ai_credits_balance' => 100,
        ]);
        $doctor = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'can_prescribe' => true,
            'pin_hash' => Hash::make('1234'),
        ]);
        $doctor->assignRole(Roles::DOCTOR);

        $patient = Patient::create([
            'clinic_id' => $clinic->id,
            'mrn' => 'MRN-D1',
            'first_name' => 'Dana',
            'last_name' => 'Patient',
            'date_of_birth' => '1985-03-03',
            'primary_provider_id' => $doctor->id,
        ]);

        $appointment = Appointment::create([
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'provider_id' => $doctor->id,
            'starts_at' => now()->setTime(11, 0),
            'ends_at' => now()->setTime(11, 30),
            'status' => 'ready_for_provider',
        ]);

        return [$clinic, $doctor, $patient, $appointment];
    }
}
