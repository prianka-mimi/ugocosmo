<?php

declare(strict_types=1);

namespace Modules\Communication\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LivestreamHostRevoked implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $targetUserId,
        public readonly int $conversationId,
        public readonly array $payload
    ) {}

    public function broadcastOn(): array
    {
        return [
            // The revoked co-host receives the event to disconnect and rejoin as viewer.
            new PrivateChannel('user.'.$this->targetUserId),
            // Host and every other conversation participant receive the UI update.
            new PrivateChannel('call.'.$this->conversationId),
            // Public livestream viewers may not be conversation participants.
            new Channel('livestreams'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'LivestreamHostRevoked';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
