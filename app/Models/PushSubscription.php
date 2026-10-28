<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PushSubscription
 *
 * Stores Web Push API subscription data for browser push notifications.
 * Uses the existing `push_subscriptions` table created alongside notifications.
 *
 * Each subscription contains:
 * - endpoint: The Push API endpoint URL (unique URL per browser/device).
 * - p256dh   : The client's public ECDH key (base64-encoded) for encryption.
 * - auth     : The client's auth secret (base64-encoded) for encryption.
 *
 * These three values are provided by the browser's Push API when a user
 * subscribes to push notifications via JavaScript:
 *
 *   navigator.serviceWorker.ready.then(reg => {
 *       reg.pushManager.subscribe({
 *           userVisibleOnly: true,
 *           applicationServerKey: urlBase64ToUint8Array(vapidPublicKey)
 *       }).then(sub => {
 *           // Send sub.toJSON() to the server
 *       });
 *   });
 *
 * @property int $id
 * @property int $user_id FK to profiles
 * @property string $endpoint Push API endpoint URL
 * @property string|null $p256dh Client public key (base64)
 * @property string|null $auth Client auth secret (base64)
 * @property-read Profile $user
 */
class PushSubscription extends Model
{
    use HasFactory;

    protected $table = 'push_subscriptions';

    protected $fillable = [
        'user_id',
        'endpoint',
        'p256dh',
        'auth',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'user_id');
    }

    /**
     * Scope: subscriptions belonging to a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: find a subscription by its unique endpoint URL.
     */
    public function scopeByEndpoint($query, string $endpoint)
    {
        return $query->where('endpoint', $endpoint);
    }
}
