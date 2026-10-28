<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * RolePermission - Maps legacy roles to their allowed permissions.
 *
 * Note: This model uses the custom `role_permissions` table (JSON-based).
 * For new permission checking via Spatie, the spatie/laravel-permission package
 * tables are used instead. This model is kept for backward compatibility
 * with the legacy permission storage format.
 */
class RolePermission extends Model
{
    protected $table = 'role_permissions';

    protected $fillable = [
        'role',
        'permissions',
    ];

    protected $casts = [
        'permissions' => 'array',
    ];
}
