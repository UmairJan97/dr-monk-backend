<?php

namespace Database\Seeders;

use App\Models\ChatMessage;
use App\Models\Clinic;
use App\Models\User;
use App\Support\Roles;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Dummy staff chat: from today for the next 15 days, 10 messages/day per role inbox.
 */
class ChatDemoSeeder extends Seeder
{
    public function run(): void
    {
        $clinic = Clinic::query()->where('slug', 'demo-clinic')->first()
            ?? Clinic::query()->first();
        if (! $clinic) {
            return;
        }

        $roleEmails = [
            Roles::CLINIC_ADMIN => 'admin@demo.local',
            Roles::DOCTOR => 'doctor@demo.local',
            Roles::NP => 'np@demo.local',
            Roles::VITAL_NURSE => 'vitals@demo.local',
            Roles::FRONT_DESK => 'desk@demo.local',
            Roles::COUNSELOR => 'counselor@demo.local',
            Roles::THERAPIST => 'therapist@demo.local',
            Roles::BILLING => 'billing@demo.local',
        ];

        $users = [];
        foreach ($roleEmails as $role => $email) {
            $user = User::query()->where('email', $email)->first();
            if ($user) {
                $users[$role] = $user;
            }
        }
        if (count($users) < 2) {
            return;
        }

        // Refresh demo chat each seed so counts stay exact for testing.
        ChatMessage::query()->where('clinic_id', $clinic->id)->delete();

        $userList = array_values($users);
        $roleKeys = array_keys($users);
        $snippets = [
            'Can you confirm room availability for this afternoon?',
            'Patient chart is ready for review.',
            'Insurance eligibility came back active.',
            'Please check the latest vitals when you have a moment.',
            'Follow-up appointment needs to be scheduled.',
            'Lab results uploaded — please review.',
            'Front desk: patient is checked in.',
            'Billing question on today’s visit codes.',
            'Counseling note draft is ready.',
            'Quick sync on tomorrow’s first slot?',
            'Reminder: incomplete note on today’s queue.',
            'Pharmacy called about a refill request.',
            'New document scanned into the chart.',
            'Can we move the 2pm visit up if needed?',
            'Thanks — message received.',
        ];

        $start = Carbon::today()->startOfDay();
        $rows = [];

        foreach ($roleKeys as $roleIndex => $role) {
            $recipient = $users[$role];
            for ($day = 0; $day < 15; $day++) {
                $dayBase = $start->copy()->addDays($day);
                for ($i = 0; $i < 10; $i++) {
                    $sender = $userList[($day * 10 + $i + $roleIndex) % count($userList)];
                    if ($sender->id === $recipient->id) {
                        $sender = $userList[($i + 1) % count($userList)];
                        if ($sender->id === $recipient->id) {
                            continue;
                        }
                    }

                    $createdAt = $dayBase->copy()->addHours(8 + ($i % 8))->addMinutes(($i * 7) % 60);
                    $createdStr = $createdAt->toDateTimeString();
                    $rows[] = [
                        'clinic_id' => $clinic->id,
                        'from_user_id' => $sender->id,
                        'to_user_id' => $recipient->id,
                        'body' => $snippets[($day * 10 + $i) % count($snippets)],
                        'read_at' => $i % 3 === 0 ? $createdAt->copy()->addMinutes(12)->toDateTimeString() : null,
                        'created_at' => $createdStr,
                        'updated_at' => $createdStr,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            ChatMessage::query()->insert($chunk);
        }
    }
}
