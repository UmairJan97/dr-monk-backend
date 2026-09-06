<?php

namespace App\Models;

use App\Support\AclCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClinicAclRole extends Model
{
    protected $fillable = [
        'clinic_id',
        'name',
        'slug',
        'description',
        'base_role',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function permissionRows(): HasMany
    {
        return $this->hasMany(ClinicAclRolePermission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'clinic_acl_role_id');
    }

    /** @return list<string> */
    public function permissionKeys(): array
    {
        return $this->permissionRows->pluck('permission_key')->values()->all();
    }

    public function toApiArray(?int $assignedUsers = null): array
    {
        $keys = $this->permissionKeys();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'base_role' => $this->base_role,
            'is_system' => (bool) $this->is_system,
            'is_locked' => (bool) $this->is_system,
            'permission_keys' => $keys,
            'permission_count' => count($keys),
            'catalog_count' => count(AclCatalog::permissionKeys()),
            'assigned_users' => $assignedUsers,
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
