<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\WakePinRequest;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\AuditService;
use App\Support\ApiResponse;
use App\Support\Roles;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MAX_LOGIN_ATTEMPTS = 10;

    private const LOCKOUT_AFTER_FAILURES = 5;

    private const LOCKOUT_MINUTES = 30;

    public function __construct(private AuditService $audit) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $throttleKey = $this->loginThrottleKey($request, $credentials['email']);

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_LOGIN_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            $this->audit->log('auth.rate_limited', 'denied', null, $request, meta: [
                'email_hash' => hash('sha256', strtolower($credentials['email'])),
                'retry_after' => $seconds,
            ]);

            return ApiResponse::error(
                'Too many login attempts. Try again later.',
                429,
                'TOO_MANY_REQUESTS',
            )->withHeaders(['Retry-After' => (string) $seconds]);
        }

        $user = User::query()->where('email', $credentials['email'])->first();

        $passwordOk = $user && Hash::check($credentials['password'], $user->password);
        $phoneOk = empty($credentials['phone']) || ($user && $user->phone === $credentials['phone']);

        if (! $user || ! $passwordOk || ! $phoneOk || ! $user->is_active || $user->isLocked()) {
            RateLimiter::hit($throttleKey, 60);

            if ($user && $passwordOk === false) {
                $user->increment('failed_login_attempts');
                $user->refresh();

                if ($user->failed_login_attempts >= self::LOCKOUT_AFTER_FAILURES) {
                    $user->update(['locked_until' => now()->addMinutes(self::LOCKOUT_MINUTES)]);
                    $this->audit->log('auth.lockout', 'denied', $user, $request);
                }
            }

            $this->audit->log('auth.login_failed', 'denied', $user, $request, meta: [
                'email_hash' => hash('sha256', strtolower($credentials['email'])),
            ]);

            // Generic message — never reveal which field failed (HIPAA / anti-enumeration).
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials'],
            ]);
        }

        RateLimiter::clear($throttleKey);

        // Invalidate older API tokens on fresh login (single-session style for clinic workstations).
        $user->tokens()->delete();

        $user->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_activity_at' => now(),
            'sleep_mode_at' => null,
        ]);

        $token = $user->createToken('api', ['*'], now()->addHours(12))->plainTextToken;
        $this->audit->log('auth.login', 'allowed', $user, $request);

        return ApiResponse::success([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in_hours' => 12,
            'user' => $this->userPayload($user),
        ], 'Authenticated');
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        $this->audit->log('auth.logout', 'allowed', $user, $request);

        return ApiResponse::success(null, 'Logged out');
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $user?->tokens()->delete();
        $this->audit->log('auth.logout_all', 'allowed', $user, $request);

        return ApiResponse::success(null, 'All sessions revoked');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success($this->userPayload($user));
    }

    public function sleep(Request $request): JsonResponse
    {
        $request->user()->update(['sleep_mode_at' => now()]);
        $this->audit->log('auth.sleep', 'allowed', $request->user(), $request);

        return ApiResponse::success(['sleep_mode' => true], 'Sleep mode enabled');
    }

    public function wake(WakePinRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();
        $key = 'wake:'.$user->id.':'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return ApiResponse::error('Too many PIN attempts.', 429, 'TOO_MANY_REQUESTS');
        }

        if (! $user->pin_hash || ! Hash::check($data['pin'], $user->pin_hash)) {
            RateLimiter::hit($key, 300);
            $this->audit->log('auth.wake_failed', 'denied', $user, $request);

            return ApiResponse::error('Invalid PIN', 401, 'INVALID_PIN');
        }

        RateLimiter::clear($key);
        $user->update(['sleep_mode_at' => null, 'last_activity_at' => now()]);
        $this->audit->log('auth.wake', 'allowed', $user, $request);

        return ApiResponse::success(['sleep_mode' => false], 'Session awakened');
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $key = 'forgot:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return ApiResponse::error('Too many requests. Try again later.', 429, 'TOO_MANY_REQUESTS');
        }

        RateLimiter::hit($key, 60);

        // Always return the same message (anti-enumeration).
        Password::broker()->sendResetLink(['email' => $email]);

        $this->audit->log('auth.forgot_password', 'allowed', null, $request, meta: [
            'email_hash' => hash('sha256', strtolower($email)),
        ]);

        return ApiResponse::success(null, 'If that email exists, a reset link has been sent.');
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                ])->save();

                $user->tokens()->delete();
                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['Unable to reset password with the provided token.'],
            ]);
        }

        $this->audit->log('auth.password_reset', 'allowed', null, $request, meta: [
            'email_hash' => hash('sha256', strtolower($request->validated('email'))),
        ]);

        return ApiResponse::success(null, 'Password has been reset. Please sign in.');
    }

    public function acceptInvitation(AcceptInvitationRequest $request): JsonResponse
    {
        $data = $request->validated();

        $invitation = UserInvitation::query()
            ->where('token', hash('sha256', $data['token']))
            ->whereNull('accepted_at')
            ->first();

        if (! $invitation || $invitation->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'token' => ['Invitation is invalid or expired.'],
            ]);
        }

        if (! in_array($invitation->role, Roles::clinicAssignable(), true)) {
            throw ValidationException::withMessages([
                'token' => ['Invitation role is not allowed.'],
            ]);
        }

        $user = DB::transaction(function () use ($data, $invitation) {
            $existing = User::query()->where('email', $invitation->email)->first();
            if ($existing) {
                throw ValidationException::withMessages([
                    'token' => ['An account with this email already exists.'],
                ]);
            }

            $user = User::create([
                'clinic_id' => $invitation->clinic_id,
                'name' => $data['name'],
                'email' => $invitation->email,
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'pin_hash' => Hash::make($data['pin']),
                'is_active' => true,
                'can_prescribe' => in_array($invitation->role, [Roles::DOCTOR, Roles::NP], true),
            ]);

            $user->syncRoles([$invitation->role]);
            $invitation->update(['accepted_at' => now()]);

            return $user;
        });

        $this->audit->log('auth.invitation_accepted', 'allowed', $user, $request);

        return ApiResponse::created([
            'user' => $this->userPayload($user),
        ], 'Account activated. Please sign in.');
    }

    private function loginThrottleKey(Request $request, string $email): string
    {
        return 'login:'.Str::lower($email).'|'.$request->ip();
    }

    private function userPayload(User $user): array
    {
        $user->loadMissing('roles');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'clinic_id' => $user->clinic_id,
            'roles' => $user->getRoleNames()->values()->all(),
            // Permission checks stay on the API; FE routes by role only.
            'permissions' => [],
            'sleep_mode' => $user->isInSleepMode(),
            'can_prescribe' => (bool) $user->can_prescribe,
            'can_prescribe_now' => $user->canPrescribe(),
            'license_state' => $user->license_state,
            'is_active' => (bool) $user->is_active,
        ];
    }
}
