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
 * 5 Front Desk visits for TODAY → vitals done → ready for NP queue testing.
 */
class NpTodayFlowSeeder extends Seeder
{
    public function run(): void
    {
        $clinic = Clinic::query()->where('slug', 'demo-clinic')->first();
        $np = User::query()->where('email', 'np@demo.local')->first();
        $desk = User::query()->where('email', 'desk@demo.local')->first();
        $nurse = User::query()->where('email', 'vitals@demo.local')->first();

        if (! $clinic || ! $np || ! $desk) {
            $this->command?->warn('Demo clinic / NP / desk missing.');

            return;
        }

        $np->forceFill([
            'can_prescribe' => true,
            'license_state' => config('drmonk.default_license_state', 'NY'),
            'npi' => $np->npi ?: '1234567890',
        ])->save();

        $tz = $clinic->timezone ?: 'America/New_York';
        $now = Carbon::now($tz);

        Appointment::query()
            ->where('clinic_id', $clinic->id)
            ->where('notes', 'like', '%[np-today-flow]%')
            ->delete();

        $rows = [
            ['Liam', 'Parker', '1988-09-22', 'Male', '5552345678', 'liam.parker.today@example.com', 'UnitedHealthcare', 'UHC445566', 128, 82, 98.6, 74],
            ['Ava', 'Thompson', '1992-05-14', 'Female', '5551234567', 'ava.thompson.today@example.com', 'Aetna', 'AET998877', 122, 78, 99.1, 78],
            ['Noah', 'Reed', '1979-11-03', 'Male', '5553456789', 'noah.reed.today@example.com', 'Cigna', 'CIG112233', 138, 88, 98.4, 82],
            ['Mia', 'Lopez', '1995-02-28', 'Female', '5554567890', 'mia.lopez.today@example.com', 'Blue Cross Blue Shield', 'BCBS778899', 118, 76, 98.9, 70],
            ['Ethan', 'Brooks', '1984-07-19', 'Male', '5555678901', 'ethan.brooks.today@example.com', 'Horizon', 'HOR334455', 130, 84, 99.0, 76],
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
                    'mrn' => 'MRN-NPTODAY'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                    'first_name' => $first,
                    'last_name' => $last,
                    'date_of_birth' => $dob,
                    'gender' => $gender,
                    'phone' => $phone,
                    'address' => 'Albany, NY 12207',
                    'primary_provider_id' => $np->id,
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
                    'group_number' => 'GRP-TODAY',
                ]
            );

            if (! $np->assignedPatients()->where('patients.id', $patient->id)->exists()) {
                $np->assignedPatients()->attach($patient->id, ['clinic_id' => $clinic->id]);
            }

            // Spread across morning clinic hours today
            $start = $now->copy()->startOfDay()->addHours(9)->addMinutes(15 + ($i * 25));
            if ($start->lt($now->copy()->subMinutes(5))) {
                $start = $now->copy()->addMinutes(5 + ($i * 8));
            }

            $appt = Appointment::query()->create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'provider_id' => $np->id,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes(25),
                'status' => 'ready_for_provider',
                'visit_type' => 'Office visit',
                'notes' => '[np-today-flow] Front Desk check-in + vitals done',
                'room' => 'A'.($i + 1),
            ]);

            // °F → °C for storage
            $tempC = round(($tempF - 32) * 5 / 9, 1);
            $alerts = [];
            if ($sys >= 140 || $dia >= 90) {
                $alerts[] = 'blood_pressure';
            }
            if ($tempC >= 38.0) {
                $alerts[] = 'high_temperature';
            }

            Vital::query()->create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'appointment_id' => $appt->id,
                'recorded_by' => $nurse?->id ?? $desk->id,
                'height_cm' => 170 + $i,
                'weight_kg' => 68 + ($i * 3),
                'bmi' => round((68 + ($i * 3)) / (((170 + $i) / 100) ** 2), 2),
                'temperature_c' => $tempC,
                'bp_systolic' => $sys,
                'bp_diastolic' => $dia,
                'pulse' => $pulse,
                'respiratory_rate' => 16,
                'spo2' => 97 + ($i % 2),
                'pain_scale' => $i % 4,
                'glucose' => 95 + $i,
                'alerts' => $alerts,
            ]);

            $created[] = "{$first} {$last} · {$patient->mrn} · {$start->format('g:i A')} · {$appt->status}";
        }

        $this->command?->info('NP today flow: 5 Front Desk + vitals → ready_for_provider');
        foreach ($created as $line) {
            $this->command?->line('  - '.$line);
        }
        $this->command?->info('Login NP: np@demo.local / password → Open chart');
    }
}
