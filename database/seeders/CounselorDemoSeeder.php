<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Assessment;
use App\Models\BillingCode;
use App\Models\Clinic;
use App\Models\CounselingSession;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class CounselorDemoSeeder extends Seeder
{
    public function run(): void
    {
        $clinic = Clinic::query()->where('slug', 'demo-clinic')->first();
        $counselor = User::query()->where('email', 'counselor@demo.local')->first();
        $doctor = User::query()->where('email', 'doctor@demo.local')->first();

        if (! $clinic || ! $counselor) {
            $this->command?->warn('Demo clinic/counselor missing.');

            return;
        }

        $tz = $clinic->timezone ?: 'America/New_York';
        $now = Carbon::now($tz);

        Appointment::query()
            ->where('clinic_id', $clinic->id)
            ->where('notes', 'like', '%[demo-counselor]%')
            ->delete();

        $patients = Patient::query()
            ->where('clinic_id', $clinic->id)
            ->orderBy('id')
            ->limit(12)
            ->get();

        if ($patients->count() < 10) {
            $this->command?->warn('Need FrontDeskDemoSeeder patients first.');

            return;
        }

        $statuses = [
            'ready_for_provider', 'waiting', 'in_progress', 'ready_for_provider',
            'ready_for_provider', 'waiting', 'ready_for_provider', 'in_progress',
            'ready_for_provider', 'ready_for_provider',
        ];
        $dxCodes = [
            ['F41.1', 'Generalized anxiety disorder'],
            ['F32.1', 'Major depressive disorder, single episode, moderate'],
            ['F43.10', 'Post-traumatic stress disorder, unspecified'],
        ];

        foreach ($patients->take(10)->values() as $i => $patient) {
            $start = $now->copy()->startOfDay()->addHours(12)->addMinutes($i * 30);
            if ($start->lt($now->copy()->subMinutes(15))) {
                $start = $now->copy()->addMinutes(10 + ($i * 7));
            }

            $appt = Appointment::query()->create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'provider_id' => $counselor->id,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes(45),
                'status' => $statuses[$i] ?? 'ready_for_provider',
                'visit_type' => $i % 2 === 0 ? 'Individual therapy' : 'Follow-up counseling',
                'notes' => '[demo-counselor]',
            ]);

            $dx = $dxCodes[$i % count($dxCodes)];
            Diagnosis::query()->firstOrCreate(
                [
                    'patient_id' => $patient->id,
                    'icd10_code' => $dx[0],
                ],
                [
                    'clinic_id' => $clinic->id,
                    'description' => $dx[1],
                    'status' => 'active',
                    'recorded_by' => $doctor?->id ?? $counselor->id,
                ]
            );

            CounselingSession::query()->create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'counselor_id' => $counselor->id,
                'appointment_id' => $appt->id,
                'session_type' => 'individual',
                'notes' => 'CBT focus: coping skills and sleep hygiene. Patient engaged.',
                'goals' => ['Reduce anxiety symptoms', 'Improve sleep schedule'],
            ]);

            if ($i < 5) {
                Assessment::query()->create([
                    'clinic_id' => $clinic->id,
                    'patient_id' => $patient->id,
                    'administered_by' => $counselor->id,
                    'instrument' => $i % 2 === 0 ? 'PHQ-9' : 'GAD-7',
                    'score' => 6 + ($i % 8),
                    'responses' => ['note' => 'Demo scored instrument'],
                ]);

                BillingCode::query()->firstOrCreate(
                    [
                        'patient_id' => $patient->id,
                        'code' => $i % 2 === 0 ? '90834' : '90837',
                        'status' => 'suggested',
                    ],
                    [
                        'clinic_id' => $clinic->id,
                        'appointment_id' => $appt->id,
                        'code_system' => 'CPT',
                        'description' => $i % 2 === 0
                            ? 'Psychotherapy, 45 minutes'
                            : 'Psychotherapy, 60 minutes',
                        'source' => 'ai_suggest',
                    ]
                );
            }
        }

        $this->command?->info('Counselor demo: 10 therapy appointments + sessions seeded.');
    }
}
