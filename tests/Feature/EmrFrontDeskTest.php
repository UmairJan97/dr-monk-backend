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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmrFrontDeskTest extends TestCase
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

        Storage::fake('phi');
    }

    public function test_front_desk_registers_patient_without_clinical_phi(): void
    {
        [, $desk] = $this->world();
        Sanctum::actingAs($desk);

        $response = $this->postJson('/api/v1/patients', [
            'first_name' => 'Alex',
            'last_name' => 'Rivera',
            'date_of_birth' => '1992-04-12',
            'phone' => '(212) 555-0100',
            'email' => 'alex@example.com',
            'address' => '123 Main St',
            'city' => 'New York',
            'state' => 'NY',
            'zip' => '10001',
            'emergency_contact' => [
                'name' => 'Sam Rivera',
                'phone' => '(212) 555-0101',
                'relation' => 'Spouse',
            ],
            'allergies' => 'Penicillin',
            'active_medications' => 'Should not save',
            'insurance' => [
                'payer_name' => 'Aetna',
                'policy_number' => 'POL-100',
                'group_number' => 'G-9',
            ],
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.first_name', 'Alex');

        $this->assertDatabaseHas('patients', [
            'first_name' => 'Alex',
            'last_name' => 'Rivera',
        ]);

        $patient = Patient::query()->where('first_name', 'Alex')->firstOrFail();
        $this->assertNull($patient->allergies);
        $this->assertNull($patient->active_medications);
        $this->assertStringContainsString('New York', (string) $patient->address);
        $this->assertSame('Sam Rivera', $patient->emergency_contact['name'] ?? null);
        $this->assertDatabaseHas('patient_insurances', [
            'patient_id' => $patient->id,
        ]);
    }

    public function test_dashboard_queue_checkin_and_payment_flow(): void
    {
        [$clinic, $desk, $doctor, $patient] = $this->world();
        Sanctum::actingAs($desk);

        $this->getJson('/api/v1/front-desk/dashboard')
            ->assertOk()
            ->assertJsonPath('data.stats.todays_appointments', 0);

        $appt = $this->postJson('/api/v1/front-desk/appointments', [
            'patient_id' => $patient->id,
            'provider_id' => $doctor->id,
            'starts_at' => now()->setTime(10, 0)->toIso8601String(),
            'ends_at' => now()->setTime(10, 30)->toIso8601String(),
            'visit_type' => 'Office visit',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'scheduled');

        $appointmentId = $appt->json('data.id');

        $this->getJson('/api/v1/front-desk/queue')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $appointmentId);

        $this->postJson('/api/v1/front-desk/appointments/'.$appointmentId.'/check-in')
            ->assertOk()
            ->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.can_check_in', false)
            ->assertJsonPath('data.can_no_show', false)
            ->assertJsonPath('data.can_cancel', false);

        $payment = $this->postJson('/api/v1/front-desk/payments', [
            'appointment_id' => $appointmentId,
            'patient_id' => $patient->id,
            'amount' => 40.5,
            'method' => 'cash',
        ])->assertCreated();

        $this->assertNotEmpty($payment->json('data.receipt_number'));
        $this->assertSame(40.5, $payment->json('data.amount'));
        $this->assertSame($appointmentId, $payment->json('data.appointment_id'));
    }

    public function test_selfie_upload_links_to_patient_photo(): void
    {
        [, $desk, , $patient] = $this->world();
        Sanctum::actingAs($desk);

        $file = UploadedFile::fake()->image('selfie.jpg', 200, 200);

        $this->post('/api/v1/files', [
            'file' => $file,
            'doc_type' => 'selfie',
            'patient_id' => $patient->id,
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.doc_type', 'selfie');

        $patient->refresh();
        $this->assertNotNull($patient->photo_path);
        $this->assertStringStartsWith('document:', $patient->photo_path);
    }

    public function test_providers_list_for_scheduling(): void
    {
        [, $desk, $doctor] = $this->world();
        Sanctum::actingAs($desk);

        $this->getJson('/api/v1/front-desk/providers')
            ->assertOk()
            ->assertJsonFragment(['id' => $doctor->id, 'name' => $doctor->name]);
    }

    public function test_communication_reminder_has_no_phi_and_logs(): void
    {
        [, $desk, $doctor, $patient] = $this->world();
        $patient->update(['email' => 'jamie@example.com', 'phone' => '(212) 555-0199']);
        Sanctum::actingAs($desk);

        $appt = Appointment::create([
            'clinic_id' => $patient->clinic_id,
            'patient_id' => $patient->id,
            'provider_id' => $doctor->id,
            'starts_at' => now()->addDay()->setTime(10, 0),
            'ends_at' => now()->addDay()->setTime(10, 30),
            'status' => 'scheduled',
        ]);

        $this->getJson('/api/v1/front-desk/messages/templates')
            ->assertOk()
            ->assertJsonPath('data.items.0.key', 'appointment_reminder');

        $sent = $this->postJson('/api/v1/front-desk/messages', [
            'template_key' => 'appointment_reminder',
            'channel' => 'email',
            'patient_id' => $patient->id,
            'appointment_id' => $appt->id,
        ])->assertCreated();

        $body = $sent->json('data.body');
        $this->assertStringNotContainsString('Penicillin', $body);
        $this->assertStringNotContainsString($patient->first_name, $body);
        $this->assertStringContainsString('appointment', strtolower($body));

        $this->assertDatabaseHas('clinic_message_logs', [
            'patient_id' => $patient->id,
            'channel' => 'email',
            'status' => 'sent',
        ]);
    }

    public function test_tablet_intake_session_create_and_submit(): void
    {
        [, $desk] = $this->world();
        Sanctum::actingAs($desk);

        $session = $this->postJson('/api/v1/front-desk/intake-sessions', [
            'minutes' => 60,
        ])->assertCreated();

        $token = $session->json('data.token');
        $this->assertNotEmpty($session->json('data.intake_url'));

        // Public tablet endpoint (no auth)
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $this->getJson('/api/v1/intake/'.$token)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->postJson('/api/v1/intake/'.$token, [
            'first_name' => 'Tablet',
            'last_name' => 'Guest',
            'date_of_birth' => '1995-05-05',
            'phone' => '(617) 555-0111',
            'email' => 'tablet@example.com',
            'city' => 'Boston',
            'state' => 'MA',
            'zip' => '02108',
            'insurance' => [
                'payer_name' => 'Blue Cross',
                'policy_number' => 'BC-1',
            ],
            'allergies' => 'should be rejected',
        ])->assertStatus(422);

        $this->postJson('/api/v1/intake/'.$token, [
            'first_name' => 'Tablet',
            'last_name' => 'Guest',
            'date_of_birth' => '1995-05-05',
            'phone' => '(617) 555-0111',
            'email' => 'tablet@example.com',
            'city' => 'Boston',
            'state' => 'MA',
            'zip' => '02108',
            'insurance' => [
                'payer_name' => 'Blue Cross',
                'policy_number' => 'BC-1',
            ],
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('patients', [
            'first_name' => 'Tablet',
            'last_name' => 'Guest',
        ]);
        $this->assertDatabaseHas('patient_intake_sessions', [
            'token' => $token,
            'status' => 'completed',
        ]);
    }

    public function test_payment_receipt_endpoint(): void
    {
        [, $desk, $doctor, $patient] = $this->world();
        Sanctum::actingAs($desk);

        $appt = Appointment::create([
            'clinic_id' => $patient->clinic_id,
            'patient_id' => $patient->id,
            'provider_id' => $doctor->id,
            'starts_at' => now()->setTime(11, 0),
            'ends_at' => now()->setTime(11, 30),
            'status' => 'scheduled',
            'visit_type' => 'Office visit',
        ]);

        $pay = $this->postJson('/api/v1/front-desk/payments', [
            'appointment_id' => $appt->id,
            'patient_id' => $patient->id,
            'amount' => 25,
            'method' => 'card',
        ])->assertCreated();

        $this->getJson('/api/v1/front-desk/payments/'.$pay->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.print_friendly', true)
            ->assertJsonPath('data.receipt.amount', 25)
            ->assertJsonPath('data.appointment.id', $appt->id);
    }

    public function test_us_validation_rejects_bad_phone_zip_state(): void
    {
        [, $desk] = $this->world();
        Sanctum::actingAs($desk);

        $this->postJson('/api/v1/patients', [
            'first_name' => 'Bad',
            'last_name' => 'Phone',
            'date_of_birth' => '1990-01-01',
            'phone' => '123',
            'state' => 'XX',
            'zip' => 'ABC',
        ])->assertStatus(422);

        $this->postJson('/api/v1/patients', [
            'first_name' => 'Good',
            'last_name' => 'Phone',
            'date_of_birth' => '1990-01-01',
            'phone' => '2125550198',
            'state' => 'NY',
            'zip' => '10001-1234',
        ])->assertCreated()
            ->assertJsonPath('data.phone', '(212) 555-0198');
    }

    public function test_cancel_and_no_show(): void
    {
        [, $desk, $doctor, $patient] = $this->world();
        Sanctum::actingAs($desk);

        $appt = $this->postJson('/api/v1/front-desk/appointments', [
            'patient_id' => $patient->id,
            'provider_id' => $doctor->id,
            'starts_at' => now()->addHours(2)->toIso8601String(),
            'ends_at' => now()->addHours(2)->addMinutes(30)->toIso8601String(),
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/front-desk/appointments/'.$appt.'/no-show')
            ->assertOk()
            ->assertJsonPath('data.status', 'no_show');

        $appt2 = $this->postJson('/api/v1/front-desk/appointments', [
            'patient_id' => $patient->id,
            'provider_id' => $doctor->id,
            'starts_at' => now()->addHours(4)->toIso8601String(),
            'ends_at' => now()->addHours(4)->addMinutes(30)->toIso8601String(),
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/front-desk/appointments/'.$appt2.'/cancel', [
            'reason' => 'Patient request',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_edit_rebook_refund_insurance_and_phone_note(): void
    {
        [, $desk, $doctor, $patient] = $this->world();
        Sanctum::actingAs($desk);

        $patient->insurances()->create([
            'clinic_id' => $patient->clinic_id,
            'type' => 'primary',
            'payer_name' => 'Blue Cross',
            'policy_number' => 'BC-998877',
            'group_number' => 'G1',
            'expires_on' => now()->addYear()->toDateString(),
        ]);

        $apptId = $this->postJson('/api/v1/front-desk/appointments', [
            'patient_id' => $patient->id,
            'provider_id' => $doctor->id,
            'starts_at' => now()->addHours(3)->toIso8601String(),
            'ends_at' => now()->addHours(3)->addMinutes(30)->toIso8601String(),
            'visit_type' => 'Office visit',
            'room' => 'R1',
        ])->assertCreated()->json('data.id');

        $newStart = now()->addHours(5);
        $this->patchJson('/api/v1/front-desk/appointments/'.$apptId, [
            'provider_id' => $doctor->id,
            'starts_at' => $newStart->toIso8601String(),
            'ends_at' => $newStart->copy()->addMinutes(30)->toIso8601String(),
            'visit_type' => 'Follow-up',
            'room' => 'R2',
        ])->assertOk()
            ->assertJsonPath('data.room', 'R2')
            ->assertJsonPath('data.visit_type', 'Follow-up');

        $this->postJson('/api/v1/front-desk/appointments/'.$apptId.'/cancel', [
            'reason' => 'Reschedule',
        ])->assertOk();

        $rebookedId = $this->postJson('/api/v1/front-desk/appointments/'.$apptId.'/rebook', [
            'provider_id' => $doctor->id,
            'starts_at' => now()->addDay()->setTime(9, 0)->toIso8601String(),
            'ends_at' => now()->addDay()->setTime(9, 30)->toIso8601String(),
            'visit_type' => 'Office visit',
            'room' => 'R3',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.room', 'R3')
            ->json('data.id');

        $payId = $this->postJson('/api/v1/front-desk/payments', [
            'appointment_id' => $rebookedId,
            'patient_id' => $patient->id,
            'amount' => 40,
            'method' => 'cash',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/front-desk/payments/'.$payId.'/refund', [
            'reason' => 'Patient request',
        ])->assertOk()
            ->assertJsonPath('data.status', 'refunded');

        $this->getJson('/api/v1/front-desk/patients/'.$patient->id.'/ledger')
            ->assertOk()
            ->assertJsonPath('data.total_refunded', 40);

        $this->postJson('/api/v1/front-desk/insurance-verify', [
            'patient_id' => $patient->id,
        ])->assertOk()
            ->assertJsonPath('data.overall_status', 'verified_active');

        $this->postJson('/api/v1/front-desk/phone-notes', [
            'patient_id' => $patient->id,
            'note' => 'Called to confirm visit',
        ])->assertCreated()
            ->assertJsonPath('data.channel', 'phone');

        $this->postJson('/api/v1/front-desk/messages/mass', [
            'template_key' => 'appointment_reminder',
            'channel' => 'email',
            'patient_ids' => [$patient->id],
        ])->assertOk()
            ->assertJsonPath('data.sent', 1);
    }

    /**
     * @return array{0: Clinic, 1: User, 2: User, 3: Patient}
     */
    private function world(): array
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Test',
            'slug' => 'fd-test',
            'billing_period' => 'monthly',
            'price_cents' => 100,
            'ai_credits_monthly' => 100,
            'storage_mb' => 100,
            'is_active' => true,
        ]);

        $clinic = Clinic::create([
            'name' => 'FD Clinic',
            'slug' => 'fd-clinic',
            'status' => 'active',
            'timezone' => 'America/New_York',
            'subscription_plan_id' => $plan->id,
            'ai_credits_balance' => 100,
        ]);

        $desk = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'pin_hash' => Hash::make('1234'),
        ]);
        $desk->assignRole(Roles::FRONT_DESK);

        $doctor = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'can_prescribe' => true,
            'pin_hash' => Hash::make('1234'),
        ]);
        $doctor->assignRole(Roles::DOCTOR);

        $patient = Patient::create([
            'clinic_id' => $clinic->id,
            'mrn' => 'MRN-FD1',
            'first_name' => 'Jamie',
            'last_name' => 'Lee',
            'date_of_birth' => '1988-02-02',
            'phone' => '(212) 555-0144',
            'email' => 'jamie@example.com',
            'primary_provider_id' => $doctor->id,
        ]);

        return [$clinic, $desk, $doctor, $patient];
    }
}
