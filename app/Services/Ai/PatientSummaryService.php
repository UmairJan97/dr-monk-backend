<?php

namespace App\Services\Ai;

use App\Models\AiUsageLog;
use App\Models\ClinicalNote;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\User;
use App\Services\AuditService;

class PatientSummaryService
{
    public function __construct(private AuditService $audit) {}

    /**
     * Volatile, read-only summary generated from platform data only by default.
     */
    public function generate(User $user, Patient $patient): array
    {
        $this->audit->log('ai.patient_summary', 'allowed', $user, request(), $patient->id, Patient::class, $patient->id);

        $latestNotes = ClinicalNote::query()
            ->where('patient_id', $patient->id)
            ->latest()
            ->limit(3)
            ->get(['note_type', 'created_at']);

        $diagnoses = Diagnosis::query()
            ->where('patient_id', $patient->id)
            ->where('status', 'active')
            ->limit(5)
            ->get(['icd10_code', 'description']);

        if ($user->clinic_id) {
            AiUsageLog::create([
                'clinic_id' => $user->clinic_id,
                'user_id' => $user->id,
                'feature' => 'summary',
                'credits_used' => 1,
                'patient_id' => $patient->id,
                'meta' => ['source' => 'platform_db'],
            ]);
        }

        $dxText = $diagnoses
            ->map(fn ($d) => trim(($d->icd10_code ? $d->icd10_code.' — ' : '').$d->description))
            ->filter()
            ->implode('; ');
        $name = trim($patient->first_name.' '.$patient->last_name);
        $narrative = sprintf(
            '%s (MRN %s). Allergies: %s. Active medications: %s. Active diagnoses: %s. Recent notes on file: %d. Summary is volatile and read-only.',
            $name !== '' ? $name : 'Patient',
            $patient->mrn ?: '—',
            $patient->allergies ?: 'none on file',
            $patient->active_medications ?: 'none on file',
            $dxText !== '' ? $dxText : 'none on file',
            $latestNotes->count()
        );

        return [
            'narrative' => $narrative,
            'reason_for_visit' => 'See latest appointment notes',
            'active_diagnoses' => $diagnoses,
            'current_medications' => $patient->active_medications,
            'allergies' => $patient->allergies,
            'recent_notes' => $latestNotes,
            'volatile' => true,
            'editable' => false,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
