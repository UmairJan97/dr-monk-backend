<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSessionAwake
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($request->is('api/v1/auth/wake', 'api/v1/auth/logout', 'api/v1/auth/me')) {
            return $next($request);
        }

        if ($user->isInSleepMode()) {
            abort(423, 'Session in sleep mode. Enter PIN to wake.');
        }

        if ($user->isLocked()) {
            abort(423, 'Account locked.');
        }

        if (! $user->is_active) {
            abort(401, 'Unauthenticated.');
        }

        $user->forceFill(['last_activity_at' => now()])->saveQuietly();

        return $next($request);
    }
}
