<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClinicalNote extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'clinic_id', 'patient_id', 'appointment_id', 'author_id',
        'note_type', 'content', 'structured', 'is_signed', 'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'structured' => 'array',
            'is_signed' => 'boolean',
            'signed_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
