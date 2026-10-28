<?php

declare(strict_types=1);

namespace Modules\Communication\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Modules\Communication\Events\CallInitiated;
use Modules\Communication\Models\CallLog;
use Modules\Communication\Services\CallService;
use Modules\Communication\Traits\SendsPushNotifications;

class CallInitiatedListener
{
    use SendsPushNotifications;

    public function __construct(private readonly CallService $callService) {}

    /**
     * Handle the event.
     */
    public function handle(CallInitiated $event): void
    {
        try {
            $callPayload = $event->payload;
            $callId = $callPayload['call_id'] ?? $callPayload['id'] ?? null;

            if (! $callId) {

                return;
            }

            $callLog = CallLog::find($callId);
            if (! $callLog) {
                return;
            }

            $initiatorId = $callLog->initiator_id;
            if (! $initiatorId) {
                return;
            }

            // Get initiator details for the notification
            $initiator = User::find($initiatorId);
            if (! $initiator) {
                return;
            }

            // Prepare base call data for notifications
            $baseCallData = [
                'call_id' => $callId,
                'call_type' => $callPayload['call_type'] ?? 'voice',
                'initiator_id' => $initiatorId,
                'initiator_name' => trim(($initiator->name ?? '')),
                'initiator_avatar' => $initiator->avatar ?? $initiator->avatar_url ?? null,
                'conversation_id' => $event->conversationId,
            ];

            foreach ($event->participantIds as $participantId) {
                if ($participantId === $initiatorId) {
                    continue;
                }

                if (! $this->isUserOnCall($participantId)) {
                    try {
                        $user = User::findOrFail($participantId);

                        // Fetch personalized call payload (includes their unique LiveKit/Agora token)
                        $participantCallPayload = $this->callService->getCall($user, (int) $callId, true);

                        $personalizedCallData = array_merge($baseCallData, $participantCallPayload);

                        $this->sendCallRingingNotification($user, $personalizedCallData);
                    } catch (\Throwable $exception) {
                        Log::error('CallInitiatedListener: Exception sending notification', [
                            'participant_id' => $participantId,
                            'error' => $exception->getMessage(),
                        ]);
                        report($exception);
                    }
                } else {
                    Log::debug('CallInitiatedListener: User already on a call, skipping', [
                        'participant_id' => $participantId,
                    ]);
                }
            }
        } catch (\Throwable $exception) {
            Log::error('CallInitiatedListener: Fatal exception', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);
            report($exception);
        }
    }
}
