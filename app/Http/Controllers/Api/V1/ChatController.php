<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function inbox(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = ChatMessage::query()
            ->with(['fromUser:id,name,email', 'toUser:id,name,email'])
            ->where('clinic_id', $user->clinic_id)
            ->where(function ($q) use ($user) {
                $q->where('to_user_id', $user->id)->orWhere('from_user_id', $user->id);
            })
            ->latest('created_at')
            ->limit(80)
            ->get()
            ->map(fn (ChatMessage $m) => $this->payload($m, $user->id));

        $unread = ChatMessage::query()
            ->where('clinic_id', $user->clinic_id)
            ->where('to_user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return ApiResponse::success([
            'items' => $items,
            'unread_count' => $unread,
        ]);
    }

    public function contacts(Request $request): JsonResponse
    {
        $user = $request->user();

        $lastMessages = ChatMessage::query()
            ->where('clinic_id', $user->clinic_id)
            ->where(function ($q) use ($user) {
                $q->where('to_user_id', $user->id)->orWhere('from_user_id', $user->id);
            })
            ->latest('created_at')
            ->get(['id', 'from_user_id', 'to_user_id', 'body', 'created_at', 'read_at']);

        $lastByPeer = [];
        $unreadByPeer = [];
        foreach ($lastMessages as $m) {
            $peerId = $m->from_user_id === $user->id ? $m->to_user_id : $m->from_user_id;
            if (! isset($lastByPeer[$peerId])) {
                $lastByPeer[$peerId] = $m;
            }
            if ($m->to_user_id === $user->id && $m->read_at === null) {
                $unreadByPeer[$peerId] = ($unreadByPeer[$peerId] ?? 0) + 1;
            }
        }

        $contacts = User::query()
            ->where('clinic_id', $user->clinic_id)
            ->where('id', '!=', $user->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'updated_at'])
            ->map(function (User $u) use ($lastByPeer, $unreadByPeer) {
                $last = $lastByPeer[$u->id] ?? null;
                $lastAt = $last?->created_at;
                $recent = $lastAt && $lastAt->gt(now()->subHours(24));

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'roles' => $u->getRoleNames()->values()->all(),
                    'last_message' => $last ? [
                        'id' => $last->id,
                        'body' => $last->body,
                        'created_at' => optional($last->created_at)?->toIso8601String(),
                        'mine' => $last->from_user_id !== $u->id,
                    ] : null,
                    'unread_count' => $unreadByPeer[$u->id] ?? 0,
                    'presence' => $recent ? 'online' : 'offline',
                ];
            })
            ->sortByDesc(fn ($c) => $c['last_message']['created_at'] ?? '')
            ->values();

        return ApiResponse::success(['items' => $contacts]);
    }

    public function thread(Request $request, User $peer): JsonResponse
    {
        $user = $request->user();
        abort_unless($peer->clinic_id === $user->clinic_id, 403);

        $items = ChatMessage::query()
            ->with(['fromUser:id,name,email', 'toUser:id,name,email'])
            ->where('clinic_id', $user->clinic_id)
            ->where(function ($q) use ($user, $peer) {
                $q->where(function ($inner) use ($user, $peer) {
                    $inner->where('from_user_id', $user->id)->where('to_user_id', $peer->id);
                })->orWhere(function ($inner) use ($user, $peer) {
                    $inner->where('from_user_id', $peer->id)->where('to_user_id', $user->id);
                });
            })
            ->orderBy('created_at')
            ->limit(200)
            ->get()
            ->map(fn (ChatMessage $m) => $this->payload($m, $user->id));

        ChatMessage::query()
            ->where('clinic_id', $user->clinic_id)
            ->where('from_user_id', $peer->id)
            ->where('to_user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return ApiResponse::success([
            'peer' => [
                'id' => $peer->id,
                'name' => $peer->name,
                'email' => $peer->email,
                'roles' => $peer->getRoleNames()->values()->all(),
            ],
            'items' => $items,
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $user = $request->user();
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
        $message->load(['fromUser:id,name,email', 'toUser:id,name,email']);

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
