<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingCode extends Model
{
    protected $fillable = [
        'clinic_id', 'patient_id', 'appointment_id', 'confirmed_by',
        'code_system', 'code', 'description', 'modifier', 'source', 'status',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
