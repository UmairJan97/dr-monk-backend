<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LabOrder extends Model
{
    protected $fillable = [
        'clinic_id', 'patient_id', 'ordered_by', 'test_name', 'status',
        'is_critical', 'result_file_path', 'result_summary', 'result_values', 'resulted_at',
    ];

    protected function casts(): array
    {
        return [
            'is_critical' => 'boolean',
            'result_values' => 'array',
            'resulted_at' => 'datetime',
        ];
    }
}
