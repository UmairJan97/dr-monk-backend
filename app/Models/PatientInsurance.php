<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientInsurance extends Model
{
    protected $fillable = [
        'clinic_id', 'patient_id', 'type', 'payer_name', 'policy_number',
        'group_number', 'expires_on', 'card_front_path', 'card_back_path', 'eligibility_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'expires_on' => 'date',
            'eligibility_snapshot' => 'array',
            // AES-256-CBC at rest via APP_KEY
            'payer_name' => 'encrypted',
            'policy_number' => 'encrypted',
            'group_number' => 'encrypted',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
