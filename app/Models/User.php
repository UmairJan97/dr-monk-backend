<?php

namespace App\Models;

use App\Support\Roles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'clinic_id',
        'name',
        'email',
        'phone',
        'password',
        'pin_hash',
        'is_active',
        'can_prescribe',
        'npi',
        'dea',
        'license_state',
        'failed_login_attempts',
        'locked_until',
        'last_activity_at',
        'sleep_mode_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'pin_hash',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'can_prescribe' => 'boolean',
            'locked_until' => 'datetime',
            'last_activity_at' => 'datetime',
            'sleep_mode_at' => 'datetime',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function assignedPatients(): BelongsToMany
    {
        return $this->belongsToMany(Patient::class, 'patient_provider_assignments', 'provider_id', 'patient_id')
            ->withTimestamps();
    }

    public function isLocked(): bool
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }

    public function isInSleepMode(): bool
    {
        return $this->sleep_mode_at !== null;
    }

    public function canAccessPatient(Patient $patient): bool
    {
        // SaaS Super Admin manages tenants — not clinical charts (SRD / checklist).
        if ($this->hasRole(Roles::SUPER_ADMIN)) {
            return false;
        }

        if (! $this->clinic_id || $this->clinic_id !== $patient->clinic_id) {
            return false;
        }

        if ($this->hasAnyRole([Roles::CLINIC_ADMIN, Roles::FRONT_DESK, Roles::BILLING, Roles::VITAL_NURSE, Roles::COUNSELOR])) {
            return true;
        }

        if ($this->hasAnyRole(Roles::clinicalProviders())) {
            return $this->assignedPatients()->where('patients.id', $patient->id)->exists()
                || $patient->primary_provider_id === $this->id;
        }

        return false;
    }

    public function canPrescribe(): bool
    {
        if (! $this->can_prescribe || ! $this->hasAnyRole(Roles::canWritePrescriptions())) {
            return false;
        }

        // NP must hold a license_state allowed by env PRESCRIBE_ALLOWED_STATES
        if ($this->hasRole(Roles::NP)) {
            $allowed = config('drmonk.prescribe_allowed_states', '*');
            if ($allowed !== '*' && $allowed !== '' && $allowed !== null) {
                $states = collect(explode(',', (string) $allowed))
                    ->map(fn ($s) => strtoupper(trim($s)))
                    ->filter()
                    ->all();
                $license = strtoupper((string) ($this->license_state ?: ''));
                if ($license === '' || ! in_array($license, $states, true)) {
                    return false;
                }
            }
        }

        return true;
    }
}
