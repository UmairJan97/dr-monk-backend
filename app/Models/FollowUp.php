<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FollowUp extends Model
{
    protected $fillable = [
        'clinic_id', 'patient_id', 'created_by', 'due_at', 'status', 'instructions',
    ];

    protected function casts(): array
    {
        return ['due_at' => 'datetime'];
    }
}
