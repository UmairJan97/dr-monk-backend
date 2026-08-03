<?php

namespace App\Http\Middleware;

use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function __construct(private AuditService $audit) {}

    /**
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->isLocked()) {
            abort(423, 'Account locked.');
        }

        $normalized = collect($roles)
            ->flatMap(fn (string $role) => explode(',', $role))
            ->map(fn (string $role) => trim($role))
            ->filter()
            ->unique()
            ->values()
            ->all();

        // No Super Admin bypass — SaaS admin only hits saas.* routes explicitly.
        if ($normalized !== [] && ! $user->hasAnyRole($normalized)) {
            $this->audit->log('rbac.denied', 'denied', $user, $request, meta: [
                'required_roles' => $normalized,
            ]);

            abort(403, 'Insufficient role.');
        }

        return $next($request);
    }
}
