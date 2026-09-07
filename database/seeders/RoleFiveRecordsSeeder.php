<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\BillingCode;
use App\Models\Claim;
use App\Models\Clinic;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vital;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Puts ~5 visible today-queue records on each operational role screen.
 *
 * Flow (as requested): Front Desk → Vital Nurse → NP → Doctor
 * (NP gets ready_for_np after vitals; Doctor gets ready_for_provider after NP.)
 */
class RoleFiveRecordsSeeder extends Seeder
{
    public function run(): void
    {
        $clinic = Clinic::query()->where('slug', 'demo-clinic')->first();
        $desk = User::query()->where('email', 'desk@demo.local')->first();
        $nurse = User::query()->where('email', 'vitals@demo.local')->first();
        $np = User::query()->where('email', 'np@demo.local')->first();
        $doctor = User::query()->where('email', 'doctor@demo.local')->first();
        $counselor = User::query()->where('email', 'counselor@demo.local')->first();
        $billing = User::query()->where('email', 'billing@demo.local')->first();

        if (! $clinic || ! $desk || ! $nurse || ! $np || ! $doctor) {
            $this->command?->warn('Demo clinic/users missing — run DatabaseSeeder first.');

            return;
        }

        $np->forceFill([
            'can_prescribe' => true,
            'license_state' => config('drmonk.default_license_state', 'NY'),
            'npi' => $np->npi ?: '1234567890',
        ])->save();

        $doctor->forceFill([
            'can_prescribe' => true,
            'license_state' => config('drmonk.default_license_state', 'NY'),
            'npi' => $doctor->npi ?: '1987654321',
        ])->save();

        $tz = $clinic->timezone ?: 'America/New_York';
        $now = Carbon::now($tz);

        // Remove prior role-five demos so re-seed is clean
        Appointment::query()
            ->where('clinic_id', $clinic->id)
            ->where('notes', 'like', '%[role5-%')
            ->delete();

        Claim::query()
            ->where('clinic_id', $clinic->id)
            ->where('clearinghouse_id', 'like', 'ROLE5-%')
            ->delete();

        Payment::query()
            ->where('clinic_id', $clinic->id)
            ->where('receipt_number', 'like', 'ROLE5-%')
            ->delete();

        // ——— Front Desk: 5 scheduled / waiting (today) ———
        $deskRows = [
            ['Riley', 'Adams', '1991-02-11', 'Female', '5557001001', 'riley.adams.role5@example.com', 'Aetna', 'AET-R501'],
            ['Kai', 'Bennett', '1987-06-20', 'Male', '5557001002', 'kai.bennett.role5@example.com', 'Cigna', 'CIG-R502'],
            ['Nora', 'Collins', '1995-09-03', 'Female', '5557001003', 'nora.collins.role5@example.com', 'UnitedHealthcare', 'UHC-R503'],
            ['Owen', 'Diaz', '1980-12-15', 'Male', '5557001004', 'owen.diaz.role5@example.com', 'Blue Cross', 'BCBS-R504'],
            ['Piper', 'Evans', '1999-04-28', 'Female', '5557001005', 'piper.evans.role5@example.com', 'Horizon', 'HOR-R505'],
        ];
        $deskStatuses = ['scheduled', 'scheduled', 'waiting', 'scheduled', 'waiting'];

        foreach ($deskRows as $i => $r) {
            $patient = $this->upsertPatient($clinic->id, $doctor->id, $r, 'MRN-R5FD', $i);
            $start = $now->copy()->startOfDay()->addHours(8)->addMinutes(10 + ($i * 20));
            if ($start->lt($now->copy()->subMinutes(10))) {
                $start = $now->copy()->addMinutes(15 + ($i * 12));
            }
            Appointment::query()->create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'provider_id' => $i % 2 === 0 ? $np->id : $doctor->id,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes(25),
                'status' => $deskStatuses[$i],
                'visit_type' => 'Office visit',
                'room' => 'D'.($i + 1),
                'notes' => '[role5-front-desk]',
            ]);
        }

        // ——— Vital Nurse: 5 waiting / in-vitals (today) ———
        $vitalRows = [
            ['Quinn', 'Foster', '1993-01-08', 'Male', '5557002001', 'quinn.foster.role5@example.com', 'Aetna', 'AET-R511'],
            ['Sage', 'Green', '1989-07-19', 'Female', '5557002002', 'sage.green.role5@example.com', 'Cigna', 'CIG-R512'],
            ['Tate', 'Hayes', '1976-03-22', 'Male', '5557002003', 'tate.hayes.role5@example.com', 'UnitedHealthcare', 'UHC-R513'],
            ['Uma', 'Iyer', '1996-11-30', 'Female', '5557002004', 'uma.iyer.role5@example.com', 'Blue Cross', 'BCBS-R514'],
            ['Vera', 'Jones', '1984-05-05', 'Female', '5557002005', 'vera.jones.role5@example.com', 'Horizon', 'HOR-R515'],
        ];
        $vitalStatuses = ['waiting', 'waiting', 'ready_for_vitals', 'waiting', 'ready_for_vitals'];

        foreach ($vitalRows as $i => $r) {
            $providerId = $i < 3 ? $np->id : $doctor->id; // first 3 destined for NP path
            $patient = $this->upsertPatient($clinic->id, $providerId, $r, 'MRN-R5VT', $i);
            $start = $now->copy()->startOfDay()->addHours(9)->addMinutes(5 + ($i * 18));
            if ($start->lt($now->copy()->subMinutes(5))) {
                $start = $now->copy()->addMinutes(5 + ($i * 7));
            }
            $appt = Appointment::query()->create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'provider_id' => $providerId,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes(25),
                'status' => $vitalStatuses[$i],
                'visit_type' => $i % 2 === 0 ? 'Office visit' : 'Follow-up',
                'room' => 'V'.($i + 1),
                'notes' => '[role5-vital]',
            ]);
            if ($vitalStatuses[$i] === 'ready_for_vitals') {
                $this->seedVital($clinic->id, $patient->id, $appt->id, $nurse->id, 120, 78, 98.6, 72, []);
            }
        }

        // ——— NP: 5 ready_for_np (after vitals, before doctor) ———
        $npRows = [
            ['Wendy', 'Khan', '1990-08-14', 'Female', '5557003001', 'wendy.khan.role5@example.com', 'Aetna', 'AET-R521'],
            ['Xander', 'Lee', '1982-02-27', 'Male', '5557003002', 'xander.lee.role5@example.com', 'Cigna', 'CIG-R522'],
            ['Yara', 'Morris', '1997-10-09', 'Female', '5557003003', 'yara.morris.role5@example.com', 'UnitedHealthcare', 'UHC-R523'],
            ['Zane', 'Ng', '1975-06-01', 'Male', '5557003004', 'zane.ng.role5@example.com', 'Blue Cross', 'BCBS-R524'],
            ['Aria', 'Ortiz', '1994-12-18', 'Female', '5557003005', 'aria.ortiz.role5@example.com', 'Horizon', 'HOR-R525'],
        ];

        foreach ($npRows as $i => $r) {
            $patient = $this->upsertPatient($clinic->id, $np->id, $r, 'MRN-R5NP', $i);
            $this->assignProvider($np, $clinic->id, $patient->id);
            $start = $now->copy()->startOfDay()->addHours(10)->addMinutes(10 + ($i * 22));
            if ($start->lt($now->copy()->subMinutes(5))) {
                $start = $now->copy()->addMinutes(8 + ($i * 9));
            }
            $sys = $i === 0 ? 148 : 122;
            $appt = Appointment::query()->create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'provider_id' => $doctor->id,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes(25),
                'status' => 'ready_for_np',
                'visit_type' => 'NP visit',
                'room' => 'N'.($i + 1),
                'notes' => '[role5-np] vitals done → NP queue',
            ]);
            $alerts = $sys >= 140 ? ['blood_pressure'] : [];
            $this->seedVital($clinic->id, $patient->id, $appt->id, $nurse->id, $sys, 80, 98.8, 74 + $i, $alerts);
            if ($i < 2) {
                Diagnosis::query()->firstOrCreate(
                    ['patient_id' => $patient->id, 'icd10_code' => 'J02.9', 'recorded_by' => $np->id],
                    [
                        'clinic_id' => $clinic->id,
                        'description' => 'Acute pharyngitis, unspecified',
                        'status' => 'active',
                    ]
                );
            }
        }

        // ——— Doctor: 5 ready_for_provider (after vitals; separate from NP) ———
        $drRows = [
            ['Blake', 'Patel', '1988-03-16', 'Male', '5557004001', 'blake.patel.role5@example.com', 'Aetna', 'AET-R531'],
            ['Cora', 'Quinn', '1992-09-25', 'Female', '5557004002', 'cora.quinn.role5@example.com', 'Cigna', 'CIG-R532'],
            ['Drew', 'Rivera', '1979-01-07', 'Male', '5557004003', 'drew.rivera.role5@example.com', 'UnitedHealthcare', 'UHC-R533'],
            ['Elle', 'Singh', '1998-05-21', 'Female', '5557004004', 'elle.singh.role5@example.com', 'Blue Cross', 'BCBS-R534'],
            ['Finn', 'Turner', '1985-11-11', 'Male', '5557004005', 'finn.turner.role5@example.com', 'Horizon', 'HOR-R535'],
        ];

        foreach ($drRows as $i => $r) {
            $patient = $this->upsertPatient($clinic->id, $doctor->id, $r, 'MRN-R5DR', $i);
            $this->assignProvider($doctor, $clinic->id, $patient->id);
            $start = $now->copy()->startOfDay()->addHours(11)->addMinutes(5 + ($i * 20));
            if ($start->lt($now->copy()->subMinutes(5))) {
                $start = $now->copy()->addMinutes(12 + ($i * 10));
            }
            $sys = $i % 2 === 0 ? 146 : 118;
            $tempF = $i === 1 ? 100.8 : 98.4;
            $appt = Appointment::query()->create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'provider_id' => $doctor->id,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes(25),
                'status' => $i === 3 ? 'in_progress' : 'ready_for_provider',
                'visit_type' => 'Office visit',
                'room' => 'B'.($i + 1),
                'notes' => '[role5-doctor] vitals done → Doctor queue',
            ]);
            $tempC = round(($tempF - 32) * 5 / 9, 1);
            $alerts = Vital::detectAlerts([
                'bp_systolic' => $sys,
                'bp_diastolic' => $sys >= 140 ? 92 : 76,
                'temperature_c' => $tempC,
                'pulse' => 76,
                'spo2' => 97,
            ]);
            $this->seedVital(
                $clinic->id,
                $patient->id,
                $appt->id,
                $nurse->id,
                $sys,
                $sys >= 140 ? 92 : 76,
                $tempF,
                76 + $i,
                $alerts
            );
        }

        // ——— Counselor: 5 today sessions ———
        if ($counselor) {
            $cRows = [
                ['Gina', 'Underwood', '1991-04-02', 'Female', '5557005001', 'gina.u.role5@example.com', 'Aetna', 'AET-R541'],
                ['Hank', 'Vargas', '1986-08-13', 'Male', '5557005002', 'hank.v.role5@example.com', 'Cigna', 'CIG-R542'],
                ['Iris', 'Walsh', '1993-12-24', 'Female', '5557005003', 'iris.w.role5@example.com', 'UnitedHealthcare', 'UHC-R543'],
                ['Jake', 'Xu', '1978-07-09', 'Male', '5557005004', 'jake.x.role5@example.com', 'Blue Cross', 'BCBS-R544'],
                ['Kara', 'Young', '1995-02-17', 'Female', '5557005005', 'kara.y.role5@example.com', 'Horizon', 'HOR-R545'],
            ];
            foreach ($cRows as $i => $r) {
                $patient = $this->upsertPatient($clinic->id, $counselor->id, $r, 'MRN-R5CO', $i);
                $this->assignProvider($counselor, $clinic->id, $patient->id);
                $start = $now->copy()->startOfDay()->addHours(13)->addMinutes($i * 30);
                if ($start->lt($now->copy()->subMinutes(5))) {
                    $start = $now->copy()->addMinutes(20 + ($i * 11));
                }
                Appointment::query()->create([
                    'clinic_id' => $clinic->id,
                    'patient_id' => $patient->id,
                    'provider_id' => $counselor->id,
                    'starts_at' => $start,
                    'ends_at' => $start->copy()->addMinutes(45),
                    'status' => $i === 0 ? 'in_progress' : 'ready_for_provider',
                    'visit_type' => 'Counseling',
                    'notes' => '[role5-counselor]',
                ]);
                Diagnosis::query()->firstOrCreate(
                    ['patient_id' => $patient->id, 'icd10_code' => 'F41.1'],
                    [
                        'clinic_id' => $clinic->id,
                        'description' => 'Generalized anxiety disorder',
                        'recorded_by' => $doctor->id,
                        'status' => 'active',
                    ]
                );
            }
        }

        // ——— Billing: 5 payments + 5 claims ———
        if ($billing) {
            $billPatients = Patient::query()
                ->where('clinic_id', $clinic->id)
                ->where('email', 'like', '%.role5@example.com')
                ->orderBy('id')
                ->limit(5)
                ->get();

            foreach ($billPatients->values() as $i => $patient) {
                Payment::query()->create([
                    'clinic_id' => $clinic->id,
                    'patient_id' => $patient->id,
                    'recorded_by' => $desk->id,
                    'amount' => [25, 40, 55, 30, 45][$i],
                    'method' => ['cash', 'card', 'card', 'cash', 'card'][$i],
                    'receipt_number' => 'ROLE5-'.Str::upper(Str::random(6)),
                    'status' => 'completed',
                ]);

                Claim::query()->create([
                    'clinic_id' => $clinic->id,
                    'patient_id' => $patient->id,
                    'created_by' => $billing->id,
                    'clearinghouse_id' => 'ROLE5-CLM-'.($i + 1),
                    'status' => ['submitted', 'accepted', 'denied', 'accepted', 'submitted'][$i],
                    'billed_amount' => [120, 180, 95, 210, 150][$i],
                    'paid_amount' => [0, 144, 0, 168, 0][$i],
                    'x12_payload' => 'ISA*00*...ROLE5...',
                    'denial_codes' => $i === 2 ? ['CO-4'] : null,
                ]);

                BillingCode::query()->firstOrCreate(
                    [
                        'clinic_id' => $clinic->id,
                        'patient_id' => $patient->id,
                        'code' => '99213',
                        'status' => 'confirmed',
                    ],
                    [
                        'code_system' => 'CPT',
                        'description' => 'Office visit [role5-billing]',
                        'source' => 'manual',
                        'confirmed_by' => $billing->id,
                    ]
                );
            }
        }

        $this->command?->info('RoleFiveRecordsSeeder done (today queues):');
        $this->command?->line('  Front Desk  → 5 ([role5-front-desk])');
        $this->command?->line('  Vital Nurse → 5 ([role5-vital])');
        $this->command?->line('  NP          → 5 ready ([role5-np])  ← after vitals');
        $this->command?->line('  Doctor      → 5 ready ([role5-doctor])');
        $this->command?->line('  Counselor   → 5');
        $this->command?->line('  Billing     → 5 payments + claims');
        $this->command?->info('Flow: Desk → Vital → NP → Doctor');
    }

    /** @param  array{0:string,1:string,2:string,3:string,4:string,5:string,6:string,7:string}  $r */
    private function upsertPatient(int $clinicId, int $providerId, array $r, string $mrnPrefix, int $i): Patient
    {
        [$first, $last, $dob, $gender, $phone, $email, $payer, $policy] = $r;

        $patient = Patient::query()->updateOrCreate(
            [
                'clinic_id' => $clinicId,
                'email' => $email,
            ],
            [
                'mrn' => $mrnPrefix.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                'first_name' => $first,
                'last_name' => $last,
                'date_of_birth' => $dob,
                'gender' => $gender,
                'phone' => $phone,
                'address' => 'Albany, NY 12207',
                'primary_provider_id' => $providerId,
                'emergency_contact' => [
                    'name' => 'Emergency '.$last,
                    'phone' => '5559990000',
                    'relation' => 'Spouse',
                ],
            ]
        );

        PatientInsurance::query()->updateOrCreate(
            [
                'clinic_id' => $clinicId,
                'patient_id' => $patient->id,
                'type' => 'primary',
            ],
            [
                'payer_name' => $payer,
                'policy_number' => $policy,
                'group_number' => 'GRP-ROLE5',
            ]
        );

        return $patient;
    }

    private function assignProvider(User $provider, int $clinicId, int $patientId): void
    {
        if (! $provider->assignedPatients()->where('patients.id', $patientId)->exists()) {
            $provider->assignedPatients()->attach($patientId, ['clinic_id' => $clinicId]);
        }
    }

    /** @param  list<string>  $alerts */
    private function seedVital(
        int $clinicId,
        int $patientId,
        int $appointmentId,
        int $recordedBy,
        int $sys,
        int $dia,
        float $tempF,
        int $pulse,
        array $alerts
    ): void {
        $tempC = round(($tempF - 32) * 5 / 9, 1);
        Vital::query()->create([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'appointment_id' => $appointmentId,
            'recorded_by' => $recordedBy,
            'height_cm' => 170,
            'weight_kg' => 70,
            'bmi' => 24.22,
            'temperature_c' => $tempC,
            'bp_systolic' => $sys,
            'bp_diastolic' => $dia,
            'pulse' => $pulse,
            'respiratory_rate' => 16,
            'spo2' => 98,
            'pain_scale' => 1,
            'glucose' => 95,
            'alerts' => $alerts,
        ]);
    }
}
