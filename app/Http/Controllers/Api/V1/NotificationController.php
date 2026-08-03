<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClinicNotification;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = ClinicNotification::query()
            ->where('clinic_id', $user->clinic_id)
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->limit(40)
            ->get()
            ->map(fn (ClinicNotification $n) => $this->payload($n));

        $unread = ClinicNotification::query()
            ->where('clinic_id', $user->clinic_id)
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return ApiResponse::success([
            'items' => $notifications,
            'unread_count' => $unread,
        ]);
    }

    public function markRead(Request $request, ClinicNotification $notification): JsonResponse
    {
        abort_unless(
            $notification->user_id === $request->user()->id
            && $notification->clinic_id === $request->user()->clinic_id,
            403
        );

        $notification->markRead();

        return ApiResponse::success([
            'id' => $notification->id,
            'read_at' => $notification->read_at?->toIso8601String(),
        ], 'Notification marked read');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        ClinicNotification::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return ApiResponse::success(null, 'All notifications marked read');
    }

    private function payload(ClinicNotification $n): array
    {
        return [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'data' => $n->data ?? [],
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => optional($n->created_at)?->toIso8601String(),
        ];
    }
}
