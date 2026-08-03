<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ai\HelloMonkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiController extends Controller
{
    public function __construct(private HelloMonkService $monk) {}

    public function command(Request $request): JsonResponse
    {
        $data = $request->validate([
            'transcript' => ['required', 'string'],
            'patient_id' => ['nullable', 'exists:patients,id'],
        ]);

        return response()->json(
            $this->monk->handleCommand($request->user(), $data['transcript'], $data['patient_id'] ?? null)
        );
    }
}
