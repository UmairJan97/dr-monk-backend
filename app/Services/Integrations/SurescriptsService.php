<?php

namespace App\Services\Integrations;

use App\Models\Prescription;
use App\Models\User;

/**
 * NCPDP SCRIPT / Surescripts adapter — sandbox stub until network certification.
 * Keys: SURESCRIPTS_MODE, SURESCRIPTS_API_KEY, SURESCRIPTS_ENDPOINT
 */
class SurescriptsService
{
    public function sendPrescription(Prescription $prescription, User $prescriber): Prescription
    {
        if (! $prescriber->canPrescribe()) {
            abort(403, 'Prescriber not authorized for this license state.');
        }

        $mode = config('drmonk.surescripts.mode', config('services.surescripts.mode', 'sandbox'));

        $prescription->update([
            'status' => 'sent',
            'ncpdp_message_id' => 'NCPDP-SIM-'.uniqid(),
            'surescripts_payload' => [
                'standard' => 'NCPDP SCRIPT',
                'mode' => $mode,
                'endpoint' => config('drmonk.surescripts.endpoint'),
                'sent_at' => now()->toIso8601String(),
            ],
        ]);

        return $prescription->fresh();
    }
}
