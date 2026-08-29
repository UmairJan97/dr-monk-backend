<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BillingCode;
use App\Models\Claim;
use App\Models\Document;
use App\Models\LabOrder;
use App\Models\Prescription;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClinicalLibraryController extends Controller
{
    public function documents(Request $request): JsonResponse
    {
        $clinicId = $request->user()->clinic_id;

        $items = Document::query()
            ->where('clinic_id', $clinicId)
            ->whereNotNull('patient_id')
            ->with([
                'patient:id,first_name,last_name,mrn',
                'uploader:id,name',
            ])
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (Document $doc) => [
                'id' => $doc->id,
                'title' => $doc->title,
                'doc_type' => $doc->doc_type,
                'mime_type' => $doc->mime_type,
                'byte_size' => $doc->byte_size,
                'is_signed' => (bool) $doc->is_signed,
                'created_at' => optional($doc->created_at)?->toIso8601String(),
                'patient' => $doc->patient ? [
                    'id' => $doc->patient->id,
                    'first_name' => $doc->patient->first_name,
                    'last_name' => $doc->patient->last_name,
                    'mrn' => $doc->patient->mrn,
                ] : null,
                'uploader' => $doc->uploader ? [
                    'id' => $doc->uploader->id,
                    'name' => $doc->uploader->name,
                ] : null,
            ]);

        return ApiResponse::success(['items' => $items]);
    }

    public function labOrders(Request $request): JsonResponse
    {
        $clinicId = $request->user()->clinic_id;

        $items = LabOrder::query()
            ->where('clinic_id', $clinicId)
            ->with([
                'patient:id,first_name,last_name,mrn',
                'orderedBy:id,name',
            ])
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (LabOrder $lab) => [
                'id' => $lab->id,
                'test_name' => $lab->test_name,
                'status' => $lab->status,
                'is_critical' => (bool) $lab->is_critical,
                'result_summary' => $lab->result_summary,
                'result_values' => $lab->result_values,
                'resulted_at' => optional($lab->resulted_at)?->toIso8601String(),
                'created_at' => optional($lab->created_at)?->toIso8601String(),
                'patient' => $lab->patient ? [
                    'id' => $lab->patient->id,
                    'first_name' => $lab->patient->first_name,
                    'last_name' => $lab->patient->last_name,
                    'mrn' => $lab->patient->mrn,
                ] : null,
                'ordered_by' => $lab->orderedBy ? [
                    'id' => $lab->orderedBy->id,
                    'name' => $lab->orderedBy->name,
                ] : null,
            ]);

        return ApiResponse::success(['items' => $items]);
    }

    public function prescriptions(Request $request): JsonResponse
    {
        $clinicId = $request->user()->clinic_id;

        $items = Prescription::query()
            ->where('clinic_id', $clinicId)
            ->with([
                'patient:id,first_name,last_name,mrn',
                'prescriber:id,name',
            ])
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (Prescription $rx) => [
                'id' => $rx->id,
                'medication_name' => $rx->medication_name,
                'sig' => $rx->sig,
                'quantity' => $rx->quantity,
                'refills' => $rx->refills,
                'pharmacy' => $rx->pharmacy,
                'status' => $rx->status,
                'created_at' => optional($rx->created_at)?->toIso8601String(),
                'patient' => $rx->patient ? [
                    'id' => $rx->patient->id,
                    'first_name' => $rx->patient->first_name,
                    'last_name' => $rx->patient->last_name,
                    'mrn' => $rx->patient->mrn,
                ] : null,
                'prescriber' => $rx->prescriber ? [
                    'id' => $rx->prescriber->id,
                    'name' => $rx->prescriber->name,
                ] : null,
            ]);

        return ApiResponse::success(['items' => $items]);
    }

    public function billingOverview(Request $request): JsonResponse
    {
        $clinicId = $request->user()->clinic_id;

        $codes = BillingCode::query()
            ->where('clinic_id', $clinicId)
            ->with(['patient:id,first_name,last_name,mrn'])
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (BillingCode $code) => [
                'id' => $code->id,
                'code' => $code->code,
                'code_system' => $code->code_system,
                'description' => $code->description,
                'modifier' => $code->modifier,
                'status' => $code->status,
                'source' => $code->source,
                'created_at' => optional($code->created_at)?->toIso8601String(),
                'patient' => $code->patient ? [
                    'id' => $code->patient->id,
                    'first_name' => $code->patient->first_name,
                    'last_name' => $code->patient->last_name,
                    'mrn' => $code->patient->mrn,
                ] : null,
            ]);

        $claims = Claim::query()
            ->where('clinic_id', $clinicId)
            ->with(['patient:id,first_name,last_name,mrn'])
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (Claim $claim) => [
                'id' => $claim->id,
                'status' => $claim->status,
                'billed_amount' => $claim->billed_amount,
                'paid_amount' => $claim->paid_amount,
                'created_at' => optional($claim->created_at)?->toIso8601String(),
                'patient' => $claim->patient ? [
                    'id' => $claim->patient->id,
                    'first_name' => $claim->patient->first_name,
                    'last_name' => $claim->patient->last_name,
                    'mrn' => $claim->patient->mrn,
                ] : null,
            ]);

        $stats = [
            'codes_suggested' => BillingCode::query()->where('clinic_id', $clinicId)->where('status', 'suggested')->count(),
            'codes_confirmed' => BillingCode::query()->where('clinic_id', $clinicId)->whereIn('status', ['accepted', 'confirmed'])->count(),
            'claims_draft' => Claim::query()->where('clinic_id', $clinicId)->where('status', 'draft')->count(),
            'claims_submitted' => Claim::query()->where('clinic_id', $clinicId)->whereIn('status', ['submitted', 'accepted'])->count(),
            'claims_denied' => Claim::query()->where('clinic_id', $clinicId)->where('status', 'denied')->count(),
        ];

        return ApiResponse::success([
            'stats' => $stats,
            'codes' => $codes,
            'claims' => $claims,
        ]);
    }
}
