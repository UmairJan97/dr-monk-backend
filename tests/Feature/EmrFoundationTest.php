<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmrFoundationTest extends TestCase
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

    public function test_login_returns_generic_error_for_bad_credentials(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrongpass',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonPath('errors.email.0', 'Invalid credentials');
    }

    public function test_login_success_returns_secure_envelope_and_roles(): void
    {
        [$clinic, $desk] = $this->seedClinicWorld();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $desk->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.roles.0', Roles::FRONT_DESK)
            ->assertJsonStructure(['data' => ['token', 'user' => ['permissions']]]);

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_logout_revokes_current_token(): void
    {
        [, $desk] = $this->seedClinicWorld();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $desk->email,
            'password' => 'password',
        ])->assertOk();

        $token = $login->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        // Reset guard state between requests (Laravel test client caches auth).
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $desk->id,
        ]);
    }

    public function test_sleep_mode_blocks_protected_routes_until_wake(): void
    {
        [, $desk] = $this->seedClinicWorld();
        Sanctum::actingAs($desk);

        $this->postJson('/api/v1/auth/sleep')->assertOk();

        $this->getJson('/api/v1/patients')
            ->assertStatus(423)
            ->assertJsonPath('code', 'LOCKED');

        $this->postJson('/api/v1/auth/wake', ['pin' => '1234'])
            ->assertOk()
            ->assertJsonPath('data.sleep_mode', false);

        $this->getJson('/api/v1/patients')->assertOk();
    }

    public function test_super_admin_cannot_access_clinical_patient_routes(): void
    {
        [$clinic] = $this->seedClinicWorld();
        $super = User::factory()->create(['clinic_id' => null, 'is_active' => true]);
        $super->assignRole(Roles::SUPER_ADMIN);

        Sanctum::actingAs($super);
        $this->getJson('/api/v1/patients')->assertForbidden();
        $this->getJson('/api/v1/saas/dashboard')->assertOk();
    }

    public function test_role_permissions_matrix_is_seeded(): void
    {
        $doctorRole = Role::findByName(Roles::DOCTOR, 'web');
        $adminRole = Role::findByName(Roles::CLINIC_ADMIN, 'web');

        $this->assertTrue($doctorRole->hasPermissionTo(Permissions::PRESCRIPTIONS_WRITE));
        $this->assertFalse($adminRole->hasPermissionTo(Permissions::PRESCRIPTIONS_WRITE));
        $this->assertTrue($adminRole->hasPermissionTo(Permissions::ADMIN_MANAGE));
    }

    public function test_invitation_accept_activates_user_within_48h(): void
    {
        [$clinic, , , , $admin] = $this->seedClinicWorld();
        Sanctum::actingAs($admin);

        $invite = $this->postJson('/api/v1/admin/invitations', [
            'email' => 'newdesk@demo.local',
            'role' => Roles::FRONT_DESK,
        ])->assertCreated();

        $token = $invite->json('data.activation_token');
        $this->assertSame(64, strlen($token));

        $this->postJson('/api/v1/auth/accept-invitation', [
            'token' => $token,
            'name' => 'New Desk',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'pin' => '4321',
        ])->assertCreated()
            ->assertJsonPath('data.user.roles.0', Roles::FRONT_DESK);

        $this->assertDatabaseHas('users', ['email' => 'newdesk@demo.local', 'clinic_id' => $clinic->id]);
        $this->assertNotNull(UserInvitation::query()->where('email', 'newdesk@demo.local')->value('accepted_at'));
    }

    public function test_double_booking_is_rejected(): void
    {
        [$clinic, $desk, $doctor, $patient] = $this->seedClinicWorld();

        Sanctum::actingAs($desk);

        $payload = [
            'patient_id' => $patient->id,
            'provider_id' => $doctor->id,
            'starts_at' => now()->addDay()->setTime(10, 0)->toIso8601String(),
            'ends_at' => now()->addDay()->setTime(10, 30)->toIso8601String(),
        ];

        $this->postJson('/api/v1/front-desk/appointments', $payload)->assertCreated();
        $this->postJson('/api/v1/front-desk/appointments', $payload)->assertStatus(422);
    }

    public function test_front_desk_cannot_see_clinical_fields_on_patient_show(): void
    {
        [$clinic, $desk, $doctor, $patient] = $this->seedClinicWorld();
        $patient->update(['allergies' => 'Penicillin', 'active_medications' => 'Lisinopril']);

        Sanctum::actingAs($desk);
        $response = $this->getJson('/api/v1/patients/'.$patient->id);

        $response->assertOk();
        $response->assertJsonMissing(['allergies' => 'Penicillin']);
        $response->assertJsonPath('data.first_name', $patient->first_name);
        $this->assertArrayNotHasKey('allergies', $response->json('data'));
    }

    public function test_vitals_bmi_and_ready_for_provider_flow(): void
    {
        [$clinic, $desk, $doctor, $patient] = $this->seedClinicWorld();
        $nurse = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'pin_hash' => Hash::make('1234'),
        ]);
        $nurse->assignRole(Roles::VITAL_NURSE);

        $appointment = Appointment::create([
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'provider_id' => $doctor->id,
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
            'status' => 'waiting',
        ]);

        Sanctum::actingAs($nurse);
        $vital = $this->postJson('/api/v1/vitals', [
            'patient_id' => $patient->id,
            'appointment_id' => $appointment->id,
            'height_cm' => 170,
            'weight_kg' => 68,
            'bp_systolic' => 150,
            'bp_diastolic' => 95,
            'temperature_c' => 38.2,
            'pulse' => 88,
            'spo2' => 97,
        ])->assertCreated();

        $this->assertEquals(23.53, $vital->json('data.bmi'));
        $this->assertContains('blood_pressure', $vital->json('data.alerts'));

        $this->postJson('/api/v1/vitals/appointments/'.$appointment->id.'/complete')
            ->assertOk()
            ->assertJsonPath('data.appointment.status', 'ready_for_provider');

        $this->assertDatabaseHas('clinic_notifications', [
            'clinic_id' => $clinic->id,
            'user_id' => $doctor->id,
            'type' => 'vitals.ready',
        ]);
    }

    public function test_encrypted_file_upload_and_signed_download(): void
    {
        [$clinic, $desk, $doctor, $patient] = $this->seedClinicWorld();
        Sanctum::actingAs($desk);

        $file = \Illuminate\Http\UploadedFile::fake()->create('card.pdf', 120, 'application/pdf');

        $upload = $this->post('/api/v1/files', [
            'file' => $file,
            'doc_type' => 'insurance_card',
            'patient_id' => $patient->id,
            'title' => 'Insurance card',
        ], ['Accept' => 'application/json'])->assertCreated();

        $documentId = $upload->json('data.id');
        $this->assertTrue($upload->json('data.is_encrypted'));
        $this->assertNotEmpty($upload->json('data.signed_url'));

        $document = \App\Models\Document::query()->findOrFail($documentId);
        $diskPath = storage_path('app/phi/'.$document->file_path);
        $this->assertFileExists($diskPath);
        $rawOnDisk = file_get_contents($diskPath);
        $this->assertStringNotContainsString('%PDF', $rawOnDisk ?: '');

        $signed = $this->postJson('/api/v1/files/'.$documentId.'/signed-url', ['minutes' => 5])
            ->assertOk()
            ->json('data.signed_url');

        $path = parse_url($signed, PHP_URL_PATH);
        $query = parse_url($signed, PHP_URL_QUERY);
        $this->getJson($path.'?'.$query)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_forgot_password_is_anti_enumeration(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'missing@example.com',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'If that email exists, a reset link has been sent.');
    }

    public function test_admin_cannot_write_prescriptions_via_clinical_route(): void
    {
        [$clinic, $desk, $doctor, $patient] = $this->seedClinicWorld();
        $admin = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'pin_hash' => Hash::make('1234'),
        ]);
        $admin->assignRole(Roles::CLINIC_ADMIN);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/clinical/patients/'.$patient->id.'/prescriptions', [
            'medication_name' => 'Amoxicillin',
        ])->assertForbidden();
    }

    public function test_doctor_without_assignment_is_denied_phi(): void
    {
        [$clinic, $desk, $doctor, $patient] = $this->seedClinicWorld();
        $other = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'can_prescribe' => true,
            'pin_hash' => Hash::make('1234'),
        ]);
        $other->assignRole(Roles::DOCTOR);

        Sanctum::actingAs($other);
        $this->getJson('/api/v1/patients/'.$patient->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_coding_suggest_requires_explicit_confirm(): void
    {
        [$clinic, $desk, $doctor, $patient] = $this->seedClinicWorld();
        $doctor->assignedPatients()->attach($patient->id, ['clinic_id' => $clinic->id]);

        Sanctum::actingAs($doctor);
        $response = $this->postJson('/api/v1/clinical/patients/'.$patient->id.'/diagnoses', [
            'description' => 'URI',
            'icd10_code' => 'J06.9',
        ])->assertCreated();

        $this->assertNotEmpty($response->json('data.coding_suggestions'));
        $this->assertDatabaseHas('billing_codes', [
            'patient_id' => $patient->id,
            'status' => 'suggested',
            'source' => 'ai_suggest',
        ]);
    }

    /**
     * @return array{0: Clinic, 1: User, 2: User, 3: Patient, 4: User}
     */
    private function seedClinicWorld(): array
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Test',
            'slug' => 'test',
            'billing_period' => 'monthly',
            'price_cents' => 100,
            'ai_credits_monthly' => 100,
            'storage_mb' => 100,
            'is_active' => true,
        ]);

        $clinic = Clinic::create([
            'name' => 'Test Clinic',
            'slug' => 'test-clinic',
            'status' => 'active',
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

        $admin = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'pin_hash' => Hash::make('1234'),
        ]);
        $admin->assignRole(Roles::CLINIC_ADMIN);

        $patient = Patient::create([
            'clinic_id' => $clinic->id,
            'mrn' => 'MRN-TEST1',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'date_of_birth' => '1990-01-01',
            'primary_provider_id' => $doctor->id,
        ]);

        return [$clinic, $desk, $doctor, $patient, $admin];
    }
}
