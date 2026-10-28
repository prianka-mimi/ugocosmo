<?php

declare(strict_types=1);

namespace Modules\Communication\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LivestreamHostEarningsUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $hostId,
        public readonly array $payload,
        public readonly ?int $streamId = null,
        public readonly ?int $conversationId = null
    ) {}

    public function broadcastOn(): array
    {
        $channels = [
            new Channel('livestreams'),
            new PrivateChannel('user.'.$this->hostId),
        ];

        if ($this->streamId !== null && $this->streamId > 0) {
            $channels[] = new Channel('livestream.'.$this->streamId);
        }

        if ($this->conversationId !== null && $this->conversationId > 0) {
            $channels[] = new PresenceChannel('call.'.$this->conversationId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'LivestreamHostEarningsUpdated';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
