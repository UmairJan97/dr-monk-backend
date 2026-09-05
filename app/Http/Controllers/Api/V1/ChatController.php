<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ClinicNotification;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    private const ONLINE_SECONDS = 45;

    public function heartbeat(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->markOnline($user);

        return ApiResponse::success([
            'last_seen_at' => optional($user->last_activity_at)?->toIso8601String(),
            'chat_unread' => $this->unreadChatCount($user),
            'notif_unread' => $this->unreadNotifCount($user),
        ]);
    }

    public function away(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->markAway($user);

        return ApiResponse::success([
            'last_seen_at' => optional($user->last_activity_at)?->toIso8601String(),
            'presence' => 'offline',
        ]);
    }

    public function inbox(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = ChatMessage::query()
            ->with(['fromUser:id,name,email', 'toUser:id,name,email'])
            ->where('clinic_id', $user->clinic_id)
            ->where(function ($q) use ($user) {
                $q->where('to_user_id', $user->id)->orWhere('from_user_id', $user->id);
            })
            ->latest('id')
            ->limit(40)
            ->get()
            ->map(fn (ChatMessage $m) => $this->payload($m, $user->id));

        return ApiResponse::success([
            'items' => $items,
            'unread_count' => $this->unreadChatCount($user),
        ]);
    }

    public function contacts(Request $request): JsonResponse
    {
        $user = $request->user();
        $uid = (int) $user->id;

        $sent = DB::table('chat_messages')
            ->selectRaw('to_user_id as peer_id, MAX(id) as last_id')
            ->where('clinic_id', $user->clinic_id)
            ->where('from_user_id', $uid)
            ->groupBy('to_user_id');

        $received = DB::table('chat_messages')
            ->selectRaw('from_user_id as peer_id, MAX(id) as last_id')
            ->where('clinic_id', $user->clinic_id)
            ->where('to_user_id', $uid)
            ->groupBy('from_user_id');

        $lastRows = DB::query()
            ->fromSub($sent->unionAll($received), 'chat_peers')
            ->selectRaw('peer_id, MAX(last_id) as last_id')
            ->groupBy('peer_id')
            ->get();

        if ($lastRows->isEmpty()) {
            return ApiResponse::success(['items' => []]);
        }

        $lastMessages = ChatMessage::query()
            ->whereIn('id', $lastRows->pluck('last_id'))
            ->get(['id', 'from_user_id', 'to_user_id', 'body', 'created_at'])
            ->keyBy('id');

        $unreadByPeer = DB::table('chat_messages')
            ->selectRaw('from_user_id as peer_id, COUNT(*) as unread_count')
            ->where('clinic_id', $user->clinic_id)
            ->where('to_user_id', $uid)
            ->whereNull('read_at')
            ->groupBy('from_user_id')
            ->pluck('unread_count', 'peer_id');

        $peers = User::query()
            ->where('clinic_id', $user->clinic_id)
            ->whereIn('id', $lastRows->pluck('peer_id')->map(fn ($id) => (int) $id)->unique()->values())
            ->get(['id', 'name', 'email', 'last_activity_at'])
            ->keyBy('id');

        $items = $lastRows
            ->map(function ($row) use ($lastMessages, $unreadByPeer, $peers, $uid) {
                $peerId = (int) $row->peer_id;
                $peer = $peers->get($peerId);
                if (! $peer) {
                    return null;
                }
                $last = $lastMessages->get((int) $row->last_id);

                return [
                    'id' => $peer->id,
                    'name' => $peer->name,
                    'email' => $peer->email,
                    'last_message' => $last ? [
                        'id' => $last->id,
                        'body' => $last->body,
                        'created_at' => optional($last->created_at)?->toIso8601String(),
                        'mine' => (int) $last->from_user_id === $uid,
                    ] : null,
                    'unread_count' => (int) ($unreadByPeer[$peerId] ?? 0),
                    'presence' => $this->presenceOf($peer->id),
                    'last_seen_at' => optional($peer->last_activity_at)?->toIso8601String(),
                ];
            })
            ->filter()
            ->sortByDesc(fn ($c) => $c['last_message']['created_at'] ?? '')
            ->values();

        return ApiResponse::success(['items' => $items]);
    }

    public function thread(Request $request, User $peer): JsonResponse
    {
        $user = $request->user();
        abort_unless($peer->clinic_id === $user->clinic_id, 403);

        $fromLite = ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
        $toLite = ['id' => $peer->id, 'name' => $peer->name, 'email' => $peer->email];

        $rows = ChatMessage::query()
            ->where('clinic_id', $user->clinic_id)
            ->where(function ($q) use ($user, $peer) {
                $q->where(function ($inner) use ($user, $peer) {
                    $inner->where('from_user_id', $user->id)->where('to_user_id', $peer->id);
                })->orWhere(function ($inner) use ($user, $peer) {
                    $inner->where('from_user_id', $peer->id)->where('to_user_id', $user->id);
                });
            })
            ->orderByDesc('id')
            ->limit(80)
            ->get(['id', 'from_user_id', 'to_user_id', 'body', 'read_at', 'created_at']);

        $items = $rows->reverse()->values()->map(function (ChatMessage $m) use ($user, $fromLite, $toLite) {
            $mine = (int) $m->from_user_id === (int) $user->id;

            return [
                'id' => $m->id,
                'body' => $m->body,
                'from_user_id' => $m->from_user_id,
                'to_user_id' => $m->to_user_id,
                'from' => $mine ? $fromLite : $toLite,
                'to' => $mine ? $toLite : $fromLite,
                'mine' => $mine,
                'read_at' => optional($m->read_at)?->toIso8601String(),
                'created_at' => optional($m->created_at)?->toIso8601String(),
            ];
        });

        ChatMessage::query()
            ->where('clinic_id', $user->clinic_id)
            ->where('from_user_id', $peer->id)
            ->where('to_user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $peer->refresh();

        return ApiResponse::success([
            'peer' => $this->peerPayload($peer),
            'items' => $items,
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->markOnline($user);
        $data = $request->validate([
            'to_user_id' => ['required', 'integer', 'exists:users,id'],
            'body' => ['required', 'string', 'min:1', 'max:65535'],
        ]);

        $peer = User::query()->findOrFail($data['to_user_id']);
        abort_unless($peer->clinic_id === $user->clinic_id, 403);
        abort_if($peer->id === $user->id, 422, 'Cannot message yourself.');

        $message = ChatMessage::query()->create([
            'clinic_id' => $user->clinic_id,
            'from_user_id' => $user->id,
            'to_user_id' => $peer->id,
            'body' => trim($data['body']),
        ]);
        $message->setRelation('fromUser', $user);
        $message->setRelation('toUser', $peer);

        return ApiResponse::created($this->payload($message, $user->id), 'Message sent');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();

        ChatMessage::query()
            ->where('clinic_id', $user->clinic_id)
            ->where('to_user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return ApiResponse::success(null, 'All messages marked read');
    }

    public function markRead(Request $request, ChatMessage $message): JsonResponse
    {
        $user = $request->user();
        abort_unless($message->clinic_id === $user->clinic_id && $message->to_user_id === $user->id, 403);

        if (! $message->read_at) {
            $message->update(['read_at' => now()]);
        }

        return ApiResponse::success($this->payload($message->fresh(['fromUser:id,name,email', 'toUser:id,name,email']), $user->id));
    }

    private function markOnline(User $user): void
    {
        $user->forceFill(['last_activity_at' => now()])->saveQuietly();
        Cache::put($this->onlineKey((int) $user->id), 1, now()->addSeconds(self::ONLINE_SECONDS));
    }

    private function markAway(User $user): void
    {
        $user->forceFill(['last_activity_at' => now()])->saveQuietly();
        Cache::forget($this->onlineKey((int) $user->id));
    }

    private function presenceOf(int $userId): string
    {
        return Cache::has($this->onlineKey($userId)) ? 'online' : 'offline';
    }

    private function onlineKey(int $userId): string
    {
        return 'chat-online:'.$userId;
    }

    private function peerPayload(User $peer): array
    {
        return [
            'id' => $peer->id,
            'name' => $peer->name,
            'email' => $peer->email,
            'presence' => $this->presenceOf((int) $peer->id),
            'last_seen_at' => optional($peer->last_activity_at)?->toIso8601String(),
        ];
    }

    private function unreadChatCount(User $user): int
    {
        return ChatMessage::query()
            ->where('clinic_id', $user->clinic_id)
            ->where('to_user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    private function unreadNotifCount(User $user): int
    {
        return ClinicNotification::query()
            ->where('clinic_id', $user->clinic_id)
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    private function payload(ChatMessage $m, int $viewerId): array
    {
        $from = $m->fromUser;
        $to = $m->toUser;

        return [
            'id' => $m->id,
            'body' => $m->body,
            'from_user_id' => $m->from_user_id,
            'to_user_id' => $m->to_user_id,
            'from' => $from ? ['id' => $from->id, 'name' => $from->name, 'email' => $from->email] : null,
            'to' => $to ? ['id' => $to->id, 'name' => $to->name, 'email' => $to->email] : null,
            'mine' => $m->from_user_id === $viewerId,
            'read_at' => optional($m->read_at)?->toIso8601String(),
            'created_at' => optional($m->created_at)?->toIso8601String(),
        ];
    }
}
