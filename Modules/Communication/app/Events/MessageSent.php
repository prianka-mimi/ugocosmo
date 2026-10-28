<?php

declare(strict_types=1);

namespace Modules\Communication\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<int>  $participantIds
     * @param  array<string, mixed>  $messagePayload
     */
    public function __construct(
        public readonly int $conversationId,
        public readonly array $participantIds,
        public readonly array $messagePayload
    ) {}

    public function broadcastOn(): array
    {
        $channels = [];

        foreach ($this->participantIds as $participantId) {
            $channels[] = new PrivateChannel('user.'.$participantId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'MessageSent';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message' => $this->messagePayload,
        ];
    }
}
