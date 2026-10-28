<?php

namespace App\Notifications\Channels;

use App\Manager\PushNotificationService;
use App\Manager\WebPushNotificationService;
use Illuminate\Notifications\Notification;

class PushChannel
{
    /**
     * Send the given notification via push channels (FCM & WebPush).
     */
    public function send(mixed $notifiable, Notification $notification): void
    {
        $profileId = $notifiable->profile?->id ?? $notifiable->id;

        $title = $notification->pushTitle ?? $notification->title ?? '';
        $body = $notification->pushBody ?? $notification->body ?? '';
        $data = $notification->data ?? [];

        if (empty($title) && empty($body)) {
            return;
        }

        // Send FCM Push Notification to mobile devices
        /** @var PushNotificationService $pushService */
        $pushService = app(PushNotificationService::class);
        $pushService->sendToUser($profileId, $title, $body, $data);

        // Send Web Push Notification to browsers
        /** @var WebPushNotificationService $webPushService */
        $webPushService = app(WebPushNotificationService::class);
        $webPushService->sendToUser($profileId, $title, $body, $data);
    }
}
