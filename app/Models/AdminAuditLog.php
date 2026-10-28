<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AdminAuditLog - Records every action performed by admin/supervisor users.
 *
 * Used for accountability and auditing of all administrative actions
 * including user management, content moderation, and role changes.
 */
class AdminAuditLog extends Model
{
    use HasFactory;

    protected $table = 'admin_audit_logs';

    protected $fillable = [
        'admin_id',
        'action',       // e.g. 'user_suspended', 'post_deactivated', 'role_assigned'
        'entity_type',  // e.g. 'user', 'post', 'product', 'event'
        'entity_id',
        'old_value',    // JSON snapshot before the change
        'new_value',    // JSON snapshot after the change
        'ip_address',
    ];

    protected $casts = [
        'old_value' => 'json',
        'new_value' => 'json',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'admin_id');
    }

    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeByEntityType($query, string $entityType)
    {
        return $query->where('entity_type', $entityType);
    }
}
