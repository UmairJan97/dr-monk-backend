<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicAclRolePermission extends Model
{
    protected $fillable = [
        'clinic_acl_role_id',
        'permission_key',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(ClinicAclRole::class, 'clinic_acl_role_id');
    }
}
