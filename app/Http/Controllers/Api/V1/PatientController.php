<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Services\AuditService;
use App\Support\ApiResponse;
use App\Support\PhiGate;
use App\Support\Roles;
use App\Support\UsValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PatientController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasRole(Roles::SUPER_ADMIN)) {
            return ApiResponse::error('Patient charts are not available for SaaS Super Admin.', 403, 'FORBIDDEN');
        }

        $q = Patient::query()->where('clinic_id', $user->clinic_id);

        if ($search = $request->string('q')->toString()) {
            if (mb_strlen($search) > 100) {
                return ApiResponse::validation(['q' => ['Search query is too long.']]);
            }

            $like = '%'.strtolower($search).'%';
            $q->where(function ($inner) use ($like) {
                $inner->whereRaw('LOWER(first_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('mrn', 'like', $like);
            });
        }

        if ($user->hasAnyRole(Roles::clinicalProviders())) {
            $q->where(function ($inner) use ($user) {
                $inner->where('primary_provider_id', $user->id)
                    ->orWhereHas('providers', fn ($p) => $p->where('users.id', $user->id));
            });
        }

        $patients = $q->latest()->paginate(25);

        if ($user->hasAnyRole(Roles::demographicsOnly())) {
            $patients->getCollection()->transform(fn (Patient $p) => PhiGate::demographicsPayload($p));
        }

        return ApiResponse::success($patients);
    }

    public function store(Request $request): JsonResponse
    {
        $requirePhone = $request->user()->hasRole(Roles::FRONT_DESK);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => UsValidation::dateOfBirth(),
            'gender' => ['nullable', 'string', 'max:40'],
            'phone' => UsValidation::phone($requirePhone),
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => UsValidation::state(),
            'zip' => UsValidation::zip(),
            'primary_provider_id' => [
                'nullable',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('clinic_id', $request->user()->clinic_id)),
            ],
            'emergency_contact' => ['nullable', 'array'],
            'emergency_contact.name' => ['nullable', 'string', 'max:120'],
            'emergency_contact.phone' => UsValidation::phone(),
            'emergency_contact.relation' => ['nullable', 'string', 'max:60'],
            'insurance' => ['nullable', 'array'],
            'insurance.type' => ['nullable', 'string', 'in:primary,secondary'],
            'insurance.payer_name' => ['nullable', 'string', 'max:255'],
            'insurance.policy_number' => ['required_with:insurance.payer_name', 'nullable', 'string', 'max:255'],
            'insurance.group_number' => ['nullable', 'string', 'max:255'],
            'insurance.expires_on' => ['nullable', 'date'],
            'secondary_insurance' => ['nullable', 'array'],
            'secondary_insurance.payer_name' => ['nullable', 'string', 'max:255'],
            'secondary_insurance.policy_number' => ['required_with:secondary_insurance.payer_name', 'nullable', 'string', 'max:255'],
            'secondary_insurance.group_number' => ['nullable', 'string', 'max:255'],
            'pcp_name' => ['nullable', 'string', 'max:120'],
        ]);

        // Front Desk demographics + emergency contact only — never clinical free-text.
        if ($request->user()->hasRole(Roles::FRONT_DESK)) {
            unset($data['allergies'], $data['active_medications']);
            $request->request->remove('allergies');
            $request->request->remove('active_medications');
        }

        $data['phone'] = UsValidation::normalizePhone($data['phone'] ?? null);
        $data['state'] = UsValidation::normalizeState($data['state'] ?? null);
        $data['zip'] = UsValidation::normalizeZip($data['zip'] ?? null);
        if (! empty($data['emergency_contact']['phone'])) {
            $data['emergency_contact']['phone'] = UsValidation::normalizePhone($data['emergency_contact']['phone']);
        }
        if (! empty($data['pcp_name'])) {
            $data['emergency_contact'] = array_merge($data['emergency_contact'] ?? [], [
                'pcp_name' => $data['pcp_name'],
            ]);
        }

        $addressParts = array_filter([
            $data['address'] ?? null,
            $data['city'] ?? null,
            isset($data['state'], $data['zip']) ? trim(($data['state'] ?? '').' '.($data['zip'] ?? '')) : ($data['state'] ?? $data['zip'] ?? null),
        ]);
        if ($addressParts !== []) {
            $data['address'] = implode(', ', $addressParts);
        }

        $patient = Patient::create([
            ...collect($data)->except(['insurance', 'secondary_insurance', 'city', 'state', 'zip', 'pcp_name'])->all(),
            'clinic_id' => $request->user()->clinic_id,
            'mrn' => 'MRN-'.strtoupper(Str::random(8)),
        ]);

        if (! empty($data['insurance']['payer_name'])) {
            PatientInsurance::create([
                'clinic_id' => $patient->clinic_id,
                'patient_id' => $patient->id,
                'type' => $data['insurance']['type'] ?? 'primary',
                'payer_name' => $data['insurance']['payer_name'],
                'policy_number' => $data['insurance']['policy_number'] ?? '',
                'group_number' => $data['insurance']['group_number'] ?? null,
                'expires_on' => $data['insurance']['expires_on'] ?? null,
            ]);
        }

        if (! empty($data['secondary_insurance']['payer_name'])) {
            PatientInsurance::create([
                'clinic_id' => $patient->clinic_id,
                'patient_id' => $patient->id,
                'type' => 'secondary',
                'payer_name' => $data['secondary_insurance']['payer_name'],
                'policy_number' => $data['secondary_insurance']['policy_number'] ?? '',
                'group_number' => $data['secondary_insurance']['group_number'] ?? null,
            ]);
        }

        $this->audit->log('patient.create', 'allowed', $request->user(), $request, $patient->id, Patient::class, $patient->id);

        $payload = $request->user()->hasAnyRole(Roles::demographicsOnly())
            ? PhiGate::demographicsPayload($patient)
            : $patient->toArray();

        return ApiResponse::created($payload, 'Patient registered');
    }

    public function show(Request $request, Patient $patient): JsonResponse
    {
        if ($request->user()->hasAnyRole(Roles::demographicsOnly())) {
            return ApiResponse::success(PhiGate::demographicsPayload($patient));
        }

        $patient->load([
            'primaryProvider:id,name',
            'vitals' => fn ($q) => $q->latest()->limit(1),
        ]);

        return ApiResponse::success($patient);
    }
}
