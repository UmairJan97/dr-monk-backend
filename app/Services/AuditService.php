<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditService
{
    public function log(
        string $action,
        string $result,
        ?User $user = null,
        ?Request $request = null,
        ?int $patientId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        array $meta = [],
    ): AuditLog {
        return AuditLog::create([
            'clinic_id' => $user?->clinic_id,
            'user_id' => $user?->id,
            'role' => $user?->getRoleNames()->first(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'patient_id' => $patientId,
            'endpoint' => $request?->path(),
            'ip_address' => $request?->ip(),
            'result' => $result,
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }
}
