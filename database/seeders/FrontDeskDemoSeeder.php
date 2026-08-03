<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicMessageLog;
use App\Models\ClinicNotification;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Payment;
use App\Models\User;
use App\Support\Roles;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class FrontDeskDemoSeeder extends Seeder
{
    public function run(): void
    {
        $clinic = Clinic::query()->where('slug', 'demo-clinic')->first();
        if (! $clinic) {
            $this->command?->warn('Demo clinic missing — run DatabaseSeeder first.');

            return;
        }

        $doctor = User::query()->where('email', 'doctor@demo.local')->first();
        $np = User::query()->where('email', 'np@demo.local')->first();
        $desk = User::query()->where('email', 'desk@demo.local')->first();
        if (! $doctor || ! $desk) {
            $this->command?->warn('Demo users missing.');

            return;
        }

        $tz = $clinic->timezone ?: 'America/New_York';
        $now = Carbon::now($tz);

        $patientsSpec = [
            ['first' => 'Maya', 'last' => 'Chen', 'dob' => '1990-03-14', 'gender' => 'Female', 'phone' => '(212) 555-0101', 'email' => 'maya.chen@example.com', 'city' => 'New York', 'state' => 'NY', 'zip' => '10001', 'payer' => 'Aetna', 'policy' => 'AET-1001'],
            ['first' => 'Jordan', 'last' => 'Brooks', 'dob' => '1985-07-22', 'gender' => 'Male', 'phone' => '(212) 555-0102', 'email' => 'jordan.brooks@example.com', 'city' => 'Brooklyn', 'state' => 'NY', 'zip' => '11201', 'payer' => 'UnitedHealthcare', 'policy' => 'UHC-2201'],
            ['first' => 'Sofia', 'last' => 'Martinez', 'dob' => '1998-11-02', 'gender' => 'Female', 'phone' => '(718) 555-0103', 'email' => 'sofia.m@example.com', 'city' => 'Queens', 'state' => 'NY', 'zip' => '11375', 'payer' => 'Blue Cross Blue Shield', 'policy' => 'BCBS-3301'],
            ['first' => 'Liam', 'last' => 'Nguyen', 'dob' => '1979-01-30', 'gender' => 'Male', 'phone' => '(917) 555-0104', 'email' => 'liam.nguyen@example.com', 'city' => 'New York', 'state' => 'NY', 'zip' => '10011', 'payer' => 'Cigna', 'policy' => 'CIG-4401'],
            ['first' => 'Ava', 'last' => 'Patel', 'dob' => '2001-05-18', 'gender' => 'Female', 'phone' => '(646) 555-0105', 'email' => 'ava.patel@example.com', 'city' => 'Jersey City', 'state' => 'NJ', 'zip' => '07302', 'payer' => 'Horizon', 'policy' => 'HOR-5501'],
            ['first' => 'Noah', 'last' => 'Williams', 'dob' => '1993-09-09', 'gender' => 'Male', 'phone' => '(201) 555-0106', 'email' => 'noah.w@example.com', 'city' => 'Hoboken', 'state' => 'NJ', 'zip' => '07030', 'payer' => 'Aetna', 'policy' => 'AET-6601'],
            ['first' => 'Emma', 'last' => 'Johnson', 'dob' => '1988-12-25', 'gender' => 'Female', 'phone' => '(203) 555-0107', 'email' => 'emma.j@example.com', 'city' => 'Stamford', 'state' => 'CT', 'zip' => '06901', 'payer' => 'Anthem', 'policy' => 'ANT-7701'],
            ['first' => 'Ethan', 'last' => 'Garcia', 'dob' => '1975-04-04', 'gender' => 'Male', 'phone' => '(914) 555-0108', 'email' => 'ethan.g@example.com', 'city' => 'White Plains', 'state' => 'NY', 'zip' => '10601', 'payer' => 'Medicare', 'policy' => 'MCR-8801'],
            ['first' => 'Olivia', 'last' => 'Kim', 'dob' => '1996-08-16', 'gender' => 'Female', 'phone' => '(516) 555-0109', 'email' => 'olivia.kim@example.com', 'city' => 'Hempstead', 'state' => 'NY', 'zip' => '11550', 'payer' => 'EmblemHealth', 'policy' => 'EMB-9901'],
            ['first' => 'Lucas', 'last' => 'Brown', 'dob' => '1982-02-11', 'gender' => 'Male', 'phone' => '(631) 555-0110', 'email' => 'lucas.brown@example.com', 'city' => 'Huntington', 'state' => 'NY', 'zip' => '11743', 'payer' => 'Oxford', 'policy' => 'OXF-1010'],
            ['first' => 'Isabella', 'last' => 'Davis', 'dob' => '1994-06-07', 'gender' => 'Female', 'phone' => '(845) 555-0111', 'email' => 'isabella.d@example.com', 'city' => 'Yonkers', 'state' => 'NY', 'zip' => '10701', 'payer' => 'Aetna', 'policy' => 'AET-1111'],
            ['first' => 'Mason', 'last' => 'Wilson', 'dob' => '1968-10-21', 'gender' => 'Male', 'phone' => '(973) 555-0112', 'email' => 'mason.w@example.com', 'city' => 'Newark', 'state' => 'NJ', 'zip' => '07102', 'payer' => 'Cigna', 'policy' => 'CIG-1212'],
        ];

        $patients = [];
        foreach ($patientsSpec as $i => $row) {
            $patient = Patient::query()->updateOrCreate(
                [
                    'clinic_id' => $clinic->id,
                    'email' => $row['email'],
                ],
                [
                    'mrn' => 'MRN-DEMO'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                    'first_name' => $row['first'],
                    'last_name' => $row['last'],
                    'date_of_birth' => $row['dob'],
                    'gender' => $row['gender'],
                    'phone' => $row['phone'],
                    'address' => "{$row['city']}, {$row['state']} {$row['zip']}",
                    'primary_provider_id' => $i % 2 === 0 ? $doctor->id : ($np?->id ?? $doctor->id),
                    'emergency_contact' => [
                        'name' => 'Emergency '.$row['last'],
                        'phone' => '(212) 555-0199',
                        'relation' => 'Spouse',
                    ],
                ]
            );

            PatientInsurance::query()->updateOrCreate(
                [
                    'patient_id' => $patient->id,
                    'type' => 'primary',
                ],
                [
                    'clinic_id' => $clinic->id,
                    'payer_name' => $row['payer'],
                    'policy_number' => $row['policy'],
                    'group_number' => 'GRP-'.($i + 1),
                ]
            );

            $patients[] = $patient;
        }

        // Clear today's demo appointments so re-seed stays predictable
        Appointment::query()
            ->where('clinic_id', $clinic->id)
            ->whereDate('starts_at', $now->toDateString())
            ->where('notes', 'like', '%[demo-front-desk]%')
            ->delete();

        $statuses = ['scheduled', 'scheduled', 'waiting', 'scheduled', 'waiting', 'scheduled', 'scheduled', 'ready_for_vitals', 'scheduled', 'scheduled'];
        $providers = [$doctor->id, $np?->id ?? $doctor->id];

        foreach (array_slice($patients, 0, 10) as $i => $patient) {
            $start = $now->copy()->startOfDay()->addHours(8)->addMinutes($i * 30);
            if ($start->lt($now->copy()->subHours(2))) {
                $start = $now->copy()->addMinutes(15 + ($i * 12));
            }

            Appointment::query()->create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'provider_id' => $providers[$i % 2],
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes(30),
                'status' => $statuses[$i] ?? 'scheduled',
                'visit_type' => $i % 3 === 0 ? 'Follow-up' : 'Office visit',
                'notes' => '[demo-front-desk]',
            ]);
        }

        // Two more this week
        foreach ([1, 2] as $dayOffset) {
            $p = $patients[10 + ($dayOffset - 1)] ?? $patients[0];
            $start = $now->copy()->addDays($dayOffset)->setTime(10, 0);
            Appointment::query()->updateOrCreate(
                [
                    'clinic_id' => $clinic->id,
                    'patient_id' => $p->id,
                    'starts_at' => $start,
                ],
                [
                    'provider_id' => $doctor->id,
                    'ends_at' => $start->copy()->addMinutes(30),
                    'status' => 'scheduled',
                    'visit_type' => 'Office visit',
                    'notes' => '[demo-front-desk]',
                ]
            );
        }

        if (Payment::query()->where('clinic_id', $clinic->id)->count() < 3) {
            foreach (array_slice($patients, 0, 4) as $i => $patient) {
                Payment::query()->create([
                    'clinic_id' => $clinic->id,
                    'patient_id' => $patient->id,
                    'recorded_by' => $desk->id,
                    'amount' => [25.00, 40.00, 15.00, 50.00][$i],
                    'method' => ['cash', 'card', 'cash', 'card'][$i],
                    'receipt_number' => 'RCPT-DEMO-'.Str::upper(Str::random(6)),
                    'status' => 'completed',
                ]);
            }
        }

        if (ClinicMessageLog::query()->where('clinic_id', $clinic->id)->count() < 2) {
            foreach (array_slice($patients, 0, 2) as $i => $patient) {
                ClinicMessageLog::query()->create([
                    'clinic_id' => $clinic->id,
                    'patient_id' => $patient->id,
                    'sent_by' => $desk->id,
                    'channel' => $i === 0 ? 'email' : 'sms',
                    'template_key' => 'appointment_reminder',
                    'recipient_hint' => $i === 0 ? 'm***@example.com' : '***-0101',
                    'body' => 'Reminder: your appointment is coming up. Reply STOP to opt out.',
                    'status' => 'sent',
                ]);
            }
        }

        if (! ClinicNotification::query()->where('user_id', $desk->id)->exists()) {
            ClinicNotification::query()->create([
                'clinic_id' => $clinic->id,
                'user_id' => $desk->id,
                'type' => 'desk.tip',
                'title' => 'Front Desk tip',
                'body' => 'Check today’s queue regularly — late scheduled visits appear in Alerts.',
                'data' => [],
            ]);
            ClinicNotification::query()->create([
                'clinic_id' => $clinic->id,
                'user_id' => $desk->id,
                'type' => 'desk.insurance',
                'title' => 'Insurance reminder',
                'body' => 'Verify insurance before check-in when the Alerts card is above zero.',
                'data' => [],
            ]);
        }

        if ($doctor && ! ClinicNotification::query()->where('user_id', $doctor->id)->where('type', 'vitals.ready')->exists()) {
            ClinicNotification::query()->create([
                'clinic_id' => $clinic->id,
                'user_id' => $doctor->id,
                'type' => 'vitals.ready',
                'title' => 'Patient ready for provider',
                'body' => 'Vitals complete — patient is ready in queue.',
                'data' => ['status' => 'ready_for_provider'],
            ]);
        }

        $this->command?->info('Front Desk demo: '.count($patients).' patients + today queue seeded.');
    }
}
