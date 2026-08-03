<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Prescription extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'clinic_id', 'patient_id', 'prescriber_id', 'medication_name', 'sig',
        'quantity', 'refills', 'pharmacy', 'status', 'ncpdp_message_id', 'surescripts_payload',
    ];

    protected function casts(): array
    {
        return ['surescripts_payload' => 'array'];
    }
}
