<?php

declare(strict_types=1);

namespace Modules\Communication\Traits;

use App\Models\User;
use App\Services\NotificationService;
use Modules\Communication\Models\CallParticipant;

trait SendsPushNotifications
{
    /**
     * Check if a user is currently on an active call.
     *
     * @param  int  $userId  The ID of the user to check
     * @return bool True if user is on an active call, false otherwise
     */
    protected function isUserOnCall(int $userId): bool
    {
        return CallParticipant::query()
            ->whereHas('call', function ($query) {
                $query->where('status', 'ongoing');
            })
            ->where('user_id', $userId)
            ->whereNotNull('joined_at')
            ->whereNull('left_at')
            ->exists();
    }

    /**
     * Send a push notification to ring a user's phone.
     *
     * @param  User  $user  The user to notify
     * @param  array<string, mixed>  $callData  Call information (call_id, call_type, initiator_name, etc.)
     */
    protected function sendCallRingingNotification(User $user, array $callData): void
    {
        $initiatorName = $callData['initiator_name'] ?? 'Someone';
        $callType = $callData['call_type'] ?? 'voice';
        $title = "{$initiatorName} is calling...";
        $body = ucfirst((string) $callType).' call incoming';

        NotificationService::send(
            user: $user,
            type: 'incoming_call',
            title: $title,
            body: $body,
            pushTitle: $title,
            pushBody: $body,
            data: [
                'notification_type' => 'incoming_call',
                'call_id' => $callData['call_id'] ?? null,
                'call_payload' => $callData,
            ],
            image: $callData['initiator_avatar'] ?? null
        );
    }

    /**
     * Send ringing notifications to all participants not currently on a call.
     *
     * @param  array<int>  $participantIds  List of participant user IDs
     * @param  array<string, mixed>  $callData  Call information
     * @param  int  $initiatorId  The ID of the call initiator
     * @return array<int, bool> Array with user_id as key and notification sent status as value
     */
    protected function sendRingingNotificationsToParticipants(
        array $participantIds,
        array $callData,
        int $initiatorId
    ): array {
        $results = [];

        foreach ($participantIds as $participantId) {
            // Skip the initiator
            if ($participantId === $initiatorId) {
                $results[$participantId] = false;

                continue;
            }

            // Only send if user is not already on a call
            if (! $this->isUserOnCall($participantId)) {
                try {
                    $user = User::findOrFail($participantId);
                    $this->sendCallRingingNotification($user, $callData);
                    $results[$participantId] = true;
                } catch (\Throwable $exception) {
                    report($exception);
                    $results[$participantId] = false;
                }
            } else {
                $results[$participantId] = false;
            }
        }

        return $results;
    }
}
