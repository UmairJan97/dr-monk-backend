<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TreatmentPlan extends Model
{
    protected $fillable = [
        'clinic_id', 'patient_id', 'created_by', 'recommendations',
        'home_care', 'follow_up_plan', 'referrals',
    ];
}
