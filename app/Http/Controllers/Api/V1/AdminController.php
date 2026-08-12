<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InviteUserRequest;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Claim;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\User;
use App\Models\UserInvitation;
use App\Models\Vital;
use Carbon\Carbon;
use App\Services\AuditService;
use App\Support\ApiResponse;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function dashboard(Request $request): JsonResponse
    {
        $clinicId = $request->user()->clinic_id;
        $today = today();
        $yesterday = today()->subDay();
        $weekStart = now()->subDays(6)->startOfDay();
        $prevWeekStart = now()->subDays(13)->startOfDay();
        $prevWeekEnd = now()->subDays(7)->endOfDay();

        $patientsToday = (int) Appointment::query()
            ->where('clinic_id', $clinicId)
            ->whereDate('starts_at', $today)
            ->distinct()
            ->count('patient_id');
        $patientsYesterday = (int) Appointment::query()
            ->where('clinic_id', $clinicId)
            ->whereDate('starts_at', $yesterday)
            ->distinct()
            ->count('patient_id');

        $apptsToday = Appointment::query()
            ->where('clinic_id', $clinicId)
            ->whereDate('starts_at', $today)
            ->count();
        $apptsYesterday = Appointment::query()
            ->where('clinic_id', $clinicId)
            ->whereDate('starts_at', $yesterday)
            ->count();

        $criticalToday = Vital::query()
            ->where('clinic_id', $clinicId)
            ->whereDate('created_at', $today)
            ->whereNotNull('alerts')
            ->get(['alerts'])
            ->filter(fn (Vital $v) => ! empty($v->alerts))
            ->count();
        $criticalYesterday = Vital::query()
            ->where('clinic_id', $clinicId)
            ->whereDate('created_at', $yesterday)
            ->whereNotNull('alerts')
            ->get(['alerts'])
            ->filter(fn (Vital $v) => ! empty($v->alerts))
            ->count();

        $revenueWeek = (float) Claim::query()
            ->where('clinic_id', $clinicId)
            ->where('created_at', '>=', $weekStart)
            ->sum('billed_amount');
        $revenuePrevWeek = (float) Claim::query()
            ->where('clinic_id', $clinicId)
            ->whereBetween('created_at', [$prevWeekStart, $prevWeekEnd])
            ->sum('billed_amount');

        $weeklyVisits = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = now()->subDays($i);
            $weeklyVisits[] = [
                'label' => $day->format('D'),
                'date' => $day->toDateString(),
                'count' => Appointment::query()
                    ->where('clinic_id', $clinicId)
                    ->whereDate('starts_at', $day)
                    ->count(),
            ];
        }

        $mixRaw = Appointment::query()
            ->where('clinic_id', $clinicId)
            ->where('starts_at', '>=', now()->subDays(30))
            ->selectRaw('COALESCE(NULLIF(visit_type, ""), "general") as visit_type, COUNT(*) as total')
            ->groupBy('visit_type')
            ->pluck('total', 'visit_type');

        $mixTotal = max(1, (int) $mixRaw->sum());
        $caseMix = $mixRaw->map(fn ($count, $type) => [
            'label' => Str::title(str_replace('_', ' ', (string) $type)),
            'key' => (string) $type,
            'count' => (int) $count,
            'percent' => round(((int) $count / $mixTotal) * 100),
        ])->values()->all();

        $todaysAppointments = Appointment::query()
            ->where('clinic_id', $clinicId)
            ->whereDate('starts_at', $today)
            ->with(['patient:id,first_name,last_name,mrn', 'provider:id,name'])
            ->orderBy('starts_at')
            ->limit(10)
            ->get()
            ->map(function (Appointment $a) {
                $starts = $a->starts_at instanceof Carbon ? $a->starts_at : Carbon::parse($a->starts_at);
                $ends = $a->ends_at ? ($a->ends_at instanceof Carbon ? $a->ends_at : Carbon::parse($a->ends_at)) : null;
                $mins = $ends ? max(5, $starts->diffInMinutes($ends)) : 30;

                return [
                    'id' => $a->id,
                    'starts_at' => $starts->toIso8601String(),
                    'time' => $starts->format('H:i'),
                    'duration_minutes' => $mins,
                    'status' => $a->status,
                    'visit_type' => $a->visit_type,
                    'patient' => $a->patient ? [
                        'id' => $a->patient->id,
                        'first_name' => $a->patient->first_name,
                        'last_name' => $a->patient->last_name,
                        'mrn' => $a->patient->mrn,
                    ] : null,
                    'provider' => $a->provider ? ['name' => $a->provider->name] : null,
                ];
            })
            ->values()
            ->all();

        $recentPatients = Patient::query()
            ->where('clinic_id', $clinicId)
            ->latest('updated_at')
            ->limit(8)
            ->get(['id', 'mrn', 'first_name', 'last_name', 'date_of_birth', 'gender', 'updated_at'])
            ->map(function (Patient $p) {
                $vital = Vital::query()
                    ->where('patient_id', $p->id)
                    ->latest('id')
                    ->first(['alerts']);
                $alerts = $vital?->alerts ?? [];
                $hasAlerts = is_array($alerts) && count($alerts) > 0;
                $age = $p->date_of_birth ? $p->date_of_birth->age : null;
                $sex = $p->gender ? strtoupper(substr((string) $p->gender, 0, 1)) : '';

                return [
                    'id' => $p->id,
                    'mrn' => $p->mrn,
                    'first_name' => $p->first_name,
                    'last_name' => $p->last_name,
                    'initials' => strtoupper(substr($p->first_name, 0, 1).substr($p->last_name, 0, 1)),
                    'summary' => trim(($hasAlerts ? 'Alert vitals' : 'Routine follow-up').($age !== null ? " · {$age}{$sex}" : '')),
                    'status' => $hasAlerts ? 'ALERT' : 'STABLE',
                ];
            })
            ->values()
            ->all();

        $pctDelta = static function (float|int $current, float|int $previous): float {
            if ((float) $previous === 0.0) {
                return $current > 0 ? 100.0 : 0.0;
            }

            return round((((float) $current - (float) $previous) / (float) $previous) * 100, 1);
        };

        return ApiResponse::success([
            'stats' => [
                'active_users' => User::query()->where('clinic_id', $clinicId)->where('is_active', true)->count(),
                'patients' => Patient::query()->where('clinic_id', $clinicId)->count(),
                'patients_today' => $patientsToday,
                'todays_appointments' => $apptsToday,
                'waiting' => Appointment::query()->where('clinic_id', $clinicId)->whereIn('status', ['waiting', 'ready_for_vitals'])->count(),
                'open_invites' => UserInvitation::query()->where('clinic_id', $clinicId)->whereNull('accepted_at')->where('expires_at', '>', now())->count(),
                'critical_cases' => $criticalToday,
                'revenue_week' => $revenueWeek,
            ],
            'trends' => [
                'patients_today_pct' => $pctDelta($patientsToday, $patientsYesterday),
                'appointments_pct' => $pctDelta($apptsToday, $apptsYesterday),
                'critical_delta' => $criticalToday - $criticalYesterday,
                'revenue_week_pct' => $pctDelta($revenueWeek, $revenuePrevWeek),
            ],
            'weekly_visits' => $weeklyVisits,
            'case_mix' => $caseMix,
            'todays_appointments' => $todaysAppointments,
            'recent_patients' => $recentPatients,
            'currency' => config('drmonk.currency', 'USD'),
        ]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $logs = AuditLog::query()
            ->when(
                $request->user()->hasRole(Roles::SUPER_ADMIN) === false,
                fn ($q) => $q->where('clinic_id', $request->user()->clinic_id)
            )
            ->latest('created_at')
            ->paginate(50);

        return ApiResponse::success([
            'items' => $logs->items(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
            ],
        ], 'Audit logs');
    }

    public function operationalSuggestions(Request $request): JsonResponse
    {
        $clinicId = $request->user()->clinic_id;
        $waiting = Appointment::query()->where('clinic_id', $clinicId)->where('status', 'waiting')->count();

        return ApiResponse::success([
            'suggestions' => [
                [
                    'type' => 'scheduling',
                    'message' => 'Thursday afternoon has under-utilized slots.',
                    'auto_action' => false,
                ],
                [
                    'type' => 'bottleneck',
                    'message' => $waiting > 5
                        ? "Vitals/waiting queue elevated ({$waiting} patients)."
                        : 'Vitals queue within normal range.',
                    'auto_action' => false,
                ],
            ],
        ], 'Operational suggestions');
    }

    public function invite(InviteUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $plainToken = Str::random(64);

        $invitation = UserInvitation::create([
            'clinic_id' => $request->user()->clinic_id,
            'email' => Str::lower($data['email']),
            'role' => $data['role'],
            'token' => hash('sha256', $plainToken),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addHours(48),
        ]);

        $this->audit->log(
            'admin.invite',
            'allowed',
            $request->user(),
            $request,
            entityType: UserInvitation::class,
            entityId: $invitation->id,
            meta: ['role' => $invitation->role, 'email_hash' => hash('sha256', $invitation->email)]
        );

        return ApiResponse::created([
            'invitation_id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'activation_token' => $plainToken,
        ], 'Invitation created (48-hour link)');
    }

    public function invitations(Request $request): JsonResponse
    {
        $items = UserInvitation::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->latest()
            ->limit(50)
            ->get(['id', 'email', 'role', 'expires_at', 'accepted_at', 'created_at']);

        return ApiResponse::success(['items' => $items]);
    }

    public function users(Request $request): JsonResponse
    {
        $users = User::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->with('roles:id,name')
            ->orderBy('name')
            ->paginate(50);

        $users->getCollection()->transform(function (User $user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_active' => $user->is_active,
                'can_prescribe' => $user->can_prescribe,
                'license_state' => $user->license_state,
                'roles' => $user->getRoleNames()->values()->all(),
            ];
        });

        return ApiResponse::success($users, 'Clinic users');
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        abort_unless($user->clinic_id === $request->user()->clinic_id, 403);

        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'role' => ['sometimes', 'string', 'in:'.implode(',', Roles::clinicAssignable())],
            'can_prescribe' => ['sometimes', 'boolean'],
            'license_state' => ['nullable', 'string', 'size:2'],
        ]);

        if (array_key_exists('is_active', $data)) {
            $user->is_active = $data['is_active'];
        }
        if (array_key_exists('can_prescribe', $data)) {
            // Admin may only set prescribe for doctor/NP roles
            $roles = isset($data['role']) ? [$data['role']] : $user->getRoleNames()->all();
            $mayRx = count(array_intersect($roles, Roles::canWritePrescriptions())) > 0;
            $user->can_prescribe = $mayRx ? (bool) $data['can_prescribe'] : false;
        }
        if (array_key_exists('license_state', $data)) {
            $user->license_state = $data['license_state'] ? strtoupper($data['license_state']) : null;
        }
        $user->save();

        if (! empty($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        $this->audit->log('admin.user_update', 'allowed', $request->user(), $request, entityType: User::class, entityId: $user->id);

        return ApiResponse::success([
            'id' => $user->id,
            'is_active' => $user->is_active,
            'can_prescribe' => $user->can_prescribe,
            'roles' => $user->getRoleNames()->values()->all(),
        ], 'User updated');
    }

    public function roles(): JsonResponse
    {
        return ApiResponse::success([
            'assignable' => Roles::clinicAssignable(),
            'matrix' => Permissions::matrix(),
            'note' => 'Admin cannot write prescriptions (A9). Matrix is read-only in V1.',
        ]);
    }

    public function oversight(Request $request): JsonResponse
    {
        $clinicId = $request->user()->clinic_id;

        return ApiResponse::success([
            'patients' => Patient::query()
                ->where('clinic_id', $clinicId)
                ->latest()
                ->limit(20)
                ->get(['id', 'mrn', 'first_name', 'last_name', 'date_of_birth', 'created_at']),
            'appointments' => Appointment::query()
                ->where('clinic_id', $clinicId)
                ->whereDate('starts_at', '>=', today())
                ->with(['patient:id,first_name,last_name,mrn', 'provider:id,name'])
                ->orderBy('starts_at')
                ->limit(30)
                ->get(),
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        $clinic = Clinic::query()->findOrFail($request->user()->clinic_id);

        return ApiResponse::success([
            'name' => $clinic->name,
            'timezone' => $clinic->timezone,
            'logo_path' => $clinic->logo_path,
            'working_hours' => $clinic->working_hours,
            'notification_templates' => $clinic->notification_templates ?? [],
            'hipaa_settings' => $clinic->hipaa_settings ?? [],
            'status' => $clinic->status,
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->assertPassword($request);

        $data = $request->validate([
            'timezone' => ['sometimes', 'string', 'max:64'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'working_hours' => ['nullable', 'array'],
            'notification_templates' => ['nullable', 'array'],
            'hipaa_settings' => ['nullable', 'array'],
            'hipaa_settings.privacy_officer' => ['nullable', 'string', 'max:255'],
            'hipaa_settings.security_officer' => ['nullable', 'string', 'max:255'],
            'hipaa_settings.last_risk_assessment_on' => ['nullable', 'date'],
            'hipaa_settings.breach_contact_email' => ['nullable', 'email'],
        ]);

        $clinic = Clinic::query()->findOrFail($request->user()->clinic_id);
        $clinic->fill(collect($data)->except('password')->all());
        $clinic->save();

        $this->audit->log('admin.settings_update', 'allowed', $request->user(), $request, entityType: Clinic::class, entityId: $clinic->id);

        return ApiResponse::success([
            'timezone' => $clinic->timezone,
            'working_hours' => $clinic->working_hours,
            'notification_templates' => $clinic->notification_templates,
            'hipaa_settings' => $clinic->hipaa_settings,
        ], 'Settings updated');
    }

    private function assertPassword(Request $request): void
    {
        $password = $request->input('password');
        if (! is_string($password) || ! Hash::check($password, $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => ['Re-authentication required for critical changes.'],
            ]);
        }
    }
}
