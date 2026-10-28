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

class LivestreamStarted implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $streamId,
        public readonly array $followerIds,
        public readonly array $payload,
        public readonly ?int $conversationId = null
    ) {}

    public function broadcastOn(): array
    {
        $channels = [
            new Channel('livestreams'), // public discovery feed
        ];

        if ($this->conversationId !== null && $this->conversationId > 0) {
            $channels[] = new PresenceChannel('call.'.$this->conversationId);
        }

        foreach ($this->followerIds as $followerId) {
            $channels[] = new PrivateChannel('user.'.$followerId); // per-user "X went live"
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'LivestreamStarted';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
