<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicMessageLog extends Model
{
    protected $fillable = [
        'clinic_id',
        'sent_by',
        'patient_id',
        'appointment_id',
        'channel',
        'template_key',
        'recipient_hint',
        'subject',
        'body',
        'status',
        'provider_ref',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }
}
