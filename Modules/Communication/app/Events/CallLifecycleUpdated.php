<?php

declare(strict_types=1);

namespace Modules\Communication\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallLifecycleUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<int>  $participantIds
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $eventName,
        public readonly int $conversationId,
        public readonly array $participantIds,
        public readonly array $payload
    ) {}

    public function broadcastOn(): array
    {
        $channels = [
            new PresenceChannel('call.'.$this->conversationId),
        ];

        foreach ($this->participantIds as $participantId) {
            $channels[] = new PrivateChannel('user.'.$participantId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return $this->eventName;
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
