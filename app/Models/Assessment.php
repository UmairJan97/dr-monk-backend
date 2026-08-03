<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Assessment extends Model
{
    protected $fillable = [
        'clinic_id', 'patient_id', 'administered_by', 'instrument', 'responses', 'score',
    ];

    protected function casts(): array
    {
        return ['responses' => 'array'];
    }
}
