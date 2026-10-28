<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\SendNotification;

class NotificationService
{
    /**
     * Send notification to a single user.
     *
     * @param  array<string, mixed>|null  $data
     * @param  list<string>|null  $channels
     */
    public static function send(
        User $user,
        string $type = 'general',
        ?string $title = null,
        ?string $body = null,
        ?string $pushTitle = null,
        ?string $pushBody = null,
        ?string $smsBody = null,
        ?array $data = null,
        ?array $channels = null,
        ?string $mailSubject = null,
        ?string $mailView = null,
        ?string $image = null,
        bool $bypassPrefs = false,
    ): void {
        $user->notify(new SendNotification(
            type: $type,
            title: $title,
            body: $body,
            pushTitle: $pushTitle,
            pushBody: $pushBody,
            smsBody: $smsBody,
            data: $data,
            customChannels: $channels,
            mailSubject: $mailSubject,
            mailView: $mailView,
            image: $image,
            bypassPrefs: $bypassPrefs,
        ));
    }

    /**
     * Send notification to multiple users.
     *
     * @param  iterable<int, User>  $users
     * @param  array<string, mixed>|null  $data
     * @param  list<string>|null  $channels
     */
    public static function sendToMany(
        iterable $users,
        string $type = 'general',
        ?string $title = null,
        ?string $body = null,
        ?string $pushTitle = null,
        ?string $pushBody = null,
        ?string $smsBody = null,
        ?array $data = null,
        ?array $channels = null,
        ?string $mailSubject = null,
        ?string $mailView = null,
        ?string $image = null,
        bool $bypassPrefs = false,
    ): void {
        foreach ($users as $user) {
            self::send(
                user: $user,
                type: $type,
                title: $title,
                body: $body,
                pushTitle: $pushTitle,
                pushBody: $pushBody,
                smsBody: $smsBody,
                data: $data,
                channels: $channels,
                mailSubject: $mailSubject,
                mailView: $mailView,
                image: $image,
                bypassPrefs: $bypassPrefs,
            );
        }
    }
}
