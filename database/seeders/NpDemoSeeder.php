<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\BillingCode;
use App\Models\Clinic;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\User;
use App\Models\Vital;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class NpDemoSeeder extends Seeder
{
    public function run(): void
    {
        $clinic = Clinic::query()->where('slug', 'demo-clinic')->first();
        $np = User::query()->where('email', 'np@demo.local')->first();
        $nurse = User::query()->where('email', 'vitals@demo.local')->first();

        if (! $clinic || ! $np) {
            $this->command?->warn('Demo clinic/NP missing.');

            return;
        }

        // Ensure NP can prescribe in demo (NY + allowed states)
        $np->forceFill([
            'can_prescribe' => true,
            'license_state' => config('drmonk.default_license_state', 'NY'),
            'npi' => $np->npi ?: '1234567890',
        ])->save();

        $tz = $clinic->timezone ?: 'America/New_York';
        $now = Carbon::now($tz);

        Appointment::query()
            ->where('clinic_id', $clinic->id)
            ->where('notes', 'like', '%[demo-np]%')
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
            'ready_for_provider', 'ready_for_provider', 'in_progress', 'ready_for_provider',
            'ready_for_provider', 'vitals_completed', 'ready_for_provider', 'ready_for_provider',
            'ready_for_provider', 'ready_for_provider',
        ];

        foreach ($patients->take(10)->values() as $i => $patient) {
            // Assign to NP (keep primary or set if unset)
            if (! $patient->primary_provider_id) {
                $patient->update(['primary_provider_id' => $np->id]);
            }
            if (! $np->assignedPatients()->where('patients.id', $patient->id)->exists()) {
                $np->assignedPatients()->attach($patient->id, ['clinic_id' => $clinic->id]);
            }

            $start = $now->copy()->startOfDay()->addHours(11)->addMinutes($i * 18);
            if ($start->lt($now->copy()->subMinutes(20))) {
                $start = $now->copy()->addMinutes(8 + ($i * 5));
            }

            $appt = Appointment::query()->create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'provider_id' => $np->id,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes(18),
                'status' => $statuses[$i] ?? 'ready_for_provider',
                'visit_type' => $i % 2 === 0 ? 'NP visit' : 'Follow-up',
                'notes' => '[demo-np]',
            ]);

            Vital::query()->create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'appointment_id' => $appt->id,
                'recorded_by' => $nurse?->id ?? $np->id,
                'height_cm' => 165,
                'weight_kg' => 68,
                'bmi' => 25.0,
                'temperature_c' => $i % 5 === 0 ? 38.1 : 36.6,
                'bp_systolic' => $i % 4 === 0 ? 142 : 118,
                'bp_diastolic' => 76,
                'pulse' => 74,
                'respiratory_rate' => 16,
                'spo2' => 98,
                'pain_scale' => 2,
                'alerts' => $i % 4 === 0 ? ['blood_pressure'] : [],
            ]);

            if ($i < 3) {
                Diagnosis::query()->firstOrCreate(
                    [
                        'patient_id' => $patient->id,
                        'icd10_code' => 'J02.9',
                        'recorded_by' => $np->id,
                    ],
                    [
                        'clinic_id' => $clinic->id,
                        'description' => 'Acute pharyngitis, unspecified',
                        'status' => 'active',
                    ]
                );

                BillingCode::query()->firstOrCreate(
                    [
                        'patient_id' => $patient->id,
                        'code' => '99213',
                        'status' => 'suggested',
                        'confirmed_by' => null,
                    ],
                    [
                        'clinic_id' => $clinic->id,
                        'appointment_id' => $appt->id,
                        'code_system' => 'CPT',
                        'description' => 'Office visit, established',
                        'source' => 'ai_suggest',
                    ]
                );
            }
        }

        $this->command?->info('NP demo: 10 ready-for-provider appointments seeded (prescribe: '.$np->license_state.').');
    }
}
