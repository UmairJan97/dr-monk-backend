<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Clinic;
use App\Models\ClinicNotification;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChatNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (Permissions::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        foreach (Roles::all() as $roleName) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions(Permissions::matrix()[$roleName] ?? []);
        }
    }

    public function test_contacts_only_include_people_with_message_history(): void
    {
        [$doctor, $nurse, $billing] = $this->staff();
        Sanctum::actingAs($doctor);

        $this->getJson('/api/v1/chat/contacts')
            ->assertOk()
            ->assertJsonPath('data.items', []);

        ChatMessage::query()->create([
            'clinic_id' => $doctor->clinic_id,
            'from_user_id' => $nurse->id,
            'to_user_id' => $doctor->id,
            'body' => 'Vitals ready',
        ]);
        ChatMessage::query()->create([
            'clinic_id' => $doctor->clinic_id,
            'from_user_id' => $billing->id,
            'to_user_id' => $nurse->id,
            'body' => 'Not for doctor',
        ]);

        $this->getJson('/api/v1/chat/contacts')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $nurse->id)
            ->assertJsonPath('data.items.0.last_message.body', 'Vitals ready');
    }

    public function test_heartbeat_marks_user_online_and_returns_separate_unreads(): void
    {
        [$doctor, $nurse] = $this->staff();
        ChatMessage::query()->create([
            'clinic_id' => $doctor->clinic_id,
            'from_user_id' => $nurse->id,
            'to_user_id' => $doctor->id,
            'body' => 'Hello',
        ]);
        ClinicNotification::query()->create([
            'clinic_id' => $doctor->clinic_id,
            'user_id' => $doctor->id,
            'type' => 'ready_for_provider',
            'title' => 'Patient ready',
            'body' => 'Room 2 is ready',
        ]);

        Sanctum::actingAs($doctor);
        $this->postJson('/api/v1/chat/heartbeat')
            ->assertOk()
            ->assertJsonPath('data.chat_unread', 1)
            ->assertJsonPath('data.notif_unread', 1);

        $this->assertNotNull($doctor->fresh()->last_activity_at);

        Sanctum::actingAs($nurse);
        $this->getJson('/api/v1/chat/contacts')
            ->assertOk()
            ->assertJsonPath('data.items.0.presence', 'online');

        Sanctum::actingAs($doctor);
        $this->postJson('/api/v1/chat/away')->assertOk();

        Sanctum::actingAs($nurse);
        $offline = $this->getJson('/api/v1/chat/contacts')
            ->assertOk()
            ->assertJsonPath('data.items.0.presence', 'offline');
        $this->assertNotEmpty($offline->json('data.items.0.last_seen_at'));
    }

    public function test_recent_activity_without_heartbeat_is_not_online(): void
    {
        [$doctor, $nurse] = $this->staff();
        $doctor->forceFill(['last_activity_at' => now()])->saveQuietly();

        ChatMessage::query()->create([
            'clinic_id' => $doctor->clinic_id,
            'from_user_id' => $doctor->id,
            'to_user_id' => $nurse->id,
            'body' => 'Still here',
        ]);

        Sanctum::actingAs($nurse);
        $this->getJson('/api/v1/chat/contacts')
            ->assertOk()
            ->assertJsonPath('data.items.0.presence', 'offline');
    }

    public function test_notification_mark_read_does_not_touch_chat(): void
    {
        [$doctor, $nurse] = $this->staff();
        $notif = ClinicNotification::query()->create([
            'clinic_id' => $doctor->clinic_id,
            'user_id' => $doctor->id,
            'type' => 'ready_for_provider',
            'title' => 'Patient ready',
            'body' => 'Dana is ready',
        ]);
        ChatMessage::query()->create([
            'clinic_id' => $doctor->clinic_id,
            'from_user_id' => $nurse->id,
            'to_user_id' => $doctor->id,
            'body' => 'Still unread chat',
        ]);

        Sanctum::actingAs($doctor);
        $this->postJson('/api/v1/notifications/'.$notif->id.'/read')
            ->assertOk();

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->postJson('/api/v1/chat/heartbeat')
            ->assertOk()
            ->assertJsonPath('data.chat_unread', 1)
            ->assertJsonPath('data.notif_unread', 0);
    }

    /**
     * @return array{0: User, 1: User, 2: User}
     */
    private function staff(): array
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Doc',
            'slug' => 'chat-plan',
            'billing_period' => 'monthly',
            'price_cents' => 100,
            'ai_credits_monthly' => 100,
            'storage_mb' => 100,
            'is_active' => true,
        ]);
        $clinic = Clinic::create([
            'name' => 'Chat Clinic',
            'slug' => 'chat-clinic',
            'status' => 'active',
            'subscription_plan_id' => $plan->id,
            'ai_credits_balance' => 100,
        ]);
        $doctor = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'name' => 'Dr Demo',
        ]);
        $doctor->assignRole(Roles::DOCTOR);
        $nurse = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'name' => 'Nurse Demo',
            'last_activity_at' => now(),
        ]);
        $nurse->assignRole(Roles::VITAL_NURSE);
        $billing = User::factory()->create([
            'clinic_id' => $clinic->id,
            'is_active' => true,
            'name' => 'Billing Demo',
        ]);
        $billing->assignRole(Roles::BILLING);

        return [$doctor, $nurse, $billing];
    }
}
