<?php

namespace App\Manager;

use App\Models\Notification;
use App\Models\NotificationPreference;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class NotificationManager
{
    protected PushNotificationService $pushService;

    protected WebPushNotificationService $webPushService;

    public function __construct(
        PushNotificationService $pushService,
        WebPushNotificationService $webPushService,
    ) {
        $this->pushService = $pushService;
        $this->webPushService = $webPushService;
    }

    /**
     * List all notifications for the current user (paginated, newest first).
     */
    public function index(int $perPage = 20)
    {
        $profileId = (int) $this->profileId();

        return Notification::forUser($profileId)
            ->latestFirst()
            ->paginate($perPage);
    }

    /**
     * Count unread notifications for the current user.
     */
    public function unreadCount(): int
    {
        $profileId = (int) $this->profileId();

        return Notification::forUser($profileId)->unread()->count();
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead(Notification $notification): Notification
    {
        $this->ensureOwned($notification);

        $notification->update(['is_read' => true]);

        return $notification->fresh();
    }

    /**
     * Mark all notifications as read for the current user.
     */
    public function markAllAsRead(): int
    {
        $profileId = (int) $this->profileId();

        return Notification::forUser($profileId)->unread()->update(['is_read' => true]);
    }

    /**
     * Dispatch a notification to a specific user via all enabled channels:
     *
     *   1. In-app (DB) notification  — stored in `notifications` table
     *   2. FCM push (mobile)         — sent via FCM HTTP v1 API (device tokens)
     *   3. Web Push (browser)        — sent via VAPID Web Push API (push_subscriptions)
     *
     * Respects the recipient's notification preferences per category:
     *   - in_app : toggles the DB notification
     *   - push   : toggles both FCM and Web Push
     *
     * Email notifications are handled separately by the calling manager
     * (BookingManager, TicketManager, ConversationManager) since they
     * have the full model context needed to build the email content.
     *
     * ─── Parameters ───────────────────────────────────────────────────
     *
     * @param  int  $recipientProfileId  The profile ID of the recipient.
     * @param  string  $type  Notification type (e.g. 'booking.created', 'ticket.purchased').
     * @param  string  $title  Short notification title.
     * @param  string  $message  Notification body text.
     * @param  array  $data  Optional JSON payload with additional context.
     * @return Notification|null The created notification or null if skipped/errored.
     */
    public function dispatch(int $recipientProfileId, string $type, string $title, string $message, ?array $data = null): ?Notification
    {
        try {
            // ── Check user's notification preferences ────────────
            $prefs = NotificationPreference::forUser($recipientProfileId);
            $pushEnabled = $prefs->isPushEnabled($type);
            $inAppEnabled = $prefs->isInAppEnabled($type);

            // ── 1. Create in-app notification (if enabled) ──────
            $notification = null;

            if ($inAppEnabled) {
                $notification = Notification::create([
                    'user_id' => $recipientProfileId,
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'data' => $data,
                ]);
            }

            // ── 2. Send FCM push notification (mobile, if enabled) ──
            if ($pushEnabled) {
                $this->pushService->sendToUser(
                    $recipientProfileId,
                    $title,
                    $message,
                    $data ?? []
                );
            }

            // ── 3. Send Web Push notification (browser, if enabled) ──
            if ($pushEnabled) {
                $this->webPushService->sendToUser(
                    $recipientProfileId,
                    $title,
                    $message,
                    $data ?? []
                );
            }

            return $notification;

        } catch (\Throwable $e) {
            Log::error('Notification dispatch error', [
                'recipient' => $recipientProfileId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Ensure the notification belongs to the current user.
     */
    protected function ensureOwned(Notification $notification): void
    {
        if ((int) $notification->user_id !== (int) $this->profileId()) {
            abort(404);
        }
    }

    /**
     * Get the current authenticated user's profile ID.
     */
    protected function profileId(): ?int
    {
        $user = Auth::guard('jwt')->user();

        return $user?->profile?->id;
    }
}
