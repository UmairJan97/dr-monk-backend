<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Payment;
use App\Models\Patient;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use App\Support\Roles;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FrontDeskController extends Controller
{
    public function __construct(
        private AppointmentBookingService $booking,
        private AuditService $audit,
        private NotificationService $notifications,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $clinicId = $request->user()->clinic_id;
        $today = today();

        $todayQuery = Appointment::query()
            ->where('clinic_id', $clinicId)
            ->whereDate('starts_at', $today)
            ->whereNotIn('status', ['cancelled']);

        $total = (clone $todayQuery)->count();
        $waiting = (clone $todayQuery)->whereIn('status', ['waiting', 'ready_for_vitals'])->count();
        $checkedIn = (clone $todayQuery)->whereNotIn('status', ['scheduled', 'cancelled', 'no_show'])->count();
        // Desk alerts: late scheduled (past start, still not checked in) + missing insurance on today's patients
        $lateScheduled = (clone $todayQuery)
            ->where('status', 'scheduled')
            ->where('starts_at', '<', now())
            ->count();
        $missingInsurance = Appointment::query()
            ->where('clinic_id', $clinicId)
            ->whereDate('starts_at', $today)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->whereHas('patient', fn ($q) => $q->whereDoesntHave('insurances'))
            ->count();
        $alerts = $lateScheduled + $missingInsurance;

        return ApiResponse::success([
            'date' => $today->toDateString(),
            'timezone' => $request->user()->clinic?->timezone ?? 'America/New_York',
            'stats' => [
                'todays_appointments' => $total,
                'waiting' => $waiting,
                'check_ins' => $checkedIn,
                'alerts' => $alerts,
            ],
        ]);
    }

    public function todayQueue(Request $request): JsonResponse
    {
        $items = Appointment::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->whereDate('starts_at', today())
            ->whereNotIn('status', ['cancelled'])
            ->with([
                'patient:id,first_name,last_name,mrn,date_of_birth,phone,photo_path',
                'provider:id,name',
            ])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Appointment $a) => $this->appointmentPayload($a));

        return ApiResponse::success(['items' => $items]);
    }

    public function appointments(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'provider_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
        ]);

        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : today()->startOfDay();
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : today()->endOfDay();

        $items = Appointment::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->whereBetween('starts_at', [$from, $to])
            ->when($data['provider_id'] ?? null, fn ($q, $id) => $q->where('provider_id', $id))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->with([
                'patient:id,first_name,last_name,mrn,date_of_birth,phone',
                'provider:id,name',
            ])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Appointment $a) => $this->appointmentPayload($a));

        return ApiResponse::success([
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'items' => $items,
        ]);
    }

    public function schedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => [
                'required',
                Rule::exists('patients', 'id')->where('clinic_id', $request->user()->clinic_id),
            ],
            'provider_id' => [
                'required',
                Rule::exists('users', 'id')->where('clinic_id', $request->user()->clinic_id),
            ],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'visit_type' => ['nullable', 'string', 'max:80'],
            'room' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $provider = User::query()->findOrFail($data['provider_id']);
        if (! $provider->hasAnyRole([Roles::DOCTOR, Roles::NP, Roles::COUNSELOR])) {
            throw ValidationException::withMessages([
                'provider_id' => ['Provider must be a Doctor, NP, or Counselor.'],
            ]);
        }

        $appointment = $this->booking->book($request->user(), $data);
        $this->audit->log(
            'appointment.create',
            'allowed',
            $request->user(),
            $request,
            $data['patient_id'],
            Appointment::class,
            $appointment->id
        );

        return ApiResponse::created(
            $this->appointmentPayload($appointment->load(['patient:id,first_name,last_name,mrn', 'provider:id,name'])),
            'Appointment scheduled'
        );
    }

    public function updateAppointment(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($appointment->clinic_id === $request->user()->clinic_id, 403);

        $data = $request->validate([
            'provider_id' => [
                'sometimes',
                Rule::exists('users', 'id')->where('clinic_id', $request->user()->clinic_id),
            ],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after:starts_at'],
            'visit_type' => ['nullable', 'string', 'max:80'],
            'room' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        if (isset($data['provider_id'])) {
            $provider = User::query()->findOrFail($data['provider_id']);
            if (! $provider->hasAnyRole([Roles::DOCTOR, Roles::NP, Roles::COUNSELOR])) {
                throw ValidationException::withMessages([
                    'provider_id' => ['Provider must be a Doctor, NP, or Counselor.'],
                ]);
            }
        }

        $updated = $this->booking->reschedule($request->user(), $appointment, $data);
        $this->audit->log(
            'appointment.reschedule',
            'allowed',
            $request->user(),
            $request,
            $updated->patient_id,
            Appointment::class,
            $updated->id
        );

        return ApiResponse::success(
            $this->appointmentPayload($updated->load(['patient:id,first_name,last_name,mrn,phone', 'provider:id,name'])),
            'Appointment updated'
        );
    }

    public function rebook(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($appointment->clinic_id === $request->user()->clinic_id, 403);

        if (! in_array($appointment->status, ['cancelled', 'no_show', 'completed'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Rebook is for cancelled, no-show, or completed visits. Use Edit/Reschedule otherwise.'],
            ]);
        }

        $data = $request->validate([
            'provider_id' => [
                'required',
                Rule::exists('users', 'id')->where('clinic_id', $request->user()->clinic_id),
            ],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'visit_type' => ['nullable', 'string', 'max:80'],
            'room' => ['nullable', 'string', 'max:40'],
        ]);

        $provider = User::query()->findOrFail($data['provider_id']);
        if (! $provider->hasAnyRole([Roles::DOCTOR, Roles::NP, Roles::COUNSELOR])) {
            throw ValidationException::withMessages([
                'provider_id' => ['Provider must be a Doctor, NP, or Counselor.'],
            ]);
        }

        $new = $this->booking->book($request->user(), [
            ...$data,
            'patient_id' => $appointment->patient_id,
            'visit_type' => $data['visit_type'] ?? $appointment->visit_type,
            'notes' => 'Rebooked from appointment #'.$appointment->id,
        ]);

        $this->audit->log(
            'appointment.rebook',
            'allowed',
            $request->user(),
            $request,
            $new->patient_id,
            Appointment::class,
            $new->id,
            ['from_appointment_id' => $appointment->id]
        );

        return ApiResponse::created(
            $this->appointmentPayload($new->load(['patient:id,first_name,last_name,mrn,phone', 'provider:id,name'])),
            'Appointment rebooked'
        );
    }

    public function checkIn(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($appointment->clinic_id === $request->user()->clinic_id, 403);

        if (in_array($appointment->status, ['cancelled', 'no_show', 'completed'], true)) {
            throw ValidationException::withMessages([
                'status' => ['This appointment cannot be checked in.'],
            ]);
        }

        if (! in_array($appointment->status, ['scheduled', 'waiting'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Patient is already past check-in (status: '.$appointment->status.').'],
            ]);
        }

        $appointment->update(['status' => 'waiting']);

        $this->audit->log(
            'appointment.check_in',
            'allowed',
            $request->user(),
            $request,
            $appointment->patient_id,
            Appointment::class,
            $appointment->id
        );

        $appointment->loadMissing(['patient:id,first_name,last_name,mrn', 'provider:id,name']);
        $mrn = $appointment->patient?->mrn;
        // No clinical PHI in body — MRN + queue cue only.
        $this->notifications->notifyVitalNurses(
            (int) $appointment->clinic_id,
            'appointment.checked_in',
            'Patient checked in',
            $mrn
                ? "Checked in (MRN {$mrn}) — ready for vitals."
                : 'Patient checked in — ready for vitals.',
            [
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'status' => 'waiting',
            ]
        );

        return ApiResponse::success(
            $this->appointmentPayload($appointment),
            'Patient checked in'
        );
    }

    public function cancel(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($appointment->clinic_id === $request->user()->clinic_id, 403);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if (in_array($appointment->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Appointment cannot be cancelled.'],
            ]);
        }

        $appointment->update([
            'status' => 'cancelled',
            'notes' => trim(($appointment->notes ? $appointment->notes."\n" : '').'Cancelled: '.($data['reason'] ?? 'Front Desk')),
        ]);

        $this->audit->log(
            'appointment.cancel',
            'allowed',
            $request->user(),
            $request,
            $appointment->patient_id,
            Appointment::class,
            $appointment->id
        );

        return ApiResponse::success(
            $this->appointmentPayload($appointment->fresh()->load(['patient:id,first_name,last_name,mrn', 'provider:id,name'])),
            'Appointment cancelled'
        );
    }

    public function markNoShow(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($appointment->clinic_id === $request->user()->clinic_id, 403);

        if (in_array($appointment->status, ['completed', 'cancelled', 'no_show'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Appointment cannot be marked no-show.'],
            ]);
        }

        $appointment->update(['status' => 'no_show']);

        $this->audit->log(
            'appointment.no_show',
            'allowed',
            $request->user(),
            $request,
            $appointment->patient_id,
            Appointment::class,
            $appointment->id
        );

        return ApiResponse::success(
            $this->appointmentPayload($appointment->fresh()->load(['patient:id,first_name,last_name,mrn', 'provider:id,name'])),
            'Marked no-show'
        );
    }

    public function collectPayment(Request $request): JsonResponse
    {
        $clinicId = $request->user()->clinic_id;

        $data = $request->validate([
            'appointment_id' => [
                'required',
                Rule::exists('appointments', 'id')->where('clinic_id', $clinicId),
            ],
            'patient_id' => [
                'required',
                Rule::exists('patients', 'id')->where('clinic_id', $clinicId),
            ],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
            'method' => ['required', 'in:cash,card,online,stripe'],
            'stripe_payment_intent_id' => ['nullable', 'string', 'max:120'],
        ]);

        $appointment = Appointment::query()->findOrFail($data['appointment_id']);
        if ((int) $appointment->patient_id !== (int) $data['patient_id']) {
            throw ValidationException::withMessages([
                'appointment_id' => ['Appointment does not belong to this patient.'],
            ]);
        }
        if (in_array($appointment->status, ['cancelled', 'no_show'], true)) {
            throw ValidationException::withMessages([
                'appointment_id' => ['Cannot collect payment for a cancelled or no-show visit.'],
            ]);
        }

        $payment = Payment::create([
            'clinic_id' => $clinicId,
            'patient_id' => $data['patient_id'],
            'appointment_id' => $data['appointment_id'],
            'recorded_by' => $request->user()->id,
            'method' => $data['method'],
            'stripe_payment_intent_id' => $data['stripe_payment_intent_id'] ?? null,
            'receipt_number' => 'RCPT-'.now()->format('YmdHis').'-'.random_int(100, 999),
            'status' => 'completed',
            'amount' => round((float) $data['amount'], 2),
        ]);

        $this->audit->log(
            'payment.collect',
            'allowed',
            $request->user(),
            $request,
            $data['patient_id'],
            Payment::class,
            $payment->id,
            [
                'method' => $payment->method,
                'amount' => $payment->amount,
                'appointment_id' => $payment->appointment_id,
            ]
        );

        return ApiResponse::created(
            $this->paymentPayload($payment->load('patient:id,first_name,last_name,mrn')),
            'Payment recorded'
        );
    }

    public function receipt(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($payment->clinic_id === $request->user()->clinic_id, 403);

        $payment->load(['patient:id,first_name,last_name,mrn', 'appointment:id,starts_at,visit_type,status']);
        $patient = $payment->patient;

        return ApiResponse::success([
            'receipt' => $this->paymentPayload($payment),
            'patient' => $patient ? [
                'id' => $patient->id,
                'mrn' => $patient->mrn,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'name' => trim($patient->first_name.' '.$patient->last_name),
            ] : null,
            'appointment' => $payment->appointment ? [
                'id' => $payment->appointment->id,
                'starts_at' => optional($payment->appointment->starts_at)?->toIso8601String(),
                'visit_type' => $payment->appointment->visit_type,
                'status' => $payment->appointment->status,
            ] : null,
            'clinic' => $request->user()->clinic?->name,
            'print_friendly' => true,
        ]);
    }

    public function payments(Request $request): JsonResponse
    {
        $items = Payment::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->when($request->integer('patient_id'), fn ($q, $id) => $q->where('patient_id', $id))
            ->whereDate('created_at', today())
            ->with(['patient:id,first_name,last_name,mrn', 'appointment:id,starts_at,visit_type'])
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (Payment $p) => $this->paymentPayload($p));

        return ApiResponse::success([
            'items' => $items,
        ]);
    }

    public function refundPayment(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($payment->clinic_id === $request->user()->clinic_id, 403);

        if ($payment->status === 'refunded') {
            throw ValidationException::withMessages([
                'status' => ['Payment is already refunded.'],
            ]);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $payment->update([
            'status' => 'refunded',
        ]);

        $this->audit->log(
            'payment.refund',
            'allowed',
            $request->user(),
            $request,
            $payment->patient_id,
            Payment::class,
            $payment->id,
            ['reason' => $data['reason'] ?? 'Front Desk refund']
        );

        return ApiResponse::success(
            $this->paymentPayload($payment->fresh()->load('patient:id,first_name,last_name,mrn')),
            'Refund recorded'
        );
    }

    public function patientLedger(Request $request, int $patientId): JsonResponse
    {
        $patient = Patient::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->findOrFail($patientId);

        $items = Payment::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->where('patient_id', $patient->id)
            ->with(['patient:id,first_name,last_name,mrn', 'appointment:id,starts_at,visit_type'])
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (Payment $p) => $this->paymentPayload($p));

        $paid = Payment::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->where('patient_id', $patient->id)
            ->where('status', 'completed')
            ->sum('amount');
        $refunded = Payment::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->where('patient_id', $patient->id)
            ->where('status', 'refunded')
            ->sum('amount');

        return ApiResponse::success([
            'patient' => [
                'id' => $patient->id,
                'mrn' => $patient->mrn,
                'name' => trim($patient->first_name.' '.$patient->last_name),
            ],
            'balance_due' => 0.0, // V1: desk collects visit payments; open invoices live in Billing
            'total_paid' => round((float) $paid, 2),
            'total_refunded' => round((float) $refunded, 2),
            'items' => $items,
            'note' => 'Formal A/R balances are owned by Billing. This ledger shows Front Desk receipts/refunds.',
        ]);
    }

    public function providers(Request $request): JsonResponse
    {
        $providers = User::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->where('is_active', true)
            ->role([Roles::DOCTOR, Roles::NP, Roles::COUNSELOR])
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'roles' => $u->getRoleNames()->values()->all(),
            ]);

        return ApiResponse::success(['items' => $providers]);
    }

    private function appointmentPayload(Appointment $appointment): array
    {
        $patient = $appointment->patient;

        return [
            'id' => $appointment->id,
            'status' => $appointment->status,
            'visit_type' => $appointment->visit_type,
            'room' => $appointment->room,
            'starts_at' => optional($appointment->starts_at)?->toIso8601String(),
            'ends_at' => optional($appointment->ends_at)?->toIso8601String(),
            'patient' => $patient ? [
                'id' => $patient->id,
                'mrn' => $patient->mrn,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'date_of_birth' => optional($patient->date_of_birth)?->format('Y-m-d'),
                'phone' => $patient->phone,
                'photo_path' => $patient->photo_path ?? null,
            ] : null,
            'provider' => $appointment->provider ? [
                'id' => $appointment->provider->id,
                'name' => $appointment->provider->name,
            ] : null,
            'can_check_in' => $appointment->status === 'scheduled',
            'can_cancel' => $appointment->status === 'scheduled',
            'can_no_show' => $appointment->status === 'scheduled',
            'can_edit' => in_array($appointment->status, ['scheduled', 'waiting'], true),
            'can_rebook' => in_array($appointment->status, ['cancelled', 'no_show', 'completed'], true),
        ];
    }

    private function paymentPayload(Payment $payment): array
    {
        $patient = $payment->relationLoaded('patient') ? $payment->patient : null;

        return [
            'id' => $payment->id,
            'patient_id' => $payment->patient_id,
            'appointment_id' => $payment->appointment_id,
            'amount' => (float) $payment->amount,
            'method' => $payment->method,
            'receipt_number' => $payment->receipt_number,
            'status' => $payment->status,
            'currency' => 'USD',
            'created_at' => optional($payment->created_at)?->toIso8601String(),
            'patient' => $patient ? [
                'id' => $patient->id,
                'mrn' => $patient->mrn,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'name' => trim($patient->first_name.' '.$patient->last_name),
            ] : null,
        ];
    }
}
