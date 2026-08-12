<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Assessment;
use App\Models\BillingCode;
use App\Models\CounselingSession;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Services\Ai\CodingSuggestService;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CounselorController extends Controller
{
    public function __construct(private CodingSuggestService $coding) {}

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $clinicId = $user->clinic_id;

        $today = Appointment::query()
            ->where('clinic_id', $clinicId)
            ->where('provider_id', $user->id)
            ->whereDate('starts_at', today());

        return ApiResponse::success([
            'stats' => [
                'todays_sessions' => (clone $today)->count(),
                'waiting' => (clone $today)->whereIn('status', ['waiting', 'ready_for_provider', 'vitals_completed'])->count(),
                'open_goals' => CounselingSession::query()
                    ->where('counselor_id', $user->id)
                    ->whereNotNull('goals')
                    ->count(),
                'assessments_today' => Assessment::query()
                    ->where('administered_by', $user->id)
                    ->whereDate('created_at', today())
                    ->count(),
            ],
            'queue' => (clone $today)
                ->whereNotIn('status', ['completed', 'cancelled', 'no_show'])
                ->with(['patient:id,first_name,last_name,mrn'])
                ->orderBy('starts_at')
                ->limit(20)
                ->get(),
        ]);
    }

    public function schedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : today()->startOfDay();
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : today()->addDays(6)->endOfDay();

        $items = Appointment::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->where('provider_id', $request->user()->id)
            ->whereBetween('starts_at', [$from, $to])
            ->with(['patient:id,first_name,last_name,mrn'])
            ->orderBy('starts_at')
            ->get();

        return ApiResponse::success(['items' => $items]);
    }

    public function doctorDiagnosis(Request $request, Patient $patient): JsonResponse
    {
        $diagnoses = Diagnosis::query()
            ->where('patient_id', $patient->id)
            ->where('status', 'active')
            ->latest()
            ->get(['id', 'icd10_code', 'description', 'status', 'created_at']);

        return ApiResponse::success([
            'read_only' => true,
            'patient' => [
                'id' => $patient->id,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'mrn' => $patient->mrn,
            ],
            'diagnoses' => $diagnoses,
        ]);
    }

    public function sessions(Request $request, Patient $patient): JsonResponse
    {
        $items = CounselingSession::query()
            ->where('patient_id', $patient->id)
            ->where('clinic_id', $request->user()->clinic_id)
            ->latest()
            ->limit(30)
            ->get();

        return ApiResponse::success(['items' => $items]);
    }

    public function storeSession(Request $request, Patient $patient): JsonResponse
    {
        $data = $request->validate([
            'session_type' => ['nullable', 'string', 'in:individual,couples,family,group'],
            'modality' => ['nullable', 'string', 'in:in_person,telehealth'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:120'],
            'notes' => ['required', 'string', 'min:10', 'max:20000'],
            'goals' => ['nullable', 'array'],
            'goals.*' => ['string', 'max:500'],
            'appointment_id' => ['nullable', 'exists:appointments,id'],
        ]);

        $type = $data['session_type'] ?? 'individual';
        $modality = $data['modality'] ?? 'in_person';
        $minutes = $data['duration_minutes'] ?? 45;
        // Persist modality/length in session_type (no dedicated columns yet).
        $sessionLabel = sprintf('%s · %s · %dm', $type, $modality, $minutes);

        $session = CounselingSession::create([
            'session_type' => $sessionLabel,
            'notes' => $data['notes'],
            'goals' => $data['goals'] ?? [],
            'appointment_id' => $data['appointment_id'] ?? null,
            'clinic_id' => $patient->clinic_id,
            'patient_id' => $patient->id,
            'counselor_id' => $request->user()->id,
        ]);

        $suggestions = $this->coding->suggest(
            $request->user(),
            $patient,
            $data['notes'] ?? null,
            $data['duration_minutes'] ?? null
        );

        return ApiResponse::created([
            'session' => $session,
            'coding_suggestions' => $suggestions,
        ], 'Session saved');
    }

    public function updateGoals(Request $request, Patient $patient, CounselingSession $session): JsonResponse
    {
        abort_unless(
            $session->patient_id === $patient->id
            && $session->clinic_id === $request->user()->clinic_id,
            403
        );

        $data = $request->validate([
            'goals' => ['required', 'array', 'min:1'],
            'goals.*' => ['string', 'max:500'],
        ]);

        $session->update(['goals' => $data['goals']]);

        return ApiResponse::success($session->fresh(), 'Goals updated');
    }

    public function assessments(Request $request, Patient $patient): JsonResponse
    {
        $items = Assessment::query()
            ->where('patient_id', $patient->id)
            ->where('clinic_id', $request->user()->clinic_id)
            ->latest()
            ->limit(30)
            ->get();

        return ApiResponse::success(['items' => $items]);
    }

    public function storeAssessment(Request $request, Patient $patient): JsonResponse
    {
        $data = $request->validate([
            'instrument' => ['required', 'in:PHQ-9,GAD-7,PCL-5,custom'],
            'responses' => ['required', 'array'],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $assessment = Assessment::create([
            ...$data,
            'clinic_id' => $patient->clinic_id,
            'patient_id' => $patient->id,
            'administered_by' => $request->user()->id,
        ]);

        return ApiResponse::created($assessment, 'Assessment recorded');
    }

    public function billingSuggestions(Request $request, Patient $patient): JsonResponse
    {
        $items = BillingCode::query()
            ->where('patient_id', $patient->id)
            ->where('clinic_id', $request->user()->clinic_id)
            ->whereIn('status', ['suggested', 'confirmed', 'accepted'])
            ->latest()
            ->limit(30)
            ->get();

        return ApiResponse::success(['items' => $items]);
    }

    /** Counselor may confirm therapy codes only — no claims/ledger. */
    public function confirmBillingCode(Request $request, BillingCode $billingCode): JsonResponse
    {
        abort_unless($billingCode->clinic_id === $request->user()->clinic_id, 403);

        $data = $request->validate([
            'status' => ['required', 'in:accepted,dismissed,confirmed,modified'],
            'code' => ['nullable', 'string', 'max:32'],
            'modifier' => ['nullable', 'string', 'max:16'],
        ]);

        $billingCode->update([
            'status' => $data['status'],
            'code' => $data['code'] ?? $billingCode->code,
            'modifier' => $data['modifier'] ?? $billingCode->modifier,
            'confirmed_by' => $request->user()->id,
        ]);

        return ApiResponse::success($billingCode->fresh(), 'Billing code updated');
    }

    public function completeSession(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($appointment->clinic_id === $request->user()->clinic_id, 403);
        abort_unless($appointment->provider_id === $request->user()->id, 403);

        if ($appointment->status === 'completed') {
            return ApiResponse::success($appointment->fresh(), 'Session already completed');
        }

        if (in_array($appointment->status, ['cancelled', 'no_show'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => ['Session cannot be completed from status: '.$appointment->status],
            ]);
        }

        $appointment->update(['status' => 'completed']);

        return ApiResponse::success($appointment->fresh(), 'Session completed');
    }
}
