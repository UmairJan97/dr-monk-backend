<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'clinic_id', 'mrn', 'first_name', 'last_name', 'date_of_birth', 'gender',
        'phone', 'email', 'address', 'photo_path', 'primary_provider_id',
        'emergency_contact', 'allergies', 'active_medications',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'emergency_contact' => 'array',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function primaryProvider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_provider_id');
    }

    public function providers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'patient_provider_assignments', 'patient_id', 'provider_id')
            ->withTimestamps();
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function vitals(): HasMany
    {
        return $this->hasMany(Vital::class);
    }

    public function insurances(): HasMany
    {
        return $this->hasMany(PatientInsurance::class);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
