<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClinicActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->clinic_id) {
            $clinic = $user->clinic;

            if (! $clinic || ! $clinic->isActive()) {
                abort(403, 'Clinic subscription is not active.');
            }
        }

        return $next($request);
    }
}
