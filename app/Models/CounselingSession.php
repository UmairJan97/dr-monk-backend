<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CounselingSession extends Model
{
    protected $fillable = [
        'clinic_id', 'patient_id', 'counselor_id', 'appointment_id',
        'session_type', 'notes', 'goals',
    ];

    protected function casts(): array
    {
        return ['goals' => 'array'];
    }
}
