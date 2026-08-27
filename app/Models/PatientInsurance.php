<?php

namespace App\Models;

use App\Casts\EncryptedSafe;
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
            // AES-256-CBC at rest via APP_KEY — never 500 the patient list
            'payer_name' => EncryptedSafe::class,
            'policy_number' => EncryptedSafe::class,
            'group_number' => EncryptedSafe::class,
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
