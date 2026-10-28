<?php

declare(strict_types=1);

namespace Modules\Communication\Listeners;

use App\Models\User;
use App\Services\NotificationService;
use Modules\Communication\Events\CallLifecycleUpdated;

class CallLifecycleUpdatedListener
{
    public function handle(CallLifecycleUpdated $event): void
    {
        try {
            $callPayload = $event->payload['call'] ?? $event->payload;
            $callId = (int) ($callPayload['call_id'] ?? 0);

            if ($callId <= 0) {
                return;
            }

            $action = (string) ($event->payload['action'] ?? $event->eventName);
            $actorId = $this->resolveActorId($event->payload);
            $actorName = $this->resolveActorName($event->payload, $action);

            foreach ($event->participantIds as $participantId) {
                $participantId = (int) $participantId;

                if ($participantId <= 0 || $participantId === $actorId) {
                    continue;
                }

                $user = User::query()->find($participantId);

                if (! $user instanceof User) {
                    continue;
                }

                $title = $this->notificationTitle($action, $actorName);
                $body = $this->notificationBody($action, $actorName);

                NotificationService::send(
                    user: $user,
                    type: $action,
                    title: $title,
                    body: $body,
                    pushTitle: $title,
                    pushBody: $body,
                    data: [
                        'notification_type' => $action,
                        'call_id' => $callId,
                        'call_payload' => $callPayload,
                    ]
                );
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function resolveActorId(array $payload): int
    {
        foreach (['participant_id', 'ended_by_id', 'updated_by_id'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return 0;
    }

    private function resolveActorName(array $payload, string $action): string
    {
        $call = $payload['call'] ?? [];

        $name = trim((string) (
            $payload['participant_name']
            ?? $payload['ended_by_name']
            ?? $payload['updated_by_name']
            ?? $call['initiator']['name']
            ?? $call['initiator_name']
            ?? ''
        ));

        if ($name !== '') {
            return $name;
        }

        return match ($action) {
            'CallJoined', 'joined' => 'A participant',
            'CallLeft', 'left' => 'A participant',
            'CallEnded', 'ended' => 'The call',
            default => 'Someone',
        };
    }

    private function notificationTitle(string $action, string $actorName): string
    {
        return match ($action) {
            'CallJoined', 'joined' => $actorName.' joined the call',
            'CallLeft', 'left' => $actorName.' left the call',
            'CallEnded', 'ended' => 'Call ended',
            default => 'Call update',
        };
    }

    private function notificationBody(string $action, string $actorName): string
    {
        return match ($action) {
            'CallJoined', 'joined' => $actorName.' joined the call.',
            'CallLeft', 'left' => $actorName.' left the call.',
            'CallEnded', 'ended' => 'Call ended.',
            default => 'Call status updated.',
        };
    }
}
