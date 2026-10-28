<?php

declare(strict_types=1);

namespace Modules\Communication\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LivestreamUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * Fired on every viewer join/leave while live.
     * Pushes the server-authoritative viewer_count to the public feed channel
     * so outsiders (profile page, discovery card) see the "X watching" ticker
     * without being in the LiveKit room.
     *
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $streamId,
        public readonly array $payload
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('livestreams'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'LivestreamUpdated';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
