<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\BillingCode;
use App\Models\ClinicalNote;
use App\Models\Diagnosis;
use App\Models\Document;
use App\Models\FollowUp;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\TreatmentPlan;
use App\Models\Vital;
use App\Services\Ai\CodingSuggestService;
use App\Services\Ai\PatientSummaryService;
use App\Services\Integrations\SurescriptsService;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClinicalController extends Controller
{
    public function __construct(
        private PatientSummaryService $summaries,
        private CodingSuggestService $coding,
        private SurescriptsService $surescripts,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $clinicId = $user->clinic_id;

        $queue = Appointment::query()
            ->where('clinic_id', $clinicId)
            ->where('provider_id', $user->id)
            ->whereIn('status', ['ready_for_provider', 'in_progress', 'vitals_completed'])
            ->whereDate('starts_at', today())
            ->with(['patient:id,first_name,last_name,mrn,date_of_birth', 'provider:id,name'])
            ->orderBy('starts_at')
            ->get();

        $completedToday = Appointment::query()
            ->where('clinic_id', $clinicId)
            ->where('provider_id', $user->id)
            ->where('status', 'completed')
            ->where(function ($q) {
                $q->whereDate('starts_at', today())
                    ->orWhereDate('updated_at', today());
            })
            ->with(['patient:id,first_name,last_name,mrn,date_of_birth', 'provider:id,name'])
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get();

        $incompleteNotes = ClinicalNote::query()
            ->where('clinic_id', $clinicId)
            ->where('author_id', $user->id)
            ->where('is_signed', false)
            ->count();

        $openLabs = LabOrder::query()
            ->where('clinic_id', $clinicId)
            ->where('ordered_by', $user->id)
            ->whereIn('status', ['ordered', 'pending'])
            ->count();

        $patientIds = $user->assignedPatients()->pluck('patients.id')
            ->merge(Patient::query()->where('primary_provider_id', $user->id)->pluck('id'))
            ->unique();

        $vitalAlerts = Vital::query()
            ->where('clinic_id', $clinicId)
            ->whereIn('patient_id', $patientIds)
            ->whereDate('created_at', today())
            ->with('patient:id,first_name,last_name,mrn')
            ->latest()
            ->limit(20)
            ->get()
            ->filter(fn (Vital $v) => ! empty($v->alerts))
            ->values()
            ->map(fn (Vital $v) => [
                'id' => $v->id,
                'patient_id' => $v->patient_id,
                'patient' => $v->patient ? [
                    'id' => $v->patient->id,
                    'first_name' => $v->patient->first_name,
                    'last_name' => $v->patient->last_name,
                    'mrn' => $v->patient->mrn,
                ] : null,
                'bp_systolic' => $v->bp_systolic,
                'temperature_c' => $v->temperature_c,
                'alerts' => $v->alerts,
                'alert_labels' => Vital::alertLabels($v->alerts ?? []),
            ]);

        return ApiResponse::success([
            'stats' => [
                'ready_queue' => $queue->count(),
                'completed_today' => $completedToday->count(),
                'incomplete_notes' => $incompleteNotes,
                'open_labs' => $openLabs,
                'vital_alerts' => $vitalAlerts->count(),
            ],
            'queue' => $queue,
            'completed_today' => $completedToday,
            'vital_alerts' => $vitalAlerts->take(5)->values(),
        ]);
    }

    public function schedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : today()->startOfDay();
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : today()->endOfDay();

        $items = Appointment::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->where('provider_id', $request->user()->id)
            ->whereBetween('starts_at', [$from, $to])
            ->with(['patient:id,first_name,last_name,mrn', 'provider:id,name'])
            ->orderBy('starts_at')
            ->get();

        return ApiResponse::success(['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'items' => $items]);
    }

    public function chart(Request $request, Patient $patient): JsonResponse
    {
        $patient->load(['primaryProvider:id,name']);

        return ApiResponse::success([
            'patient' => array_merge(
                $patient->only([
                    'id', 'mrn', 'first_name', 'last_name', 'date_of_birth', 'gender', 'phone', 'email',
                    'address', 'allergies', 'active_medications', 'emergency_contact', 'primary_provider_id',
                ]),
                ['primary_provider' => $patient->primaryProvider]
            ),
            'vitals' => Vital::query()
                ->where('patient_id', $patient->id)
                ->latest()
                ->limit(5)
                ->get()
                ->map(function (Vital $v) {
                    $tempF = $v->temperature_c !== null
                        ? round(($v->temperature_c * 9 / 5) + 32, 1)
                        : null;

                    return [
                        'id' => $v->id,
                        'bp_systolic' => $v->bp_systolic,
                        'bp_diastolic' => $v->bp_diastolic,
                        'pulse' => $v->pulse,
                        'spo2' => $v->spo2,
                        'bmi' => $v->bmi,
                        'temperature_c' => $v->temperature_c,
                        'temperature_f' => $tempF,
                        'alerts' => $v->alerts ?? [],
                        'alert_labels' => Vital::alertLabels($v->alerts ?? []),
                        'created_at' => optional($v->created_at)?->toIso8601String(),
                    ];
                }),
            'diagnoses' => Diagnosis::query()->where('patient_id', $patient->id)->latest()->limit(20)->get(),
            'notes' => ClinicalNote::query()->where('patient_id', $patient->id)->latest()->limit(20)->get(),
            'prescriptions' => Prescription::query()->where('patient_id', $patient->id)->latest()->limit(20)->get(),
            'lab_orders' => LabOrder::query()->where('patient_id', $patient->id)->latest()->limit(20)->get(),
            'treatment_plans' => TreatmentPlan::query()->where('patient_id', $patient->id)->latest()->limit(10)->get(),
            'follow_ups' => FollowUp::query()->where('patient_id', $patient->id)->latest()->limit(10)->get(),
            'documents' => Document::query()->where('patient_id', $patient->id)->latest()->limit(20)->get(['id', 'title', 'doc_type', 'mime_type', 'created_at']),
            'billing_suggestions' => BillingCode::query()
                ->where('patient_id', $patient->id)
                ->where('status', 'suggested')
                ->latest()
                ->limit(10)
                ->get(),
        ]);
    }

    public function summary(Request $request, Patient $patient): JsonResponse
    {
        return ApiResponse::success($this->summaries->generate($request->user(), $patient));
    }

    public function analytics(Request $request): JsonResponse
    {
        $user = $request->user();
        $clinicId = $user->clinic_id;

        return ApiResponse::success([
            'patients_seen_today' => Appointment::query()
                ->where('clinic_id', $clinicId)
                ->where('provider_id', $user->id)
                ->whereDate('starts_at', today())
                ->whereIn('status', ['completed', 'in_progress', 'ready_for_provider'])
                ->count(),
            'notes_signed_today' => ClinicalNote::query()
                ->where('author_id', $user->id)
                ->where('is_signed', true)
                ->whereDate('signed_at', today())
                ->count(),
            'rx_written_today' => Prescription::query()
                ->where('prescriber_id', $user->id)
                ->whereDate('created_at', today())
                ->count(),
            'labs_ordered_today' => LabOrder::query()
                ->where('ordered_by', $user->id)
                ->whereDate('created_at', today())
                ->count(),
        ]);
    }

    public function storeNote(Request $request, Patient $patient): JsonResponse
    {
        $data = $request->validate([
            'note_type' => ['required', 'string', 'in:soap,progress,follow_up,consultation,telehealth'],
            'content' => ['nullable', 'string', 'max:20000'],
            'structured' => ['nullable', 'array'],
            'structured.subjective' => ['nullable', 'string'],
            'structured.objective' => ['nullable', 'string'],
            'structured.assessment' => ['nullable', 'string'],
            'structured.plan' => ['nullable', 'string'],
            'appointment_id' => ['nullable', 'exists:appointments,id'],
            'sign' => ['sometimes', 'boolean'],
        ]);

        if (($data['note_type'] ?? '') === 'soap') {
            $request->validate([
                'structured.subjective' => ['required', 'string', 'min:1'],
                'structured.objective' => ['required', 'string', 'min:1'],
                'structured.assessment' => ['required', 'string', 'min:1'],
                'structured.plan' => ['required', 'string', 'min:1'],
            ]);
        }

        $structured = $data['structured'] ?? null;
        $content = trim((string) ($data['content'] ?? ''));
        if ($content === '' && is_array($structured)) {
            $content = trim(sprintf(
                "S: %s\nO: %s\nA: %s\nP: %s",
                $structured['subjective'] ?? '',
                $structured['objective'] ?? '',
                $structured['assessment'] ?? '',
                $structured['plan'] ?? '',
            ));
        }
        if ($content === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'content' => ['Note content is required.'],
            ]);
        }

        $note = ClinicalNote::create([
            'note_type' => $data['note_type'],
            'content' => $content,
            'structured' => $structured,
            'appointment_id' => $data['appointment_id'] ?? null,
            'clinic_id' => $patient->clinic_id,
            'patient_id' => $patient->id,
            'author_id' => $request->user()->id,
            'is_signed' => $request->boolean('sign'),
            'signed_at' => $request->boolean('sign') ? now() : null,
        ]);

        return ApiResponse::created($note, 'Clinical note saved');
    }

    public function signNote(Request $request, Patient $patient, ClinicalNote $note): JsonResponse
    {
        abort_unless($note->patient_id === $patient->id && $note->clinic_id === $request->user()->clinic_id, 403);
        abort_unless($note->author_id === $request->user()->id, 403, 'Only the author can sign this note.');

        $note->update(['is_signed' => true, 'signed_at' => now()]);

        return ApiResponse::success($note->fresh(), 'Note signed');
    }

    public function storeDiagnosis(Request $request, Patient $patient): JsonResponse
    {
        $data = $request->validate([
            'icd10_code' => ['required', 'string', 'max:16', 'regex:/^[A-TV-Z][0-9][0-9AB](?:\\.[0-9A-TV-Z]{1,4})?$/i'],
            'description' => ['required', 'string', 'max:500'],
        ]);

        $diagnosis = Diagnosis::create([
            ...$data,
            'clinic_id' => $patient->clinic_id,
            'patient_id' => $patient->id,
            'recorded_by' => $request->user()->id,
            'status' => 'active',
        ]);

        $suggestions = $this->coding->suggest($request->user(), $patient, $data['description']);

        return ApiResponse::created([
            'diagnosis' => $diagnosis,
            'coding_suggestions' => $suggestions,
        ], 'Diagnosis recorded');
    }

    public function storePrescription(Request $request, Patient $patient): JsonResponse
    {
        $user = $request->user();
        if (! $user->canPrescribe()) {
            abort(403, 'Prescription write is not permitted for this account.');
        }

        $data = $request->validate([
            'medication_name' => ['required', 'string', 'max:255'],
            'sig' => ['required', 'string', 'max:500'],
            'quantity' => ['nullable', 'string', 'max:64'],
            'refills' => ['nullable', 'string', 'max:32'],
            'pharmacy' => ['nullable', 'string', 'max:255'],
            'send' => ['boolean'],
        ]);

        $rx = Prescription::create([
            'clinic_id' => $patient->clinic_id,
            'patient_id' => $patient->id,
            'prescriber_id' => $user->id,
            'medication_name' => $data['medication_name'],
            'sig' => $data['sig'] ?? null,
            'quantity' => $data['quantity'] ?? null,
            'refills' => $data['refills'] ?? null,
            'pharmacy' => $data['pharmacy'] ?? null,
            'status' => 'draft',
        ]);

        if ($request->boolean('send')) {
            $rx = $this->surescripts->sendPrescription($rx, $user);
        }

        return ApiResponse::created($rx, 'Prescription created');
    }

    public function storeLabOrder(Request $request, Patient $patient): JsonResponse
    {
        $data = $request->validate([
            'test_name' => ['required', 'string', 'max:255'],
        ]);

        $order = LabOrder::create([
            'clinic_id' => $patient->clinic_id,
            'patient_id' => $patient->id,
            'ordered_by' => $request->user()->id,
            'test_name' => $data['test_name'],
            'status' => 'ordered',
        ]);

        return ApiResponse::created($order, 'Lab order placed');
    }

    public function updateLabResult(Request $request, LabOrder $labOrder): JsonResponse
    {
        abort_unless($labOrder->clinic_id === $request->user()->clinic_id, 403);

        $data = $request->validate([
            'result_summary' => ['nullable', 'string', 'max:2000'],
            'result_values' => ['nullable', 'array'],
            'status' => ['required', 'in:resulted,critical,cancelled'],
            'is_critical' => ['sometimes', 'boolean'],
        ]);

        $labOrder->update([
            ...$data,
            'resulted_at' => now(),
            'is_critical' => $request->boolean('is_critical') || ($data['status'] ?? '') === 'critical',
        ]);

        return ApiResponse::success($labOrder->fresh(), 'Lab result updated');
    }

    public function storeTreatmentPlan(Request $request, Patient $patient): JsonResponse
    {
        $data = $request->validate([
            'recommendations' => ['nullable', 'string', 'max:5000'],
            'home_care' => ['nullable', 'string', 'max:5000'],
            'follow_up_plan' => ['nullable', 'string', 'max:5000'],
            'referrals' => ['nullable', 'string', 'max:2000'],
        ]);

        $plan = TreatmentPlan::create([
            ...$data,
            'clinic_id' => $patient->clinic_id,
            'patient_id' => $patient->id,
            'created_by' => $request->user()->id,
        ]);

        return ApiResponse::created($plan, 'Treatment plan saved');
    }

    public function storeFollowUp(Request $request, Patient $patient): JsonResponse
    {
        $data = $request->validate([
            'due_at' => ['nullable', 'date'],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ]);

        $followUp = FollowUp::create([
            ...$data,
            'clinic_id' => $patient->clinic_id,
            'patient_id' => $patient->id,
            'created_by' => $request->user()->id,
            'status' => 'open',
        ]);

        return ApiResponse::created($followUp, 'Follow-up created');
    }

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

    public function startVisit(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($appointment->clinic_id === $request->user()->clinic_id, 403);
        abort_unless($appointment->provider_id === $request->user()->id, 403);

        $user = $request->user();

        // Always ensure PHI assignment so clinical writes stay allowed for this provider.
        if (! $user->assignedPatients()->where('patients.id', $appointment->patient_id)->exists()) {
            $user->assignedPatients()->attach($appointment->patient_id, [
                'clinic_id' => $appointment->clinic_id,
            ]);
        }
        $patient = $appointment->patient;
        if ($patient && ! $patient->primary_provider_id) {
            $patient->update(['primary_provider_id' => $user->id]);
        }

        if ($appointment->status === 'completed') {
            return ApiResponse::success($appointment->fresh(), 'Visit already completed');
        }

        if (! in_array($appointment->status, ['ready_for_provider', 'vitals_completed', 'in_progress'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => ['Visit cannot be started from status: '.$appointment->status],
            ]);
        }

        if ($appointment->status !== 'in_progress') {
            $appointment->update(['status' => 'in_progress']);
        }

        return ApiResponse::success($appointment->fresh(), 'Visit started');
    }

    public function completeVisit(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($appointment->clinic_id === $request->user()->clinic_id, 403);
        abort_unless($appointment->provider_id === $request->user()->id, 403);

        if (! in_array($appointment->status, ['in_progress', 'ready_for_provider', 'vitals_completed'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => ['Visit cannot be completed from status: '.$appointment->status],
            ]);
        }

        $appointment->update(['status' => 'completed']);

        return ApiResponse::success($appointment->fresh(), 'Visit completed');
    }
}
