<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyWebhookCall extends Model
{
    use HasFactory;

    protected $fillable = [
        'shopify_store_id',
        'topic',
        'shop_domain',
        'webhook_id',
        'payload',
        'status',
        'processing_attempts',
        'dead_lettered_at',
        'processed_at',
        'error_message',
    ];

    protected $casts = [
        'payload' => 'array',
        'processing_attempts' => 'integer',
        'dead_lettered_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DEAD_LETTER = 'dead_letter';

    public const STATUS_DUPLICATE = 'duplicate';

    /**
     * Maximum processing attempts before moving to dead letter.
     */
    public const MAX_ATTEMPTS = 5;

    public function store(): BelongsTo
    {
        return $this->belongsTo(ShopifyStore::class, 'shopify_store_id');
    }

    // ─── Scopes ────────────────────────────────────────────────────

    public function scopeUnprocessed($query)
    {
        return $query->where('status', self::STATUS_RECEIVED);
    }

    public function scopeByTopic($query, string $topic)
    {
        return $query->where('topic', $topic);
    }

    public function scopeDeadLettered($query)
    {
        return $query->where('status', self::STATUS_DEAD_LETTER);
    }

    public function scopeByWebhookId($query, string $webhookId)
    {
        return $query->where('webhook_id', $webhookId);
    }

    public function scopeFailedRecently($query, int $minutes = 60)
    {
        return $query->whereIn('status', [self::STATUS_FAILED, self::STATUS_DEAD_LETTER])
            ->where('created_at', '>=', now()->subMinutes($minutes));
    }

    // ─── Helpers ───────────────────────────────────────────────────

    public function isDuplicate(): bool
    {
        return $this->status === self::STATUS_DUPLICATE;
    }

    public function isDeadLettered(): bool
    {
        return $this->status === self::STATUS_DEAD_LETTER || ! is_null($this->dead_lettered_at);
    }

    public function markProcessing(): void
    {
        $this->increment('processing_attempts');
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    public function markProcessed(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSED,
            'processed_at' => now(),
        ]);
    }

    public function markFailed(string $errorMessage): void
    {
        $data = [
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
        ];

        // Move to dead letter if max attempts exceeded
        if (($this->processing_attempts ?? 0) >= self::MAX_ATTEMPTS) {
            $data['status'] = self::STATUS_DEAD_LETTER;
            $data['dead_lettered_at'] = now();
        }

        $this->update($data);
    }

    public function markDuplicate(): void
    {
        $this->update(['status' => self::STATUS_DUPLICATE]);
    }
}
