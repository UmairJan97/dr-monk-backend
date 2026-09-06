<?php

namespace App\Services;

use App\Models\ClinicAclRole;
use App\Models\ClinicAclRolePermission;
use App\Models\User;
use App\Support\AclCatalog;
use App\Support\Roles;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClinicAclService
{
    public function ensureForClinic(int $clinicId): void
    {
        foreach (Roles::clinicAssignable() as $slug) {
            $role = ClinicAclRole::query()->firstOrCreate(
                [
                    'clinic_id' => $clinicId,
                    'slug' => $slug,
                ],
                [
                    'name' => AclCatalog::roleLabel($slug),
                    'description' => AclCatalog::roleDescription($slug),
                    'base_role' => $slug,
                    'is_system' => true,
                ]
            );

            if ($role->wasRecentlyCreated) {
                $this->syncPermissions($role, AclCatalog::defaultsForRole($slug));
            }
        }
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function sanitizeKeys(array $keys): array
    {
        $allowed = AclCatalog::permissionKeys();

        return array_values(array_unique(array_values(array_intersect($keys, $allowed))));
    }

    /**
     * @param  list<string>  $keys
     */
    public function syncPermissions(ClinicAclRole $role, array $keys): void
    {
        $keys = $this->sanitizeKeys($keys);

        DB::transaction(function () use ($role, $keys) {
            ClinicAclRolePermission::query()
                ->where('clinic_acl_role_id', $role->id)
                ->delete();

            $now = now();
            $rows = array_map(fn (string $key) => [
                'clinic_acl_role_id' => $role->id,
                'permission_key' => $key,
                'created_at' => $now,
                'updated_at' => $now,
            ], $keys);

            if ($rows !== []) {
                ClinicAclRolePermission::query()->insert($rows);
            }
        });

        $role->unsetRelation('permissionRows');
        $role->load('permissionRows');
    }

    public function uniqueSlug(int $clinicId, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'role';
        $slug = $base;
        $i = 2;
        while (
            ClinicAclRole::query()
                ->where('clinic_id', $clinicId)
                ->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    public function rolesForClinic(int $clinicId): Collection
    {
        $this->ensureForClinic($clinicId);

        return ClinicAclRole::query()
            ->where('clinic_id', $clinicId)
            ->with('permissionRows')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();
    }

    public function assignedUserCount(ClinicAclRole $role): int
    {
        if ($role->is_system) {
            return User::query()
                ->where('clinic_id', $role->clinic_id)
                ->role($role->slug)
                ->count();
        }

        return User::query()
            ->where('clinic_acl_role_id', $role->id)
            ->count();
    }

    public function resolveRoleForUser(User $user): ?ClinicAclRole
    {
        if (! $user->clinic_id) {
            return null;
        }

        $this->ensureForClinic((int) $user->clinic_id);

        if ($user->clinic_acl_role_id) {
            $custom = ClinicAclRole::query()
                ->with('permissionRows')
                ->where('clinic_id', $user->clinic_id)
                ->whereKey($user->clinic_acl_role_id)
                ->first();
            if ($custom) {
                return $custom;
            }
        }

        $slug = $user->getRoleNames()->first();
        if (! $slug) {
            return null;
        }

        return ClinicAclRole::query()
            ->with('permissionRows')
            ->where('clinic_id', $user->clinic_id)
            ->where('slug', $slug)
            ->first();
    }

    public function userHas(User $user, string $permissionKey): bool
    {
        $role = $this->resolveRoleForUser($user);
        if (! $role) {
            return false;
        }

        return in_array($permissionKey, $role->permissionKeys(), true);
    }
}
