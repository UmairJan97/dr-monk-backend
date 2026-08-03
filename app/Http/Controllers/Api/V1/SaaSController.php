<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SaaSController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole(Roles::SUPER_ADMIN), 403);

        return ApiResponse::success([
            'total_clinics' => Clinic::count(),
            'active_clinics' => Clinic::where('status', 'active')->count(),
            'trial_clinics' => Clinic::where('status', 'trial')->count(),
            'suspended_clinics' => Clinic::where('status', 'suspended')->count(),
            'expired_clinics' => Clinic::where('status', 'expired')->count(),
            'total_patients' => Patient::count(),
            'total_providers' => User::role([Roles::DOCTOR, Roles::NP])->count(),
            'ai_credits_allocated' => (int) Clinic::sum('ai_credits_balance'),
            'storage_used_mb' => (int) Clinic::sum('storage_used_mb'),
            'stripe_mode' => config('drmonk.stripe.mode', 'sandbox'),
        ]);
    }

    public function plans(): JsonResponse
    {
        return ApiResponse::success([
            'items' => SubscriptionPlan::query()->orderBy('price_cents')->get(),
        ]);
    }

    public function storePlan(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole(Roles::SUPER_ADMIN), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:subscription_plans,slug'],
            'billing_period' => ['required', 'in:monthly,yearly,trial,enterprise'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'ai_credits_monthly' => ['required', 'integer', 'min:0'],
            'storage_mb' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        $plan = SubscriptionPlan::create([
            ...$data,
            'slug' => $data['slug'] ?? Str::slug($data['name']).'-'.Str::random(4),
            'is_active' => $data['is_active'] ?? true,
        ]);

        return ApiResponse::created($plan, 'Plan created');
    }

    public function clinics(): JsonResponse
    {
        $items = Clinic::query()
            ->with('plan:id,name,billing_period,price_cents')
            ->orderBy('name')
            ->get()
            ->map(fn (Clinic $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'subdomain' => $c->subdomain,
                'status' => $c->status,
                'timezone' => $c->timezone,
                'logo_path' => $c->logo_path,
                'ai_credits_balance' => $c->ai_credits_balance,
                'storage_used_mb' => $c->storage_used_mb,
                'stripe_customer_id' => $c->stripe_customer_id,
                'stripe_subscription_id' => $c->stripe_subscription_id,
                'trial_ends_at' => $c->trial_ends_at,
                'plan' => $c->plan,
            ]);

        return ApiResponse::success(['items' => $items]);
    }

    public function storeClinic(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole(Roles::SUPER_ADMIN), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'subdomain' => ['nullable', 'string', 'max:63', 'unique:clinics,subdomain'],
            'timezone' => ['nullable', 'string'],
            'subscription_plan_id' => ['nullable', 'exists:subscription_plans,id'],
            'admin_name' => ['required', 'string'],
            'admin_email' => ['required', 'email', 'unique:users,email'],
            'admin_password' => ['required', 'string', 'min:8'],
        ]);

        $plan = ! empty($data['subscription_plan_id'])
            ? SubscriptionPlan::query()->find($data['subscription_plan_id'])
            : null;

        $clinic = Clinic::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.Str::random(4),
            'subdomain' => $data['subdomain'] ?? null,
            'timezone' => $data['timezone'] ?? config('drmonk.timezone', 'America/New_York'),
            'status' => 'trial',
            'subscription_plan_id' => $data['subscription_plan_id'] ?? null,
            'trial_ends_at' => now()->addDays(14),
            'ai_credits_balance' => $plan?->ai_credits_monthly ?? 500,
            // Stripe sandbox placeholders — real keys from STRIPE_* env
            'stripe_customer_id' => config('drmonk.stripe.mode') === 'sandbox'
                ? 'cus_sandbox_'.Str::lower(Str::random(10))
                : null,
        ]);

        $admin = User::create([
            'clinic_id' => $clinic->id,
            'name' => $data['admin_name'],
            'email' => $data['admin_email'],
            'password' => $data['admin_password'],
            'is_active' => true,
            'pin_hash' => null,
        ]);
        $admin->assignRole(Roles::CLINIC_ADMIN);

        return ApiResponse::created([
            'clinic' => $clinic,
            'admin_id' => $admin->id,
            'stripe_mode' => config('drmonk.stripe.mode', 'sandbox'),
        ], 'Clinic provisioned');
    }

    public function updateClinic(Request $request, Clinic $clinic): JsonResponse
    {
        abort_unless($request->user()->hasRole(Roles::SUPER_ADMIN), 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'subdomain' => ['nullable', 'string', 'max:63', 'unique:clinics,subdomain,'.$clinic->id],
            'timezone' => ['nullable', 'string', 'max:64'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'subscription_plan_id' => ['nullable', 'exists:subscription_plans,id'],
            'status' => ['sometimes', 'in:active,suspended,trial,expired'],
        ]);

        $clinic->update($data);

        return ApiResponse::success($clinic->fresh('plan'), 'Clinic updated');
    }

    public function updateClinicStatus(Request $request, Clinic $clinic): JsonResponse
    {
        abort_unless($request->user()->hasRole(Roles::SUPER_ADMIN), 403);

        $data = $request->validate([
            'status' => ['required', 'in:active,suspended,trial,expired'],
        ]);

        $clinic->update($data);

        return ApiResponse::success($clinic, 'Clinic status updated');
    }

    public function usage(Clinic $clinic): JsonResponse
    {
        return ApiResponse::success([
            'clinic_id' => $clinic->id,
            'users' => $clinic->users()->count(),
            'patients' => $clinic->patients()->count(),
            'ai_credits_balance' => $clinic->ai_credits_balance,
            'storage_used_mb' => $clinic->storage_used_mb,
            'plan' => $clinic->plan,
            'stripe_customer_id' => $clinic->stripe_customer_id,
            'stripe_subscription_id' => $clinic->stripe_subscription_id,
        ]);
    }

    public function allocateCredits(Request $request, Clinic $clinic): JsonResponse
    {
        abort_unless($request->user()->hasRole(Roles::SUPER_ADMIN), 403);

        $data = $request->validate([
            'credits' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        $clinic->increment('ai_credits_balance', $data['credits']);

        return ApiResponse::success([
            'clinic_id' => $clinic->id,
            'ai_credits_balance' => $clinic->fresh()->ai_credits_balance,
        ], 'Credits allocated');
    }

    /** Sandbox Stripe subscription attach (no live charge without STRIPE_SECRET). */
    public function attachStripeSandbox(Request $request, Clinic $clinic): JsonResponse
    {
        abort_unless($request->user()->hasRole(Roles::SUPER_ADMIN), 403);

        $mode = config('drmonk.stripe.mode', 'sandbox');
        $hasSecret = (bool) config('drmonk.stripe.secret');

        $clinic->update([
            'stripe_customer_id' => $clinic->stripe_customer_id ?: 'cus_'.$mode.'_'.Str::lower(Str::random(8)),
            'stripe_subscription_id' => 'sub_'.$mode.'_'.Str::lower(Str::random(8)),
            'status' => $clinic->status === 'trial' ? 'active' : $clinic->status,
        ]);

        return ApiResponse::success([
            'clinic' => $clinic->fresh(),
            'stripe_mode' => $mode,
            'live_api_used' => $hasSecret && $mode === 'live',
            'note' => $hasSecret
                ? 'Stripe secret present — wire live Checkout in go-live.'
                : 'Sandbox IDs assigned. Set STRIPE_SECRET for live billing.',
        ], 'Stripe sandbox subscription attached');
    }
}
