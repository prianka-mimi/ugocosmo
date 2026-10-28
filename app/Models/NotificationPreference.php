<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NotificationPreference
 *
 * Stores a user's notification preferences as a JSON document.
 * Each user has exactly one row, created on first access with sensible defaults.
 *
 * ─── Default preferences ──────────────────────────────────────────────
 * All categories default to:
 *   { "push": true, "in_app": true, "email": true }
 *
 * Users can opt out of push (FCM / Web Push), in-app (DB notification),
 * or email (transactional) for any category.
 *
 * ─── Preference categories ────────────────────────────────────────────
 * Category    | Notification types                    | Description
 * ────────────|───────────────────────────────────────|──────────────────
 * booking     | booking.created/confirmed/completed/  | Booking alerts
 *             | cancelled                             |
 * ticket      | ticket.purchased/used                 | Ticket alerts
 * message     | message.new                           | Message alerts
 * livestream  | livestream.comment/gift/join_request/ | Livestream alerts
 *             | invite/join_accepted/join_declined/   |
 *             | invite_accepted/invite_declined       |
 */
class NotificationPreference extends Model
{
    use HasFactory;

    protected $table = 'notification_preferences';

    protected $fillable = [
        'user_id',
        'preferences',
    ];

    protected $casts = [
        'preferences' => 'array',
    ];

    public const CATEGORIES = ['booking', 'ticket', 'message', 'livestream', 'social', 'post', 'store', 'story'];

    /**
     * Default preferences applied when a user has no saved preferences.
     * All channels enabled by default for backward-compatible behavior.
     */
    public const DEFAULTS = [
        'booking' => ['push' => true, 'in_app' => true, 'email' => true],
        'ticket' => ['push' => true, 'in_app' => true, 'email' => true],
        'message' => ['push' => true, 'in_app' => true, 'email' => true],
        'livestream' => ['push' => true, 'in_app' => true, 'email' => true],
        'social' => ['push' => true, 'in_app' => true, 'email' => true],
        'post' => ['push' => true, 'in_app' => true, 'email' => true],
        'store' => ['push' => true, 'in_app' => true, 'email' => true],
        'story' => ['push' => true, 'in_app' => true, 'email' => true],
    ];

    // ─── Relationships ────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'user_id');
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    /**
     * Get or create notification preferences for a user.
     *
     * If the user doesn't have preferences yet, creates them with defaults.
     * If existing preferences are missing new categories or fields (added in
     * a later release), they are automatically merged in.
     */
    public static function forUser(int $userId): self
    {
        $prefs = static::firstOrNew(['user_id' => $userId]);

        if (! $prefs->exists) {
            // New user — set defaults
            $prefs->preferences = self::DEFAULTS;
            $prefs->save();
        } else {
            // Existing user — merge any missing categories/fields (forward-compatible)
            $existing = $prefs->preferences ?? [];
            $merged = self::DEFAULTS;

            foreach (self::CATEGORIES as $category) {
                if (isset($existing[$category])) {
                    // Merge defaults underneath so new fields (e.g. 'email') get added
                    $merged[$category] = array_merge(
                        self::DEFAULTS[$category],
                        $existing[$category]
                    );
                }
            }

            // Only save if something was merged
            if ($merged !== $existing) {
                $prefs->preferences = $merged;
                $prefs->save();
            }
        }

        return $prefs->fresh();
    }

    /**
     * Check if push notifications are enabled for a given notification type.
     */
    public function isPushEnabled(string $type): bool
    {
        $category = $this->deriveCategory($type);
        $prefs = $this->preferences ?? [];

        return $prefs[$category]['push'] ?? true;
    }

    /**
     * Check if in-app notifications are enabled for a given notification type.
     */
    public function isInAppEnabled(string $type): bool
    {
        $category = $this->deriveCategory($type);
        $prefs = $this->preferences ?? [];

        return $prefs[$category]['in_app'] ?? true;
    }

    /**
     * Check if email notifications are enabled for a given notification type.
     */
    public function isEmailEnabled(string $type): bool
    {
        $category = $this->deriveCategory($type);
        $prefs = $this->preferences ?? [];

        return $prefs[$category]['email'] ?? true;
    }

    /**
     * Derive the preference category from a notification type string.
     *
     * Examples:
     *   'booking.created'     → 'booking'
     *   'booking.confirmed'   → 'booking'
     *   'ticket.purchased'    → 'ticket'
     *   'message.new'         → 'message'
     *   'livestream.comment'  → 'livestream'
     */
    public function deriveCategory(string $type): string
    {
        $parts = explode('.', $type, 2);
        $category = $parts[0];

        return in_array($category, self::CATEGORIES, true) ? $category : $type;
    }
}
