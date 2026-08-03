<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    public const STATUSES = [
        'scheduled',
        'waiting',
        'ready_for_vitals',
        'vitals_completed',
        'ready_for_provider',
        'in_progress',
        'completed',
        'follow_up_needed',
        'cancelled',
        'no_show',
    ];

    protected $fillable = [
        'clinic_id', 'patient_id', 'provider_id', 'visit_type', 'room',
        'starts_at', 'ends_at', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }
}
