<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dispute - A dispute raised against an escrow payment.
 *
 * Either party can raise a dispute. An admin resolves it
 * by choosing to refund the buyer or release to the seller.
 */
class Dispute extends Model
{
    use HasFactory;

    protected $table = 'disputes';

    protected $fillable = [
        'order_id',
        'escrow_payment_id',
        'raised_by',
        'reason',
        'description',
        'evidence_urls',
        'status',
        'resolution',
        'resolution_notes',
        'resolved_by',
        'resolved_at',
        'refund_amount',
    ];

    protected $casts = [
        'evidence_urls' => 'json',
        'refund_amount' => 'decimal:2',
        'resolved_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function escrowPayment(): BelongsTo
    {
        return $this->belongsTo(EscrowPayment::class, 'escrow_payment_id');
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'raised_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'resolved_by');
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['pending', 'investigating']);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByUser($query, int $profileId)
    {
        return $query->where('raised_by', $profileId);
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}
