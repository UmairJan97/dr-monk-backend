<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClinicAclRole;
use App\Services\AuditService;
use App\Services\ClinicAclService;
use App\Support\AclCatalog;
use App\Support\ApiResponse;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AclController extends Controller
{
    public function __construct(
        private ClinicAclService $acl,
        private AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $clinicId = $this->clinicId($request);
        $roles = $this->acl->rolesForClinic($clinicId)->map(
            fn (ClinicAclRole $role) => $role->toApiArray($this->acl->assignedUserCount($role))
        );

        return ApiResponse::success([
            'catalog' => AclCatalog::modules(),
            'roles' => $roles,
            'base_roles' => collect(Roles::clinicAssignable())
                ->map(fn (string $slug) => [
                    'slug' => $slug,
                    'label' => AclCatalog::roleLabel($slug),
                ])
                ->values()
                ->all(),
            'permission_count' => count(AclCatalog::permissionKeys()),
        ], 'Clinic ACL');
    }

    public function store(Request $request): JsonResponse
    {
        $clinicId = $this->clinicId($request);
        $this->acl->ensureForClinic($clinicId);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80', Rule::unique('clinic_acl_roles', 'name')->where('clinic_id', $clinicId)],
            'description' => ['nullable', 'string', 'max:240'],
            'base_role' => ['required', 'string', Rule::in(Roles::clinicAssignable())],
            'permission_keys' => ['required', 'array'],
            'permission_keys.*' => ['string'],
        ]);

        $keys = $this->acl->sanitizeKeys($data['permission_keys']);
        $this->assertKnownKeys($data['permission_keys']);

        $reserved = Roles::clinicAssignable();
        $slug = $this->acl->uniqueSlug($clinicId, $data['name']);
        if (in_array($slug, $reserved, true)) {
            $slug = $this->acl->uniqueSlug($clinicId, $data['name'].'-custom');
        }

        $role = DB::transaction(function () use ($clinicId, $data, $slug, $keys) {
            $role = ClinicAclRole::query()->create([
                'clinic_id' => $clinicId,
                'name' => $data['name'],
                'slug' => $slug,
                'description' => $data['description'] ?? null,
                'base_role' => $data['base_role'],
                'is_system' => false,
            ]);
            $this->acl->syncPermissions($role, $keys);

            return $role;
        });

        $this->audit->log('acl.role_create', 'allowed', $request->user(), $request, entityType: ClinicAclRole::class, entityId: $role->id, meta: [
            'name' => $role->name,
            'permission_count' => count($keys),
        ]);

        return ApiResponse::created($role->fresh('permissionRows')->toApiArray(0), 'Role created');
    }

    public function update(Request $request, ClinicAclRole $aclRole): JsonResponse
    {
        $clinicId = $this->clinicId($request);
        abort_unless($aclRole->clinic_id === $clinicId, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:80', Rule::unique('clinic_acl_roles', 'name')->where('clinic_id', $clinicId)->ignore($aclRole->id)],
            'description' => ['nullable', 'string', 'max:240'],
            'permission_keys' => ['required', 'array'],
            'permission_keys.*' => ['string'],
        ]);

        $keys = $this->acl->sanitizeKeys($data['permission_keys']);
        $this->assertKnownKeys($data['permission_keys']);
        $this->guardSelfLockout($request->user(), $aclRole, $keys);

        if ($aclRole->is_system) {
            $aclRole->description = $data['description'] ?? $aclRole->description;
            $aclRole->save();
        } else {
            if (! empty($data['name']) && $data['name'] !== $aclRole->name) {
                $aclRole->name = $data['name'];
                $aclRole->slug = $this->acl->uniqueSlug($clinicId, $data['name'], $aclRole->id);
            }
            if (array_key_exists('description', $data)) {
                $aclRole->description = $data['description'];
            }
            $aclRole->save();
        }

        $this->acl->syncPermissions($aclRole, $keys);

        $this->audit->log('acl.role_update', 'allowed', $request->user(), $request, entityType: ClinicAclRole::class, entityId: $aclRole->id, meta: [
            'name' => $aclRole->name,
            'permission_count' => count($keys),
        ]);

        return ApiResponse::success(
            $aclRole->fresh('permissionRows')->toApiArray($this->acl->assignedUserCount($aclRole)),
            'Role updated'
        );
    }

    public function destroy(Request $request, ClinicAclRole $aclRole): JsonResponse
    {
        $clinicId = $this->clinicId($request);
        abort_unless($aclRole->clinic_id === $clinicId, 404);

        if ($aclRole->is_system) {
            throw ValidationException::withMessages([
                'role' => ['System roles cannot be deleted. Clear or change their permissions instead.'],
            ]);
        }

        if ($this->acl->assignedUserCount($aclRole) > 0) {
            throw ValidationException::withMessages([
                'role' => ['This role is assigned to staff. Reassign them before deleting.'],
            ]);
        }

        $id = $aclRole->id;
        $name = $aclRole->name;
        $aclRole->delete();

        $this->audit->log('acl.role_delete', 'allowed', $request->user(), $request, entityType: ClinicAclRole::class, entityId: $id, meta: [
            'name' => $name,
        ]);

        return ApiResponse::success(null, 'Role deleted');
    }

    private function clinicId(Request $request): int
    {
        $id = $request->user()?->clinic_id;
        abort_unless($id, 403, 'No clinic on this account.');

        return (int) $id;
    }

    /** @param  list<string>  $keys */
    private function assertKnownKeys(array $keys): void
    {
        $unknown = array_values(array_diff($keys, AclCatalog::permissionKeys()));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'permission_keys' => ['Unknown permission: '.$unknown[0]],
            ]);
        }
    }

    /** @param  list<string>  $keys */
    private function guardSelfLockout($user, ClinicAclRole $role, array $keys): void
    {
        $current = $this->acl->resolveRoleForUser($user);
        if (! $current || $current->id !== $role->id) {
            return;
        }

        if (! in_array(AclCatalog::ACL_MANAGE, $keys, true)) {
            throw ValidationException::withMessages([
                'permission_keys' => ['You cannot remove ACL / Roles access from the role you are using.'],
            ]);
        }
    }
}
