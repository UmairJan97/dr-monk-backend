<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\BillingCode;
use App\Models\Clinic;
use App\Models\ClinicalNote;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use App\Models\Vital;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DoctorDemoSeeder extends Seeder
{
    public function run(): void
    {
        $clinic = Clinic::query()->where('slug', 'demo-clinic')->first();
        $doctor = User::query()->where('email', 'doctor@demo.local')->first();
        $nurse = User::query()->where('email', 'vitals@demo.local')->first();

        if (! $clinic || ! $doctor) {
            $this->command?->warn('Demo clinic/doctor missing.');

            return;
        }

        $tz = $clinic->timezone ?: 'America/New_York';
        $now = Carbon::now($tz);

        Appointment::query()
            ->where('clinic_id', $clinic->id)
            ->where('notes', 'like', '%[demo-doctor]%')
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
            $patient->update(['primary_provider_id' => $doctor->id]);
            if (! $doctor->assignedPatients()->where('patients.id', $patient->id)->exists()) {
                $doctor->assignedPatients()->attach($patient->id, ['clinic_id' => $clinic->id]);
            }

            $start = $now->copy()->startOfDay()->addHours(10)->addMinutes($i * 20);
            if ($start->lt($now->copy()->subMinutes(30))) {
                $start = $now->copy()->addMinutes(10 + ($i * 6));
            }

            $appt = Appointment::query()->create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'provider_id' => $doctor->id,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes(20),
                'status' => $statuses[$i] ?? 'ready_for_provider',
                'visit_type' => $i % 2 === 0 ? 'Office visit' : 'Follow-up',
                'notes' => '[demo-doctor]',
            ]);

            $sys = $i % 3 === 0 ? 148 : 122;
            $tempC = $i % 4 === 0 ? 38.2 : 36.7;
            $alerts = [];
            if ($sys >= 140) {
                $alerts[] = 'blood_pressure';
            }
            if ($tempC >= 38.0) {
                $alerts[] = 'high_temperature';
            }

            Vital::query()->create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'appointment_id' => $appt->id,
                'recorded_by' => $nurse?->id ?? $doctor->id,
                'height_cm' => 170,
                'weight_kg' => 70 + $i,
                'bmi' => round((70 + $i) / (1.7 * 1.7), 2),
                'temperature_c' => $tempC,
                'bp_systolic' => $sys,
                'bp_diastolic' => 78,
                'pulse' => 72 + $i,
                'respiratory_rate' => 16,
                'spo2' => 97,
                'pain_scale' => $i % 5,
                'glucose' => 95,
                'alerts' => $alerts,
            ]);

            if ($i < 4) {
                Diagnosis::query()->firstOrCreate(
                    [
                        'patient_id' => $patient->id,
                        'icd10_code' => 'J06.9',
                    ],
                    [
                        'clinic_id' => $clinic->id,
                        'description' => 'Acute upper respiratory infection, unspecified',
                        'recorded_by' => $doctor->id,
                        'status' => 'active',
                    ]
                );

                BillingCode::query()->firstOrCreate(
                    [
                        'patient_id' => $patient->id,
                        'code' => '99213',
                        'status' => 'suggested',
                    ],
                    [
                        'clinic_id' => $clinic->id,
                        'appointment_id' => $appt->id,
                        'code_system' => 'CPT',
                        'description' => 'Office/outpatient visit, established patient',
                        'source' => 'ai_suggest',
                    ]
                );
            }

            if ($i === 2) {
                ClinicalNote::query()->create([
                    'clinic_id' => $clinic->id,
                    'patient_id' => $patient->id,
                    'appointment_id' => $appt->id,
                    'author_id' => $doctor->id,
                    'note_type' => 'soap',
                    'content' => "S: Cough\nO: Lungs clear\nA: URI\nP: Supportive care",
                    'structured' => [
                        'subjective' => 'Cough x 3 days',
                        'objective' => 'Lungs clear, afebrile',
                        'assessment' => 'Acute URI',
                        'plan' => 'Supportive care, return if worsens',
                    ],
                    'is_signed' => false,
                ]);

                Prescription::query()->create([
                    'clinic_id' => $clinic->id,
                    'patient_id' => $patient->id,
                    'prescriber_id' => $doctor->id,
                    'medication_name' => 'Amoxicillin 500mg',
                    'sig' => '1 capsule by mouth twice daily x 7 days',
                    'quantity' => '14',
                    'refills' => '0',
                    'pharmacy' => 'Demo Pharmacy',
                    'status' => 'draft',
                ]);
            }
        }

        $this->command?->info('Doctor demo: 10 ready-for-provider appointments seeded.');
    }
}
