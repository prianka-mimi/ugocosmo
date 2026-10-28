<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class SmsChannel
{
    /**
     * Send the given notification via SMS.
     */
    public function send(mixed $notifiable, Notification $notification): void
    {
        $phone = method_exists($notifiable, 'routeNotificationForSms')
            ? $notifiable->routeNotificationForSms()
            : ($notifiable->mobile_number ?? null);

        $body = method_exists($notification, 'toSms')
            ? $notification->toSms($notifiable)
            : ($notification->smsBody ?? $notification->body);

        if (empty($phone) || empty($body)) {
            return;
        }

        Log::info('SMS Notification dispatch', [
            'to' => $phone,
            'body' => $body,
        ]);
    }
}
