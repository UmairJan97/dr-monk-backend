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

class EmrVitalNurseTest extends TestCase
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

    public function test_vitals_queue_intake_alerts_and_provider_notify(): void
    {
        [$clinic, $nurse, $doctor, $patient, $appointment] = $this->world();
        Sanctum::actingAs($nurse);

        $this->getJson('/api/v1/vitals/dashboard')
            ->assertOk()
            ->assertJsonPath('data.stats.waiting', 1);

        $this->getJson('/api/v1/vitals/queue')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $appointment->id);

        $this->postJson('/api/v1/vitals/appointments/'.$appointment->id.'/start')
            ->assertOk()
            ->assertJsonPath('data.appointment.status', 'ready_for_vitals');

        $vital = $this->postJson('/api/v1/vitals', [
            'patient_id' => $patient->id,
            'appointment_id' => $appointment->id,
            'height_in' => 67,
            'weight_lb' => 150,
            'bp_systolic' => 150,
            'bp_diastolic' => 95,
            'temperature_f' => 100.8,
            'pulse' => 88,
            'spo2' => 97,
            'pain_scale' => 3,
        ])->assertCreated();

        $this->assertNotNull($vital->json('data.bmi'));
        $this->assertContains('blood_pressure', $vital->json('data.alerts'));
        $this->assertContains('high_temperature', $vital->json('data.alerts'));

        $this->getJson('/api/v1/vitals/patients/'.$patient->id.'/overview')
            ->assertOk()
            ->assertJsonPath('data.restricted.diagnoses', false)
            ->assertJsonMissing(['allergies' => 'should-not-leak-via-demo']);

        $this->postJson('/api/v1/vitals/appointments/'.$appointment->id.'/complete')
            ->assertOk()
            ->assertJsonPath('data.appointment.status', 'ready_for_provider');

        $this->assertDatabaseHas('clinic_notifications', [
            'user_id' => $doctor->id,
            'type' => 'vitals.ready',
        ]);
    }

    public function test_cannot_complete_without_saved_vitals(): void
    {
        [, $nurse, , , $appointment] = $this->world();
        Sanctum::actingAs($nurse);

        $this->postJson('/api/v1/vitals/appointments/'.$appointment->id.'/complete')
            ->assertStatus(422);
    }

    public function test_us_vitals_validation_rejects_bad_bp(): void
    {
        [, $nurse, , $patient, $appointment] = $this->world();
        Sanctum::actingAs($nurse);

        $this->postJson('/api/v1/vitals', [
            'patient_id' => $patient->id,
            'appointment_id' => $appointment->id,
            'bp_systolic' => 80,
            'bp_diastolic' => 90,
            'temperature_f' => 98.6,
            'pulse' => 70,
            'spo2' => 98,
        ])->assertStatus(422);
    }

    public function test_front_desk_cannot_post_vitals(): void
    {
        [$clinic, $nurse, $doctor, $patient] = $this->world();
        $desk = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'pin_hash' => Hash::make('1234'),
        ]);
        $desk->assignRole(Roles::FRONT_DESK);
        Sanctum::actingAs($desk);

        $this->postJson('/api/v1/vitals', [
            'patient_id' => $patient->id,
            'bp_systolic' => 120,
            'bp_diastolic' => 80,
            'temperature_f' => 98.6,
            'pulse' => 72,
            'spo2' => 98,
        ])->assertForbidden();
    }

    /**
     * @return array{0: Clinic, 1: User, 2: User, 3: Patient, 4: Appointment}
     */
    private function world(): array
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Vitals Plan',
            'slug' => 'vitals-plan',
            'billing_period' => 'monthly',
            'price_cents' => 100,
            'ai_credits_monthly' => 100,
            'storage_mb' => 100,
            'is_active' => true,
        ]);
        $clinic = Clinic::create([
            'name' => 'Vitals Clinic',
            'slug' => 'vitals-clinic',
            'status' => 'active',
            'subscription_plan_id' => $plan->id,
            'ai_credits_balance' => 100,
        ]);
        $nurse = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'pin_hash' => Hash::make('1234'),
        ]);
        $nurse->assignRole(Roles::VITAL_NURSE);

        $doctor = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'can_prescribe' => true,
            'pin_hash' => Hash::make('1234'),
        ]);
        $doctor->assignRole(Roles::DOCTOR);

        $patient = Patient::create([
            'clinic_id' => $clinic->id,
            'mrn' => 'MRN-V1',
            'first_name' => 'Pat',
            'last_name' => 'Vitals',
            'date_of_birth' => '1991-01-01',
            'primary_provider_id' => $doctor->id,
        ]);

        $appointment = Appointment::create([
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'provider_id' => $doctor->id,
            'starts_at' => now()->setTime(9, 0),
            'ends_at' => now()->setTime(9, 30),
            'status' => 'waiting',
        ]);

        return [$clinic, $nurse, $doctor, $patient, $appointment];
    }
}
