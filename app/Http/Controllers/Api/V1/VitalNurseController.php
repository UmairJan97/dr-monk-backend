<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Vital;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use App\Support\PhiGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VitalNurseController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    public function dashboard(Request $request): JsonResponse
    {
        $clinicId = $request->user()->clinic_id;

        $queue = Appointment::query()
            ->where('clinic_id', $clinicId)
            ->whereIn('status', ['waiting', 'ready_for_vitals', 'vitals_completed'])
            ->whereDate('starts_at', today());

        return ApiResponse::success([
            'stats' => [
                'waiting' => (clone $queue)->where('status', 'waiting')->count(),
                'in_vitals' => (clone $queue)->where('status', 'ready_for_vitals')->count(),
                'vitals_done' => (clone $queue)->where('status', 'vitals_completed')->count(),
                'alerts_today' => Vital::query()
                    ->where('clinic_id', $clinicId)
                    ->whereDate('created_at', today())
                    ->whereNotNull('alerts')
                    ->get()
                    ->filter(fn (Vital $v) => ! empty($v->alerts))
                    ->count(),
            ],
        ]);
    }

    public function queue(Request $request): JsonResponse
    {
        $items = Appointment::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->whereIn('status', ['waiting', 'ready_for_vitals', 'vitals_completed'])
            ->whereDate('starts_at', today())
            ->with(['patient:id,first_name,last_name,date_of_birth,mrn,gender,phone', 'provider:id,name'])
            ->orderBy('starts_at')
            ->get()
            ->map(function (Appointment $a) {
                $hasVitals = Vital::query()->where('appointment_id', $a->id)->exists();

                return [
                    'id' => $a->id,
                    'status' => $a->status,
                    'visit_type' => $a->visit_type,
                    'starts_at' => optional($a->starts_at)?->toIso8601String(),
                    'patient' => $a->patient ? PhiGate::demographicsPayload($a->patient) : null,
                    'provider' => $a->provider ? ['id' => $a->provider->id, 'name' => $a->provider->name] : null,
                    'has_vitals' => $hasVitals,
                    'can_start' => $a->status === 'waiting',
                    'can_complete' => $hasVitals && in_array($a->status, ['ready_for_vitals', 'vitals_completed'], true),
                ];
            });

        return ApiResponse::success(['items' => $items]);
    }

    public function patientOverview(Request $request, Patient $patient): JsonResponse
    {
        abort_unless($request->user()->canAccessPatient($patient), 403);

        $latest = Vital::query()
            ->where('patient_id', $patient->id)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (Vital $v) => $this->vitalPayload($v));

        return ApiResponse::success([
            'patient' => PhiGate::demographicsPayload($patient),
            'latest_vitals' => $latest,
            'restricted' => [
                'diagnoses' => false,
                'prescriptions' => false,
                'billing' => false,
            ],
        ]);
    }

    public function history(Request $request, Patient $patient): JsonResponse
    {
        abort_unless($request->user()->canAccessPatient($patient), 403);

        $items = Vital::query()
            ->where('patient_id', $patient->id)
            ->where('clinic_id', $request->user()->clinic_id)
            ->with('recorder:id,name')
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn (Vital $v) => $this->vitalPayload($v));

        return ApiResponse::success(['items' => $items]);
    }

    public function startVitals(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($appointment->clinic_id === $request->user()->clinic_id, 403);

        if (! in_array($appointment->status, ['waiting', 'ready_for_vitals'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Appointment is not waiting for vitals.'],
            ]);
        }

        $appointment->update(['status' => 'ready_for_vitals']);

        return ApiResponse::success([
            'appointment' => $appointment->fresh(),
        ], 'Vitals intake started');
    }

    public function storeVitals(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => [
                'required',
                Rule::exists('patients', 'id')->where('clinic_id', $request->user()->clinic_id),
            ],
            'appointment_id' => [
                'nullable',
                Rule::exists('appointments', 'id')->where('clinic_id', $request->user()->clinic_id),
            ],
            // US customary (preferred in UI)
            'height_in' => ['nullable', 'numeric', 'min:20', 'max:90'],
            'weight_lb' => ['nullable', 'numeric', 'min:5', 'max:800'],
            'temperature_f' => ['nullable', 'numeric', 'min:90', 'max:110'],
            // Metric (still accepted)
            'height_cm' => ['nullable', 'numeric', 'min:30', 'max:300'],
            'weight_kg' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'temperature_c' => ['nullable', 'numeric', 'min:30', 'max:45'],
            'bp_systolic' => ['required', 'integer', 'min:50', 'max:300'],
            'bp_diastolic' => ['required', 'integer', 'min:30', 'max:200'],
            'pulse' => ['required', 'integer', 'min:30', 'max:220'],
            'respiratory_rate' => ['nullable', 'integer', 'min:5', 'max:60'],
            'spo2' => ['required', 'integer', 'min:70', 'max:100'],
            'pain_scale' => ['nullable', 'integer', 'min:0', 'max:10'],
            'glucose' => ['nullable', 'numeric', 'min:20', 'max:800'],
        ]);

        if ((int) $data['bp_systolic'] <= (int) $data['bp_diastolic']) {
            throw ValidationException::withMessages([
                'bp_systolic' => ['Systolic BP must be greater than diastolic.'],
            ]);
        }

        $heightCm = isset($data['height_in'])
            ? round((float) $data['height_in'] * 2.54, 2)
            : (isset($data['height_cm']) ? (float) $data['height_cm'] : null);
        $weightKg = isset($data['weight_lb'])
            ? round((float) $data['weight_lb'] * 0.45359237, 2)
            : (isset($data['weight_kg']) ? (float) $data['weight_kg'] : null);
        $tempC = isset($data['temperature_f'])
            ? round((((float) $data['temperature_f'] - 32) * 5 / 9), 2)
            : (isset($data['temperature_c']) ? (float) $data['temperature_c'] : null);

        if ($tempC === null) {
            throw ValidationException::withMessages([
                'temperature_f' => ['Temperature (°F) is required.'],
            ]);
        }

        $patient = Patient::query()->findOrFail($data['patient_id']);
        abort_unless($request->user()->canAccessPatient($patient), 403);

        $metric = [
            'height_cm' => $heightCm,
            'weight_kg' => $weightKg,
            'temperature_c' => $tempC,
            'bp_systolic' => (int) $data['bp_systolic'],
            'bp_diastolic' => (int) $data['bp_diastolic'],
            'pulse' => (int) $data['pulse'],
            'respiratory_rate' => isset($data['respiratory_rate']) ? (int) $data['respiratory_rate'] : null,
            'spo2' => (int) $data['spo2'],
            'pain_scale' => isset($data['pain_scale']) ? (int) $data['pain_scale'] : null,
            'glucose' => isset($data['glucose']) ? (float) $data['glucose'] : null,
        ];

        $bmi = Vital::calculateBmi($heightCm, $weightKg);
        $alerts = Vital::detectAlerts([...$metric, 'bmi' => $bmi]);

        $vital = Vital::create([
            ...$metric,
            'patient_id' => $data['patient_id'],
            'appointment_id' => $data['appointment_id'] ?? null,
            'clinic_id' => $request->user()->clinic_id,
            'recorded_by' => $request->user()->id,
            'bmi' => $bmi,
            'alerts' => $alerts,
        ]);

        if (! empty($data['appointment_id'])) {
            Appointment::where('id', $data['appointment_id'])->update([
                'status' => 'vitals_completed',
            ]);
        }

        return ApiResponse::created([
            'vital' => $this->vitalPayload($vital),
            'bmi' => $bmi,
            'alerts' => $alerts,
            'alert_labels' => Vital::alertLabels($alerts),
        ], 'Vitals recorded');
    }

    public function completeVitals(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($appointment->clinic_id === $request->user()->clinic_id, 403);

        if (! Vital::query()->where('appointment_id', $appointment->id)->exists()) {
            throw ValidationException::withMessages([
                'vitals' => ['Save vitals before notifying the provider.'],
            ]);
        }

        if (! in_array($appointment->status, ['ready_for_vitals', 'vitals_completed', 'waiting'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Cannot complete vitals for this appointment status.'],
            ]);
        }

        $appointment->update(['status' => 'ready_for_provider']);

        $created = $this->notifications->notifyProviders(
            (int) $appointment->clinic_id,
            'vitals.ready',
            'Patient ready for provider',
            'Vitals complete — patient is ready in queue.',
            [
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'status' => 'ready_for_provider',
            ],
            $appointment->provider_id
        );

        return ApiResponse::success([
            'appointment' => $appointment->fresh(),
            'notifications_sent' => count($created),
        ], 'Provider notified: patient ready');
    }

    private function vitalPayload(Vital $vital): array
    {
        $tempF = $vital->temperature_c !== null
            ? round(($vital->temperature_c * 9 / 5) + 32, 1)
            : null;
        $heightIn = $vital->height_cm !== null ? round($vital->height_cm / 2.54, 1) : null;
        $weightLb = $vital->weight_kg !== null ? round($vital->weight_kg / 0.45359237, 1) : null;

        return [
            'id' => $vital->id,
            'patient_id' => $vital->patient_id,
            'appointment_id' => $vital->appointment_id,
            'bp_systolic' => $vital->bp_systolic,
            'bp_diastolic' => $vital->bp_diastolic,
            'pulse' => $vital->pulse,
            'respiratory_rate' => $vital->respiratory_rate,
            'spo2' => $vital->spo2,
            'pain_scale' => $vital->pain_scale,
            'glucose' => $vital->glucose,
            'bmi' => $vital->bmi,
            'alerts' => $vital->alerts ?? [],
            'alert_labels' => Vital::alertLabels($vital->alerts ?? []),
            'temperature_c' => $vital->temperature_c,
            'temperature_f' => $tempF,
            'height_cm' => $vital->height_cm,
            'height_in' => $heightIn,
            'weight_kg' => $vital->weight_kg,
            'weight_lb' => $weightLb,
            'created_at' => optional($vital->created_at)?->toIso8601String(),
            'recorder' => $vital->relationLoaded('recorder') && $vital->recorder
                ? ['id' => $vital->recorder->id, 'name' => $vital->recorder->name]
                : null,
        ];
    }
}
