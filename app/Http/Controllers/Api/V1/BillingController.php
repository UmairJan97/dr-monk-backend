<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BillingCode;
use App\Models\Claim;
use App\Models\Expense;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Payment;
use App\Services\Ai\CodingSuggestService;
use App\Services\Integrations\ClearinghouseService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(
        private ClearinghouseService $clearinghouse,
        private CodingSuggestService $coding,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $clinicId = $request->user()->clinic_id;

        $claims = Claim::query()->where('clinic_id', $clinicId);

        return ApiResponse::success([
            'stats' => [
                'draft_claims' => (clone $claims)->where('status', 'draft')->count(),
                'submitted_claims' => (clone $claims)->whereIn('status', ['submitted', 'accepted'])->count(),
                'denied_claims' => (clone $claims)->where('status', 'denied')->count(),
                'pending_codes' => BillingCode::query()
                    ->where('clinic_id', $clinicId)
                    ->where('status', 'suggested')
                    ->count(),
                'expenses_mtd' => (float) Expense::query()
                    ->where('clinic_id', $clinicId)
                    ->whereMonth('created_at', now()->month)
                    ->sum('amount'),
                'billed_mtd' => (float) (clone $claims)
                    ->whereMonth('created_at', now()->month)
                    ->sum('billed_amount'),
            ],
            'currency' => config('drmonk.currency', 'USD'),
        ]);
    }

    public function confirmCode(Request $request, BillingCode $billingCode): JsonResponse
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

        return ApiResponse::success($billingCode->fresh(), 'Code updated');
    }

    public function pendingCodes(Request $request): JsonResponse
    {
        $items = BillingCode::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->where('status', 'suggested')
            ->with(['patient:id,first_name,last_name,mrn'])
            ->latest()
            ->limit(50)
            ->get();

        return ApiResponse::success(['items' => $items]);
    }

    public function suggestCodes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'text' => ['nullable', 'string', 'max:5000'],
        ]);

        $patient = Patient::query()->findOrFail($data['patient_id']);
        abort_unless($patient->clinic_id === $request->user()->clinic_id, 403);

        $suggestions = $this->coding->suggest($request->user(), $patient, $data['text'] ?? null);

        return ApiResponse::success(['items' => $suggestions], 'Coding suggestions ready');
    }

    public function createClaim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'appointment_id' => ['nullable', 'exists:appointments,id'],
            'billed_amount' => ['required', 'numeric', 'min:0.01'],
            'submit' => ['boolean'],
        ]);

        $patient = Patient::query()->findOrFail($data['patient_id']);
        abort_unless($patient->clinic_id === $request->user()->clinic_id, 403);

        $claim = Claim::create([
            'clinic_id' => $request->user()->clinic_id,
            'patient_id' => $data['patient_id'],
            'appointment_id' => $data['appointment_id'] ?? null,
            'created_by' => $request->user()->id,
            'status' => 'draft',
            'billed_amount' => $data['billed_amount'],
        ]);

        if ($request->boolean('submit')) {
            $claim = $this->clearinghouse->submitClaim($claim);
        }

        return ApiResponse::created($claim, $request->boolean('submit') ? 'Claim submitted (sandbox)' : 'Claim drafted');
    }

    public function eligibility(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payer_name' => ['required', 'string'],
            'policy_number' => ['required', 'string'],
            'expires_on' => ['nullable', 'date'],
            'patient_id' => ['nullable', 'exists:patients,id'],
        ]);

        $result = $this->clearinghouse->checkEligibility($data);

        if (! empty($data['patient_id'])) {
            $insurance = PatientInsurance::query()
                ->where('clinic_id', $request->user()->clinic_id)
                ->where('patient_id', $data['patient_id'])
                ->latest('id')
                ->first();
            if ($insurance) {
                $insurance->update(['eligibility_snapshot' => $result]);
            }
        }

        return ApiResponse::success($result, 'Eligibility checked (sandbox)');
    }

    public function storeExpense(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'in:supplies,rent,utilities,payroll,software,marketing,maintenance,insurance,misc'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:2000'],
            'incurred_on' => ['nullable', 'date'],
        ]);

        $expense = Expense::create([
            ...$data,
            'clinic_id' => $request->user()->clinic_id,
            'recorded_by' => $request->user()->id,
        ]);

        return ApiResponse::created($expense, 'Expense recorded');
    }

    public function expenses(Request $request): JsonResponse
    {
        $items = Expense::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->latest()
            ->limit(50)
            ->get();

        return ApiResponse::success(['items' => $items]);
    }

    public function ledger(Request $request): JsonResponse
    {
        $claims = Claim::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->with(['patient:id,first_name,last_name,mrn'])
            ->latest()
            ->paginate(50);

        $denied = Claim::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->where('status', 'denied')
            ->count();

        $receivable = (float) Claim::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->whereIn('status', ['submitted', 'accepted', 'draft'])
            ->sum('billed_amount');

        return ApiResponse::success([
            'claims' => $claims->items(),
            'pagination' => [
                'current_page' => $claims->currentPage(),
                'last_page' => $claims->lastPage(),
                'total' => $claims->total(),
            ],
            'receivables_total' => $receivable,
            'denials_count' => $denied,
            'currency' => config('drmonk.currency', 'USD'),
        ]);
    }

    public function payments(Request $request): JsonResponse
    {
        $items = Payment::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->with(['patient:id,first_name,last_name,mrn'])
            ->latest()
            ->limit(50)
            ->get();

        $collected = (float) Payment::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->whereMonth('created_at', now()->month)
            ->sum('amount');

        return ApiResponse::success([
            'items' => $items,
            'collected_mtd' => $collected,
            'currency' => config('drmonk.currency', 'USD'),
        ]);
    }

    public function insurances(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['nullable', 'exists:patients,id'],
        ]);

        $q = PatientInsurance::query()->where('clinic_id', $request->user()->clinic_id);
        if (! empty($data['patient_id'])) {
            $q->where('patient_id', $data['patient_id']);
        }

        $items = $q->with(['patient:id,first_name,last_name,mrn'])
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (PatientInsurance $ins) => [
                'id' => $ins->id,
                'patient_id' => $ins->patient_id,
                'patient' => $ins->patient,
                'type' => $ins->type,
                'payer_name' => $ins->payer_name,
                'policy_number' => $ins->policy_number,
                'group_number' => $ins->group_number,
                'expires_on' => $ins->expires_on,
                'has_eligibility' => ! empty($ins->eligibility_snapshot),
            ]);

        return ApiResponse::success(['items' => $items]);
    }
}
