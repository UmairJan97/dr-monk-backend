<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageLog extends Model
{
    protected $fillable = [
        'clinic_id', 'user_id', 'feature', 'credits_used', 'patient_id', 'meta',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }
}
