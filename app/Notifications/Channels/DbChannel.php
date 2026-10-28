<?php

namespace App\Notifications\Channels;

use App\Models\Notification as NotificationModel;
use Illuminate\Notifications\Notification;

class DbChannel
{
    /**
     * Send the given notification to the in-app database table (`notifications`).
     */
    public function send(mixed $notifiable, Notification $notification): void
    {
        $profileId = $notifiable->profile?->id ?? $notifiable->id;

        $data = method_exists($notification, 'toArray')
            ? $notification->toArray($notifiable)
            : [];

        NotificationModel::create([
            'user_id' => $profileId,
            'type' => $notification->type ?? 'general',
            'title' => $notification->title ?? ($data['title'] ?? ''),
            'message' => $notification->body ?? ($data['body'] ?? ''),
            'data' => $notification->data ?? ($data['data'] ?? []),
        ]);
    }
}
