<?php

namespace App\Http\Middleware;

use App\Models\Patient;
use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePatientAccess
{
    public function __construct(private AuditService $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $patient = $request->route('patient');

        if (is_string($patient) || is_numeric($patient)) {
            $patient = Patient::query()->findOrFail($patient);
            $request->route()->setParameter('patient', $patient);
        }

        if (! $user || ! ($patient instanceof Patient)) {
            abort(403, 'PHI access denied for this patient.');
        }

        if (! $user->canAccessPatient($patient)) {
            $this->audit->log(
                'phi.access_denied',
                'denied',
                $user,
                $request,
                $patient->id,
                Patient::class,
                $patient->id
            );

            abort(403, 'PHI access denied for this patient.');
        }

        $this->audit->log(
            'phi.access',
            'allowed',
            $user,
            $request,
            $patient->id,
            Patient::class,
            $patient->id
        );

        return $next($request);
    }
}
