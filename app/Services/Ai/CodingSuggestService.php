<?php

namespace App\Services\Ai;

use App\Models\BillingCode;
use App\Models\Patient;
use App\Models\User;
use App\Services\AuditService;
use App\Support\Roles;

class CodingSuggestService
{
    public function __construct(private AuditService $audit) {}

    /**
     * Suggest ICD-10/CPT codes; nothing applied without explicit confirmation.
     */
    public function suggest(User $user, Patient $patient, ?string $text = null, ?int $durationMinutes = null): array
    {
        $suggestions = $user->hasRole(Roles::COUNSELOR)
            ? $this->therapySuggestions($text, $durationMinutes)
            : $this->officeVisitSuggestions($text);

        foreach ($suggestions as $row) {
            BillingCode::create([
                'clinic_id' => $patient->clinic_id,
                'patient_id' => $patient->id,
                'confirmed_by' => null,
                'code_system' => $row['code_system'],
                'code' => $row['code'],
                'description' => $row['description'],
                'source' => 'ai_suggest',
                'status' => 'suggested',
            ]);
        }

        $this->audit->log(
            'ai.coding_suggest',
            'allowed',
            $user,
            request(),
            $patient->id,
            Patient::class,
            $patient->id,
            ['codes' => array_column($suggestions, 'code')]
        );

        return $suggestions;
    }

    private function officeVisitSuggestions(?string $text): array
    {
        return [
            [
                'code_system' => 'ICD10',
                'code' => 'J06.9',
                'description' => 'Acute upper respiratory infection, unspecified',
                'confidence' => 0.72,
                'documentation_gap' => false,
            ],
            [
                'code_system' => 'CPT',
                'code' => '99213',
                'description' => 'Office/outpatient visit, established patient',
                'confidence' => 0.68,
                'documentation_gap' => $text ? false : true,
            ],
        ];
    }

    private function therapySuggestions(?string $text, ?int $durationMinutes = null): array
    {
        $long = ($durationMinutes !== null && $durationMinutes >= 53)
            || ($text && mb_strlen($text) > 120);

        return [
            [
                'code_system' => 'ICD10',
                'code' => 'F41.1',
                'description' => 'Generalized anxiety disorder',
                'confidence' => 0.7,
                'documentation_gap' => false,
            ],
            [
                'code_system' => 'CPT',
                'code' => $long ? '90837' : '90834',
                'description' => $long
                    ? 'Psychotherapy, 60 minutes with patient'
                    : 'Psychotherapy, 45 minutes with patient',
                'confidence' => 0.74,
                'documentation_gap' => $text ? false : true,
            ],
        ];
    }
}
