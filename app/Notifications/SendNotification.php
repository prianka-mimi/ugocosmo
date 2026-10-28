<?php

namespace App\Notifications;

use App\Notifications\Channels\DbChannel;
use App\Notifications\Channels\PushChannel;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;

class SendNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const CHANNEL_DATABASE = 'database';

    public const CHANNEL_PUSH = 'push';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    /** @var list<string> */
    public const CHANNELS = [
        self::CHANNEL_DATABASE,
        self::CHANNEL_PUSH,
        self::CHANNEL_EMAIL,
        self::CHANNEL_SMS,
    ];

    /** @var list<string> */
    public const DEFAULT_CHANNELS = [
        self::CHANNEL_DATABASE,
        self::CHANNEL_PUSH,
    ];

    /**
     * @param  array<string, mixed>|null  $data
     * @param  list<string>|null  $customChannels  Explicit channels. Null means use user preferences.
     */
    public function __construct(
        public string $type = 'general',
        public ?string $title = null,
        public ?string $body = null,
        public ?string $pushTitle = null,
        public ?string $pushBody = null,
        public ?string $smsBody = null,
        public ?array $data = null,
        public ?array $customChannels = null,
        public ?string $mailSubject = null,
        public ?string $mailView = null,
        public ?string $image = null,
        public bool $bypassPrefs = false,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string|class-string>
     */
    public function via(mixed $notifiable): array
    {
        $baseChannels = $this->customChannels ?? self::DEFAULT_CHANNELS;

        if ($this->bypassPrefs) {
            $channels = $baseChannels;
        } else {
            $channels = [];
            if (method_exists($notifiable, 'receivesNotificationChannel')) {
                foreach ($baseChannels as $channel) {
                    if ($notifiable->receivesNotificationChannel($this->type, $channel)) {
                        $channels[] = $channel;
                    }
                }
            } else {
                $channels = $baseChannels;
            }
        }

        return array_values(array_unique(array_filter(array_map(
            fn (string $channel): ?string => $this->resolveChannel($channel, $notifiable),
            $channels,
        ))));
    }

    private function resolveChannel(string $channel, mixed $notifiable): ?string
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException("Unsupported notification channel [{$channel}].");
        }

        return match ($channel) {
            self::CHANNEL_DATABASE => DbChannel::class,
            self::CHANNEL_PUSH => PushChannel::class,
            self::CHANNEL_EMAIL => 'mail',
            self::CHANNEL_SMS => SmsChannel::class,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(mixed $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'type' => $this->type,
            'data' => $this->data ?? [],
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->mailSubject ?? $this->title ?? 'Notification');

        if ($this->mailView) {
            $mail->view($this->mailView, ['data' => $this->data ?? [], 'body' => $this->body]);
        } else {
            $mail->line($this->body ?? $this->title ?? '');
        }

        return $mail;
    }

    public function toSms(mixed $notifiable): ?string
    {
        return $this->smsBody ?? $this->body;
    }
}
