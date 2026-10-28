<?php

declare(strict_types=1);

namespace Modules\Communication\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LivestreamHostInvited implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $targetUserId,
        public readonly array $payload
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->targetUserId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'LivestreamHostInvited';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
