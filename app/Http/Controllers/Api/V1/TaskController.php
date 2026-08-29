<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProviderTask;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = ProviderTask::query()
            ->where('clinic_id', $user->clinic_id)
            ->where('user_id', $user->id)
            ->orderBy('done')
            ->orderByDesc('due_at')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (ProviderTask $task) => $this->payload($task));

        return ApiResponse::success(['items' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'patient_name' => ['nullable', 'string', 'max:255'],
            'patient_id' => ['nullable', 'integer', 'exists:patients,id'],
            'priority' => ['nullable', 'in:high,medium,low'],
            'done' => ['nullable', 'boolean'],
        ]);

        $dueAt = now();

        $task = ProviderTask::query()->create([
            'clinic_id' => $user->clinic_id,
            'user_id' => $user->id,
            'title' => trim($data['title']),
            'patient_name' => isset($data['patient_name']) ? trim((string) $data['patient_name']) : null,
            'patient_id' => $data['patient_id'] ?? null,
            'priority' => $data['priority'] ?? 'medium',
            'due_at' => $dueAt,
            'due_label' => $this->formatDueLabel($dueAt),
            'done' => (bool) ($data['done'] ?? false),
        ]);

        return ApiResponse::created($this->payload($task), 'Task created');
    }

    public function update(Request $request, ProviderTask $task): JsonResponse
    {
        $this->authorizeOwner($request, $task);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'patient_name' => ['nullable', 'string', 'max:255'],
            'patient_id' => ['nullable', 'integer', 'exists:patients,id'],
            'priority' => ['sometimes', 'in:high,medium,low'],
            'done' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('title', $data)) {
            $task->title = trim($data['title']);
        }
        if (array_key_exists('patient_name', $data)) {
            $task->patient_name = $data['patient_name'] !== null
                ? trim((string) $data['patient_name'])
                : null;
        }
        if (array_key_exists('patient_id', $data)) {
            $task->patient_id = $data['patient_id'];
        }
        if (array_key_exists('priority', $data)) {
            $task->priority = $data['priority'];
        }
        if (array_key_exists('done', $data)) {
            $task->done = (bool) $data['done'];
        }

        // Keep original due_at; if missing (legacy rows), stamp now once.
        if ($task->due_at === null) {
            $task->due_at = now();
            $task->due_label = $this->formatDueLabel($task->due_at);
        }

        $task->save();

        return ApiResponse::success($this->payload($task), 'Task updated');
    }

    public function destroy(Request $request, ProviderTask $task): JsonResponse
    {
        $this->authorizeOwner($request, $task);
        $task->delete();

        return ApiResponse::success(['id' => $task->id], 'Task deleted');
    }

    private function authorizeOwner(Request $request, ProviderTask $task): void
    {
        $user = $request->user();
        abort_unless(
            (int) $task->user_id === (int) $user->id
            && (int) $task->clinic_id === (int) $user->clinic_id,
            403
        );
    }

    private function formatDueLabel(Carbon $dueAt): string
    {
        return $dueAt->timezone(config('app.timezone'))->format('M j, Y g:i A');
    }

    private function payload(ProviderTask $task): array
    {
        $dueAt = $task->due_at;
        $dueLabel = $dueAt
            ? $this->formatDueLabel($dueAt)
            : ($task->due_label ?: null);

        return [
            'id' => $task->id,
            'title' => $task->title,
            'patient_name' => $task->patient_name,
            'patient_id' => $task->patient_id,
            'priority' => $task->priority,
            'due' => $dueLabel,
            'due_label' => $dueLabel,
            'due_at' => optional($dueAt)?->toIso8601String(),
            'done' => (bool) $task->done,
            'created_at' => optional($task->created_at)?->toIso8601String(),
            'updated_at' => optional($task->updated_at)?->toIso8601String(),
        ];
    }
}
