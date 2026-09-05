<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\CounselingSession;
use App\Models\Patient;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Roles;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class TherapistDemoSeeder extends Seeder
{
    public function run(): void
    {
        $clinic = Clinic::query()->where('slug', 'demo-clinic')->first();

        if (! $clinic) {
            $this->command?->warn('Demo clinic missing.');

            return;
        }

        $role = Role::findOrCreate(Roles::THERAPIST, 'web');
        $role->syncPermissions(Permissions::matrix()[Roles::THERAPIST] ?? []);

        $therapist = User::query()->updateOrCreate(
            ['email' => 'therapist@demo.local'],
            [
                'clinic_id' => $clinic->id,
                'name' => 'Therapist Maya',
                'password' => Hash::make('password'),
                'phone' => '5550100',
                'is_active' => true,
                'can_prescribe' => false,
                'pin_hash' => Hash::make('1234'),
            ]
        );
        $therapist->syncRoles([Roles::THERAPIST]);

        $tz = $clinic->timezone ?: 'America/New_York';
        $now = Carbon::now($tz);

        CounselingSession::query()
            ->where('counselor_id', $therapist->id)
            ->delete();

        Appointment::query()
            ->where('clinic_id', $clinic->id)
            ->where('notes', 'like', '%[demo-therapist]%')
            ->delete();

        $patients = Patient::query()
            ->where('clinic_id', $clinic->id)
            ->orderBy('id')
            ->skip(2)
            ->take(8)
            ->get();

        if ($patients->isEmpty()) {
            $this->command?->warn('Need FrontDeskDemoSeeder patients first.');

            return;
        }

        $statuses = [
            'ready_for_provider', 'waiting', 'in_progress', 'ready_for_provider',
            'waiting', 'ready_for_provider', 'in_progress', 'ready_for_provider',
        ];

        foreach ($patients->values() as $i => $patient) {
            $start = $now->copy()->startOfDay()->addHours(13)->addMinutes($i * 30);
            if ($start->lt($now->copy()->subMinutes(15))) {
                $start = $now->copy()->addMinutes(12 + ($i * 8));
            }

            $appt = Appointment::query()->create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'provider_id' => $therapist->id,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes(50),
                'status' => $statuses[$i] ?? 'ready_for_provider',
                'visit_type' => $i % 2 === 0 ? 'Therapy evaluation' : 'Follow-up therapy',
                'notes' => '[demo-therapist]',
            ]);

            CounselingSession::query()->create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'counselor_id' => $therapist->id,
                'appointment_id' => $appt->id,
                'session_type' => 'evaluation',
                'notes' => 'CBT evaluation: mood, coping, and session goals. Patient engaged.',
                'goals' => ['Stabilize mood', 'Practice grounding skills'],
            ]);
        }

        $this->command?->info('Therapist demo: therapy appointments + sessions seeded.');
    }
}
