<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\BillingCode;
use App\Models\Claim;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vital;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Schedules patients from 2026-09-04 for 15 days so every role screen has records.
 *
 * Tags: [15day-desk] [15day-vital] [15day-doctor] [15day-np] [15day-counselor] [15day-billing]
 */
class FifteenDayScheduleSeeder extends Seeder
{
    private const START = '2026-09-04';

    private const DAYS = 15;

    public function run(): void
    {
        $clinic = Clinic::query()->where('slug', 'demo-clinic')->first();
        $desk = User::query()->where('email', 'desk@demo.local')->first();
        $nurse = User::query()->where('email', 'vitals@demo.local')->first();
        $np = User::query()->where('email', 'np@demo.local')->first();
        $doctor = User::query()->where('email', 'doctor@demo.local')->first();
        $counselor = User::query()->where('email', 'counselor@demo.local')->first();
        $billing = User::query()->where('email', 'billing@demo.local')->first();

        if (! $clinic || ! $doctor || ! $np || ! $nurse || ! $desk) {
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
        $startDay = Carbon::parse(self::START, $tz)->startOfDay();

        Appointment::query()
            ->where('clinic_id', $clinic->id)
            ->where('notes', 'like', '%[15day-%')
            ->delete();

        Claim::query()
            ->where('clinic_id', $clinic->id)
            ->where('clearinghouse_id', 'like', '15DAY-%')
            ->delete();

        Payment::query()
            ->where('clinic_id', $clinic->id)
            ->where('receipt_number', 'like', '15DAY-%')
            ->delete();

        Vital::query()
            ->where('clinic_id', $clinic->id)
            ->where('notes', 'like', '%[15day-vital]%')
            ->delete();

        $deskNames = [
            ['Riley', 'Adams'], ['Kai', 'Bennett'], ['Nora', 'Collins'], ['Owen', 'Diaz'], ['Piper', 'Evans'],
        ];
        $vitalNames = [
            ['Quinn', 'Foster'], ['Sage', 'Green'], ['Tate', 'Hayes'], ['Uma', 'Iyer'], ['Vera', 'Jones'],
        ];
        $doctorNames = [
            ['Blake', 'Patel'], ['Cora', 'Quinn'], ['Drew', 'Rivera'], ['Elle', 'Singh'], ['Finn', 'Turner'],
        ];
        $npNames = [
            ['Wendy', 'Khan'], ['Xander', 'Lee'], ['Yara', 'Morris'], ['Zane', 'Ng'], ['Aria', 'Ortiz'],
        ];
        $counselorNames = [
            ['Gina', 'Underwood'], ['Hank', 'Vargas'], ['Iris', 'Walsh'], ['Jake', 'Xu'], ['Kara', 'Young'],
        ];

        $created = 0;

        for ($day = 0; $day < self::DAYS; $day++) {
            $dayBase = $startDay->copy()->addDays($day);

            // Front Desk — scheduled / waiting (clinic-wide queue)
            foreach ($deskNames as $i => $name) {
                $patient = $this->upsertPatient(
                    $clinic->id,
                    $i % 2 === 0 ? $doctor->id : $np->id,
                    $name[0],
                    $name[1],
                    "desk.d{$day}.{$i}@15day.demo",
                    'MRN-15FD',
                    $day,
                    $i,
                    'Aetna',
                    'AET-15FD'.$day.$i
                );
                $start = $dayBase->copy()->addHours(8)->addMinutes(10 + ($i * 18));
                Appointment::query()->create([
                    'clinic_id' => $clinic->id,
                    'patient_id' => $patient->id,
                    'provider_id' => $i % 2 === 0 ? $doctor->id : $np->id,
                    'starts_at' => $start,
                    'ends_at' => $start->copy()->addMinutes(25),
                    'status' => $i % 2 === 0 ? 'scheduled' : 'waiting',
                    'visit_type' => 'Office visit',
                    'room' => 'D'.($i + 1),
                    'notes' => '[15day-desk] day '.$day,
                ]);
                $created++;
            }

            // Vital Nurse — waiting / ready_for_vitals
            foreach ($vitalNames as $i => $name) {
                $providerId = $i < 3 ? $np->id : $doctor->id;
                $patient = $this->upsertPatient(
                    $clinic->id,
                    $providerId,
                    $name[0],
                    $name[1],
                    "vital.d{$day}.{$i}@15day.demo",
                    'MRN-15VT',
                    $day,
                    $i,
                    'Cigna',
                    'CIG-15VT'.$day.$i
                );
                $start = $dayBase->copy()->addHours(9)->addMinutes(5 + ($i * 16));
                $status = $i % 2 === 0 ? 'waiting' : 'ready_for_vitals';
                $appt = Appointment::query()->create([
                    'clinic_id' => $clinic->id,
                    'patient_id' => $patient->id,
                    'provider_id' => $providerId,
                    'starts_at' => $start,
                    'ends_at' => $start->copy()->addMinutes(25),
                    'status' => $status,
                    'visit_type' => 'Office visit',
                    'room' => 'V'.($i + 1),
                    'notes' => '[15day-vital] day '.$day,
                ]);
                if ($status === 'ready_for_vitals') {
                    $this->seedVital($clinic->id, $patient->id, $appt->id, $nurse->id, 122, 78, 98.6, 72);
                }
                $created++;
            }

            // Doctor schedule + ready queue
            foreach ($doctorNames as $i => $name) {
                $patient = $this->upsertPatient(
                    $clinic->id,
                    $doctor->id,
                    $name[0],
                    $name[1],
                    "doctor.d{$day}.{$i}@15day.demo",
                    'MRN-15DR',
                    $day,
                    $i,
                    'UnitedHealthcare',
                    'UHC-15DR'.$day.$i
                );
                $this->assignProvider($doctor, $clinic->id, $patient->id);
                $start = $dayBase->copy()->addHours(10)->addMinutes(10 + ($i * 20));
                $sys = $i % 2 === 0 ? 146 : 118;
                $appt = Appointment::query()->create([
                    'clinic_id' => $clinic->id,
                    'patient_id' => $patient->id,
                    'provider_id' => $doctor->id,
                    'starts_at' => $start,
                    'ends_at' => $start->copy()->addMinutes(25),
                    'status' => $i === 0 ? 'in_progress' : 'ready_for_provider',
                    'visit_type' => 'Office visit',
                    'room' => 'B'.($i + 1),
                    'notes' => '[15day-doctor] day '.$day,
                ]);
                $this->seedVital(
                    $clinic->id,
                    $patient->id,
                    $appt->id,
                    $nurse->id,
                    $sys,
                    $sys >= 140 ? 92 : 76,
                    98.4,
                    74 + $i
                );
                $created++;
            }

            // NP schedule + ready queue
            foreach ($npNames as $i => $name) {
                $patient = $this->upsertPatient(
                    $clinic->id,
                    $np->id,
                    $name[0],
                    $name[1],
                    "np.d{$day}.{$i}@15day.demo",
                    'MRN-15NP',
                    $day,
                    $i,
                    'Blue Cross',
                    'BCBS-15NP'.$day.$i
                );
                $this->assignProvider($np, $clinic->id, $patient->id);
                $start = $dayBase->copy()->addHours(11)->addMinutes(5 + ($i * 18));
                $appt = Appointment::query()->create([
                    'clinic_id' => $clinic->id,
                    'patient_id' => $patient->id,
                    'provider_id' => $np->id,
                    'starts_at' => $start,
                    'ends_at' => $start->copy()->addMinutes(25),
                    'status' => $i === 1 ? 'in_progress' : 'ready_for_provider',
                    'visit_type' => 'NP visit',
                    'room' => 'N'.($i + 1),
                    'notes' => '[15day-np] day '.$day,
                ]);
                $this->seedVital($clinic->id, $patient->id, $appt->id, $nurse->id, 124, 80, 98.7, 70 + $i);
                $created++;
            }

            // Counselor sessions
            if ($counselor) {
                foreach ($counselorNames as $i => $name) {
                    $patient = $this->upsertPatient(
                        $clinic->id,
                        $counselor->id,
                        $name[0],
                        $name[1],
                        "counselor.d{$day}.{$i}@15day.demo",
                        'MRN-15CO',
                        $day,
                        $i,
                        'Horizon',
                        'HOR-15CO'.$day.$i
                    );
                    $this->assignProvider($counselor, $clinic->id, $patient->id);
                    $start = $dayBase->copy()->addHours(13)->addMinutes($i * 30);
                    Appointment::query()->create([
                        'clinic_id' => $clinic->id,
                        'patient_id' => $patient->id,
                        'provider_id' => $counselor->id,
                        'starts_at' => $start,
                        'ends_at' => $start->copy()->addMinutes(45),
                        'status' => $i === 0 ? 'in_progress' : 'ready_for_provider',
                        'visit_type' => 'Counseling',
                        'notes' => '[15day-counselor] day '.$day,
                    ]);
                    $created++;
                }
            }

            // Billing — one payment + claim per day (uses first doctor patient)
            if ($billing) {
                $billPatient = Patient::query()
                    ->where('clinic_id', $clinic->id)
                    ->where('email', "doctor.d{$day}.0@15day.demo")
                    ->first();
                if ($billPatient) {
                    Payment::query()->create([
                        'clinic_id' => $clinic->id,
                        'patient_id' => $billPatient->id,
                        'recorded_by' => $desk->id,
                        'amount' => 85 + $day,
                        'method' => $day % 2 === 0 ? 'card' : 'cash',
                        'receipt_number' => '15DAY-PAY-'.str_pad((string) ($day + 1), 2, '0', STR_PAD_LEFT),
                        'status' => 'completed',
                    ]);
                    Claim::query()->create([
                        'clinic_id' => $clinic->id,
                        'patient_id' => $billPatient->id,
                        'created_by' => $billing->id,
                        'clearinghouse_id' => '15DAY-CLM-'.str_pad((string) ($day + 1), 2, '0', STR_PAD_LEFT),
                        'status' => $day % 3 === 0 ? 'submitted' : 'draft',
                        'billed_amount' => 125 + ($day * 5),
                        'paid_amount' => $day % 3 === 0 ? 0 : 100,
                        'x12_payload' => 'ISA*00*...15DAY...',
                        'denial_codes' => null,
                    ]);
                    BillingCode::query()->updateOrCreate(
                        [
                            'clinic_id' => $clinic->id,
                            'patient_id' => $billPatient->id,
                            'code' => '99213',
                            'status' => 'confirmed',
                        ],
                        [
                            'code_system' => 'CPT',
                            'description' => 'Office visit [15day-billing]',
                            'source' => 'manual',
                            'confirmed_by' => $billing->id,
                        ]
                    );
                }
            }
        }

        $endDay = $startDay->copy()->addDays(self::DAYS - 1)->toDateString();
        $this->command?->info("FifteenDayScheduleSeeder done: {$created} appointments");
        $this->command?->line('  Range: '.$startDay->toDateString().' → '.$endDay);
        $this->command?->line('  Per day: desk 5 · vital 5 · doctor 5 · np 5 · counselor 5');
        $this->command?->line('  Visible on Front Desk / Vital Nurse / Doctor / NP / Counselor / Billing screens');
    }

    private function upsertPatient(
        int $clinicId,
        int $providerId,
        string $first,
        string $last,
        string $email,
        string $mrnPrefix,
        int $day,
        int $i,
        string $payer,
        string $policy
    ): Patient {
        $patient = Patient::query()->updateOrCreate(
            [
                'clinic_id' => $clinicId,
                'email' => $email,
            ],
            [
                'mrn' => $mrnPrefix.str_pad((string) ($day + 1), 2, '0', STR_PAD_LEFT).str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                'first_name' => $first,
                'last_name' => $last.' D'.($day + 1),
                'date_of_birth' => Carbon::parse('1985-01-01')->addDays(($day * 5) + $i)->toDateString(),
                'gender' => $i % 2 === 0 ? 'Female' : 'Male',
                'phone' => '555'.str_pad((string) (7000000 + ($day * 10) + $i), 7, '0', STR_PAD_LEFT),
                'address' => 'Albany, NY 12207',
                'primary_provider_id' => $providerId,
                'emergency_contact' => [
                    'name' => 'Emergency '.$last,
                    'phone' => '5559990000',
                    'relation' => 'Spouse',
                    'pcp_name' => 'Dr. Monk',
                ],
                'allergies' => $i === 0 ? 'Penicillin' : null,
                'active_medications' => $i === 1 ? 'Lisinopril 10mg daily' : null,
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
                'group_number' => 'GRP-15DAY',
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

    private function seedVital(
        int $clinicId,
        int $patientId,
        int $appointmentId,
        int $recordedBy,
        int $sys,
        int $dia,
        float $tempF,
        int $pulse
    ): void {
        $tempC = round(($tempF - 32) * 5 / 9, 1);
        $alerts = Vital::detectAlerts([
            'bp_systolic' => $sys,
            'bp_diastolic' => $dia,
            'temperature_c' => $tempC,
            'pulse' => $pulse,
            'spo2' => 97,
        ]);

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
            'spo2' => 97,
            'pain_scale' => 2,
            'alerts' => $alerts,
            'notes' => '[15day-vital]',
        ]);
    }
}
