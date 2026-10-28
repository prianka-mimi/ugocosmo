<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LivestreamGift extends Model
{
    protected $table = 'communication_livestream_gifts';

    protected $fillable = [
        'call_log_id',
        'sender_id',
        'gift_id',
        'gift_type',
        'amount',
        'message',
        'sort_order',
        'stripe_payment_intent_id',
        'status',
    ];

    protected $casts = [
        'call_log_id' => 'integer',
        'sender_id' => 'integer',
        'gift_id' => 'integer',
        'amount' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class, 'call_log_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function gift(): BelongsTo
    {
        return $this->belongsTo(Gift::class, 'gift_id');
    }
}
