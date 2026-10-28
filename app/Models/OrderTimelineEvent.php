<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable milestone in an order's lifecycle.
 *
 * Written by OrderTimelineService — never updated after creation.
 */
class OrderTimelineEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id',
        'status',
        'actor_role',
        'actor_profile_id',
        'notes',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'actor_profile_id');
    }

    public function statusLabel(): ?string
    {
        return OrderStatus::tryFrom($this->status)?->label();
    }
}
