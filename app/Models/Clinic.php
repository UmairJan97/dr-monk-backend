<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Clinic extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'subdomain', 'custom_domain', 'timezone', 'logo_path',
        'working_hours', 'notification_templates', 'hipaa_settings', 'status',
        'subscription_plan_id', 'stripe_customer_id',
        'stripe_subscription_id', 'trial_ends_at', 'ai_credits_balance', 'storage_used_mb',
    ];

    protected function casts(): array
    {
        return [
            'working_hours' => 'array',
            'notification_templates' => 'array',
            'hipaa_settings' => 'array',
            'trial_ends_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'trial'], true);
    }
}
