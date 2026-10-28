<?php

declare(strict_types=1);

namespace Modules\Communication\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LivestreamGiftSent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $streamId,
        public readonly array $payload,
        public readonly ?int $conversationId = null
    ) {}

    public function broadcastOn(): array
    {
        $channels = [
            new Channel('livestreams'),
        ];

        if ($this->conversationId !== null && $this->conversationId > 0) {
            $channels[] = new PresenceChannel('call.'.$this->conversationId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'LivestreamGiftSent';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
