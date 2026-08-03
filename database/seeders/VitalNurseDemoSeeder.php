<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\User;
use App\Models\Vital;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class VitalNurseDemoSeeder extends Seeder
{
    public function run(): void
    {
        $clinic = Clinic::query()->where('slug', 'demo-clinic')->first();
        $doctor = User::query()->where('email', 'doctor@demo.local')->first();
        $np = User::query()->where('email', 'np@demo.local')->first();
        $nurse = User::query()->where('email', 'vitals@demo.local')->first();

        if (! $clinic || ! $doctor || ! $nurse) {
            $this->command?->warn('Demo clinic/users missing — run DatabaseSeeder first.');

            return;
        }

        $tz = $clinic->timezone ?: 'America/New_York';
        $now = Carbon::now($tz);

        Appointment::query()
            ->where('clinic_id', $clinic->id)
            ->where('notes', 'like', '%[demo-vital-nurse]%')
            ->delete();

        $patients = Patient::query()
            ->where('clinic_id', $clinic->id)
            ->orderBy('id')
            ->limit(12)
            ->get();

        if ($patients->count() < 10) {
            $this->command?->warn('Need FrontDeskDemoSeeder patients first (≥10).');

            return;
        }

        $statuses = [
            'waiting', 'waiting', 'ready_for_vitals', 'waiting', 'waiting',
            'ready_for_vitals', 'waiting', 'waiting', 'vitals_completed', 'waiting',
        ];
        $providers = [$doctor->id, $np?->id ?? $doctor->id];

        foreach ($patients->take(10)->values() as $i => $patient) {
            $start = $now->copy()->startOfDay()->addHours(9)->addMinutes($i * 25);
            if ($start->lt($now->copy()->subHour())) {
                $start = $now->copy()->addMinutes(5 + ($i * 8));
            }

            $appt = Appointment::query()->create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'provider_id' => $providers[$i % 2],
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes(25),
                'status' => $statuses[$i] ?? 'waiting',
                'visit_type' => $i % 2 === 0 ? 'Office visit' : 'Follow-up',
                'notes' => '[demo-vital-nurse]',
            ]);

            if ($statuses[$i] === 'vitals_completed' || $i === 2) {
                Vital::query()->create([
                    'clinic_id' => $clinic->id,
                    'patient_id' => $patient->id,
                    'appointment_id' => $appt->id,
                    'recorded_by' => $nurse->id,
                    'height_cm' => 170,
                    'weight_kg' => 72,
                    'bmi' => 24.91,
                    'temperature_c' => 36.8,
                    'bp_systolic' => 118,
                    'bp_diastolic' => 76,
                    'pulse' => 72,
                    'respiratory_rate' => 16,
                    'spo2' => 98,
                    'pain_scale' => 1,
                    'glucose' => 95,
                    'alerts' => [],
                ]);
            }
        }

        $this->command?->info('Vital Nurse demo: 10 queue appointments seeded.');
    }
}
