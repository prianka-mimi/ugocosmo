<?php

declare(strict_types=1);

namespace Modules\Communication\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LivestreamEnded implements ShouldBroadcastNow
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
        public readonly array $payload
    ) {}

    public function broadcastOn(): array
    {
        $channels = [
            new Channel('livestreams'),
        ];

        foreach ($this->followerIds as $followerId) {
            $channels[] = new PrivateChannel('user.'.$followerId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'LivestreamEnded';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
