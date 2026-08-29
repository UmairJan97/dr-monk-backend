<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderTask extends Model
{
    protected $fillable = [
        'clinic_id',
        'user_id',
        'title',
        'patient_name',
        'patient_id',
        'priority',
        'due_label',
        'due_at',
        'done',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'done' => 'boolean',
            'sort_order' => 'integer',
            'due_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
