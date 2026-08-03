<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Claim extends Model
{
    protected $fillable = [
        'clinic_id', 'patient_id', 'appointment_id', 'created_by', 'status',
        'clearinghouse_id', 'x12_payload', 'denial_codes', 'billed_amount', 'paid_amount',
    ];

    protected function casts(): array
    {
        return [
            'denial_codes' => 'array',
            'billed_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
