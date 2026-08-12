<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\User;
use App\Models\Vital;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * 4 Front Desk visits for TODAY → vitals done → ready for Doctor queue / alert testing.
 */
class DoctorTodayFlowSeeder extends Seeder
{
    public function run(): void
    {
        $clinic = Clinic::query()->where('slug', 'demo-clinic')->first();
        $doctor = User::query()->where('email', 'doctor@demo.local')->first();
        $desk = User::query()->where('email', 'desk@demo.local')->first();
        $nurse = User::query()->where('email', 'vitals@demo.local')->first();

        if (! $clinic || ! $doctor || ! $desk) {
            $this->command?->warn('Demo clinic / doctor / desk missing.');

            return;
        }

        $doctor->forceFill([
            'can_prescribe' => true,
            'license_state' => config('drmonk.default_license_state', 'NY'),
            'npi' => $doctor->npi ?: '1987654321',
        ])->save();

        $tz = $clinic->timezone ?: 'America/New_York';
        $now = Carbon::now($tz);

        Appointment::query()
            ->where('clinic_id', $clinic->id)
            ->where('notes', 'like', '%[doctor-today-flow]%')
            ->delete();

        // Include elevated BP so vital alerts also appear on doctor dashboard
        $rows = [
            ['Olivia', 'Chen', '1990-04-12', 'Female', '5556111001', 'olivia.chen.drtoday@example.com', 'Aetna', 'AET-DR01', 148, 94, 99.2, 88],
            ['James', 'Patel', '1985-08-30', 'Male', '5556111002', 'james.patel.drtoday@example.com', 'Cigna', 'CIG-DR02', 126, 80, 98.6, 72],
            ['Sofia', 'Martinez', '1997-01-18', 'Female', '5556111003', 'sofia.martinez.drtoday@example.com', 'UnitedHealthcare', 'UHC-DR03', 142, 90, 100.4, 96],
            ['William', 'Nguyen', '1978-12-05', 'Male', '5556111004', 'william.nguyen.drtoday@example.com', 'Blue Cross Blue Shield', 'BCBS-DR04', 118, 74, 98.2, 68],
        ];

        $created = [];

        foreach ($rows as $i => $r) {
            [$first, $last, $dob, $gender, $phone, $email, $payer, $policy, $sys, $dia, $tempF, $pulse] = $r;

            $patient = Patient::query()->updateOrCreate(
                [
                    'clinic_id' => $clinic->id,
                    'email' => $email,
                ],
                [
                    'mrn' => 'MRN-DRTODAY'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                    'first_name' => $first,
                    'last_name' => $last,
                    'date_of_birth' => $dob,
                    'gender' => $gender,
                    'phone' => $phone,
                    'address' => 'Albany, NY 12207',
                    'primary_provider_id' => $doctor->id,
                    'emergency_contact' => [
                        'name' => 'Emergency '.$last,
                        'phone' => '5559990000',
                        'relation' => 'Spouse',
                    ],
                ]
            );

            PatientInsurance::query()->updateOrCreate(
                [
                    'clinic_id' => $clinic->id,
                    'patient_id' => $patient->id,
                    'type' => 'primary',
                ],
                [
                    'payer_name' => $payer,
                    'policy_number' => $policy,
                    'group_number' => 'GRP-DR-TODAY',
                ]
            );

            if (! $doctor->assignedPatients()->where('patients.id', $patient->id)->exists()) {
                $doctor->assignedPatients()->attach($patient->id, ['clinic_id' => $clinic->id]);
            }

            $start = $now->copy()->startOfDay()->addHours(9)->addMinutes(10 + ($i * 30));
            if ($start->lt($now->copy()->subMinutes(5))) {
                $start = $now->copy()->addMinutes(5 + ($i * 10));
            }

            $appt = Appointment::query()->create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'provider_id' => $doctor->id,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes(25),
                'status' => 'ready_for_provider',
                'visit_type' => 'Office visit',
                'notes' => '[doctor-today-flow] Front Desk check-in + vitals done',
                'room' => 'B'.($i + 1),
            ]);

            $tempC = round(($tempF - 32) * 5 / 9, 1);
            $alerts = Vital::detectAlerts([
                'bp_systolic' => $sys,
                'bp_diastolic' => $dia,
                'temperature_c' => $tempC,
                'pulse' => $pulse,
                'spo2' => 97,
                'respiratory_rate' => 16,
            ]);

            Vital::query()->create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'appointment_id' => $appt->id,
                'recorded_by' => $nurse?->id ?? $desk->id,
                'height_cm' => 168 + $i,
                'weight_kg' => 70 + ($i * 2),
                'bmi' => round((70 + ($i * 2)) / (((168 + $i) / 100) ** 2), 2),
                'temperature_c' => $tempC,
                'bp_systolic' => $sys,
                'bp_diastolic' => $dia,
                'pulse' => $pulse,
                'respiratory_rate' => 16,
                'spo2' => 97 + ($i % 2),
                'pain_scale' => $i % 3,
                'glucose' => 100 + $i,
                'alerts' => $alerts,
            ]);

            $alertNote = $alerts ? ' · ALERTS' : '';
            $created[] = "{$first} {$last} · {$patient->mrn} · {$start->format('g:i A')} · {$appt->status}{$alertNote}";
        }

        $this->command?->info('Doctor today flow: 4 Front Desk + vitals → ready_for_provider');
        foreach ($created as $line) {
            $this->command?->line('  - '.$line);
        }
        $this->command?->info('Login Doctor: doctor@demo.local / password → Ready queue / vital alerts');
    }
}
