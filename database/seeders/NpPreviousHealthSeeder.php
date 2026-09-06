<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicalNote;
use App\Models\Patient;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Three completed past visits + SOAP notes so NP Previous Health Data has date cards.
 */
class NpPreviousHealthSeeder extends Seeder
{
    public const TAG = '[np-previous-health]';

    public function run(): void
    {
        $clinic = Clinic::query()->where('slug', 'demo-clinic')->first();
        $np = User::query()->where('email', 'np@demo.local')->first();

        if (! $clinic || ! $np) {
            $this->command?->warn('Demo clinic/NP missing.');

            return;
        }

        $tz = $clinic->timezone ?: 'America/New_York';
        $now = Carbon::now($tz);

        $patientIds = Appointment::query()
            ->where('clinic_id', $clinic->id)
            ->where('provider_id', $np->id)
            ->whereDate('starts_at', $now->toDateString())
            ->pluck('patient_id')
            ->unique()
            ->take(25)
            ->values();

        if (Patient::query()->where('clinic_id', $clinic->id)->whereKey(119)->exists()) {
            $patientIds = $patientIds->push(119)->unique()->values();
        }

        if ($patientIds->isEmpty()) {
            $patientIds = Patient::query()
                ->where('clinic_id', $clinic->id)
                ->orderBy('id')
                ->limit(12)
                ->pluck('id');
        }

        $oldIds = Appointment::query()
            ->where('clinic_id', $clinic->id)
            ->where('notes', self::TAG)
            ->pluck('id');

        if ($oldIds->isNotEmpty()) {
            ClinicalNote::query()->whereIn('appointment_id', $oldIds)->forceDelete();
            Appointment::query()->whereIn('id', $oldIds)->delete();
        }

        $templates = [
            [
                'daysAgo' => 21,
                'visit_type' => 'Follow-up',
                'subjective' => 'Chief complaint: Follow-up for hypertension and morning headaches.',
                'objective' => 'BP 138/84, HR 72, Temp 98.4°F, SpO2 98%.',
                'assessment' => 'Essential hypertension (I10)',
                'plan' => 'Continue lisinopril 10 mg daily. Recheck BP in 3 weeks. Low-salt diet counseling given.',
            ],
            [
                'daysAgo' => 14,
                'visit_type' => 'Sick visit',
                'subjective' => 'Chief complaint: Sore throat and low-grade fever for 3 days.',
                'objective' => 'BP 118/76, HR 88, Temp 100.2°F, SpO2 99%.',
                'assessment' => 'Acute pharyngitis, unspecified (J02.9)',
                'plan' => 'Supportive care, fluids, rest. Return if fever persists beyond 48 hours.',
            ],
            [
                'daysAgo' => 7,
                'visit_type' => 'NP visit',
                'subjective' => 'Chief complaint: Fatigue and increased thirst. Review of home glucose log.',
                'objective' => 'BP 126/80, HR 78, Temp 98.6°F, SpO2 98%, glucose 142.',
                'assessment' => 'Type 2 diabetes mellitus without complications (E11.9)',
                'plan' => 'Continue metformin. Reinforce diet and walking 30 minutes daily. Recheck A1c next visit.',
            ],
        ];

        $created = 0;

        foreach ($patientIds as $patientId) {
            foreach ($templates as $row) {
                $start = $now->copy()->subDays($row['daysAgo'])->setTime(10, 30);
                $appt = Appointment::query()->create([
                    'clinic_id' => $clinic->id,
                    'patient_id' => $patientId,
                    'provider_id' => $np->id,
                    'starts_at' => $start,
                    'ends_at' => $start->copy()->addMinutes(20),
                    'status' => 'completed',
                    'visit_type' => $row['visit_type'],
                    'notes' => self::TAG,
                    'checked_in_at' => $start->copy()->subMinutes(8),
                ]);

                $content = trim(sprintf(
                    "S: %s\nO: %s\nA: %s\nP: %s",
                    $row['subjective'],
                    $row['objective'],
                    $row['assessment'],
                    $row['plan'],
                ));

                ClinicalNote::query()->create([
                    'clinic_id' => $clinic->id,
                    'patient_id' => $patientId,
                    'appointment_id' => $appt->id,
                    'author_id' => $np->id,
                    'note_type' => 'soap',
                    'content' => $content,
                    'structured' => [
                        'subjective' => $row['subjective'],
                        'objective' => $row['objective'],
                        'assessment' => $row['assessment'],
                        'plan' => $row['plan'],
                    ],
                    'is_signed' => true,
                    'signed_at' => $start->copy()->addMinutes(18),
                ]);
                $created++;
            }
        }

        $this->command?->info("NP previous health: {$created} completed visits seeded for {$patientIds->count()} patients.");
    }
}
