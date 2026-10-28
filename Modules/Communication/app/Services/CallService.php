<?php

declare(strict_types=1);

namespace Modules\Communication\Services;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\VideoGrant;
use Agence104\LiveKit\WebhookReceiver;
use App\Models\Event;
use App\Models\GiftWallet;
use App\Models\JobListing;
use App\Models\Product;
use App\Models\Profile;
use App\Models\Service;
use App\Models\User;
use App\Models\UserConnection;
use App\Models\WalletTransaction;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Events\CallInitiated;
use Modules\Communication\Events\CallLifecycleUpdated;
use Modules\Communication\Events\LivestreamEnded;
use Modules\Communication\Events\LivestreamGiftSent;
use Modules\Communication\Events\LivestreamHostEarningsUpdated;
use Modules\Communication\Events\LivestreamHostInvited;
use Modules\Communication\Events\LivestreamHostRevoked;
use Modules\Communication\Events\LivestreamPollUpdated;
use Modules\Communication\Events\LivestreamStarted;
use Modules\Communication\Events\LivestreamUpdated;
use Modules\Communication\Jobs\LivestreamHostGraceJob;
use Modules\Communication\Models\CallLog;
use Modules\Communication\Models\CallParticipant;
use Modules\Communication\Models\CommunicationLike;
use Modules\Communication\Models\Conversation;
use Modules\Communication\Models\ConversationParticipant;
use Modules\Communication\Models\Gift;
use Modules\Communication\Models\LivestreamAmountPreset;
use Modules\Communication\Models\LivestreamComment;
use Modules\Communication\Models\LivestreamFeaturedItem;
use Modules\Communication\Models\LivestreamGift;
use Modules\Communication\Models\LivestreamPoll;
use Modules\Communication\Models\LivestreamPollOption;
use Modules\Communication\Models\LivestreamPollVote;
use Modules\Communication\Repositories\Contracts\MessageRepositoryInterface;
use RuntimeException;
use Stripe\StripeClient;
use TaylanUnutmaz\AgoraTokenBuilder\RtcTokenBuilder;
use Throwable;

class CallService
{
    public function __construct(
        private readonly MessageRepositoryInterface $messages,
        private readonly CallMediaControlService $mediaControl,
        private readonly MessageService $messageService,
        private readonly AiQuestionService $aiQuestionService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function initiateCall(User $initiator, int $conversationId, string $callType): array
    {
        $conversation = Conversation::query()->findOrFail($conversationId);
        $this->activeConversationParticipant($conversationId, (int) $initiator->id);
        // $this->assertCallNotBlocked($initiator, $conversation);
        $this->closeTimedOutOngoingCallInConversation($conversationId);

        $ongoingCall = CallLog::query()
            ->where('conversation_id', $conversationId)
            ->where('status', 'ongoing')
            ->latest('id')
            ->first();

        if ($ongoingCall instanceof CallLog) {
            throw ValidationException::withMessages([
                'conversation_id' => ['An ongoing call already exists in this conversation. Join the existing call instead.'],
            ]);
        }

        $engine = $this->resolveEngine($conversation, $callType);

        $callLog = DB::transaction(function () use ($conversationId, $initiator, $callType, $engine): CallLog {
            $callLog = CallLog::query()->create([
                'conversation_id' => $conversationId,
                'initiator_id' => (int) $initiator->id,
                'ended_by_id' => null,
                'call_type' => $callType,
                'engine' => $engine,
                'room_name' => null,
                'status' => 'ongoing',
                'duration_seconds' => 0,
                'started_at' => now(),
                'ended_at' => null,
            ]);

            $callLog->room_name = $this->buildRoomName($conversationId, (int) $callLog->id);
            $callLog->save();

            $this->seedCallParticipants($callLog, (int) $initiator->id);

            return $callLog;
        });

        $callLog->loadMissing('conversation');

        $payload = $this->buildCallPayload($callLog, $initiator, true);

        // Create a system message to reflect the call initiation
        try {
            $this->messageService->createCallInitiatedMessage($initiator, $conversationId, [
                'call_id' => (int) $callLog->id,
                'call_type' => $callType,
                'started_at' => $callLog->started_at?->toIso8601String(),
            ]);
        } catch (Throwable) {
            // Log message creation failure but don't block the call initiation
        }

        // Build a token-free payload for broadcast so the receiver only gets a
        // notification. Each participant must call /join to receive their own
        // individually-scoped token (with their own identity).
        $broadcastPayload = $this->buildCallPayload($callLog, $initiator, false);

        $callback = function () use ($conversationId, $callLog, $broadcastPayload) {
            event(new CallInitiated(
                conversationId: (int) $conversationId,
                participantIds: $callLog->participants
                    ->pluck('user_id')
                    ->map(fn ($id) => (int) $id)
                    ->toArray(),
                payload: $broadcastPayload
            ));
        };

        if (app()->runningInConsole()) {
            $callback();
        } else {
            dispatch($callback)->afterResponse();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCall(User $viewer, int $callId, bool $includeToken = false): array
    {
        $callLog = $this->loadCallOrFail($callId);
        $this->activeConversationParticipant((int) $callLog->conversation_id, (int) $viewer->id);

        return $this->buildCallPayload($callLog, $viewer, $includeToken);
    }

    public function paginateCallHistory(User $viewer, int $perPage = 20): LengthAwarePaginator
    {
        $safePerPage = max(min($perPage, 100), 1);
        $viewerId = (int) $viewer->id;

        return CallLog::query()
            ->whereHas('participants', function ($query) use ($viewerId): void {
                $query->where('user_id', $viewerId);
            })
            ->with([
                'conversation',
                'initiator:id,first_name,last_name,avatar',
                'endedBy:id,first_name,last_name,avatar',
                'participants' => function ($query): void {
                    $query->with('user:id,first_name,last_name,avatar');
                },
            ])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate($safePerPage)
            ->through(fn (CallLog $callLog): array => $this->mapCallHistoryItem($callLog, $viewerId));
    }

    /**
     * @return array<string, mixed>
     */
    public function checkActiveCall(User $viewer, int $conversationId): array
    {
        Conversation::query()->findOrFail($conversationId);
        $this->activeConversationParticipant($conversationId, (int) $viewer->id);
        $this->closeTimedOutOngoingCallInConversation($conversationId);

        $callLog = CallLog::query()
            ->where('conversation_id', $conversationId)
            ->where('status', 'ongoing')
            ->latest('id')
            ->first();

        if (! $callLog instanceof CallLog) {
            return [
                'has_active_call' => false,
                'call' => null,
            ];
        }

        return [
            'has_active_call' => true,
            'call' => $this->buildCallPayload($callLog, $viewer, false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function joinCall(User $participant, int $callId): array
    {
        $callLog = $this->closeTimedOutOngoingCall($this->loadCallOrFail($callId));

        if ((string) $callLog->status !== 'ongoing') {
            throw ValidationException::withMessages([
                'call' => ['This call has already ended.'],
            ]);
        }

        $activeParticipant = $this->activeConversationParticipant((int) $callLog->conversation_id, (int) $participant->id);
        $this->assertCallNotBlocked($participant, $callLog->conversation);

        $callParticipant = CallParticipant::query()->firstOrNew([
            'call_log_id' => (int) $callLog->id,
            'user_id' => (int) $participant->id,
        ]);

        if ($callParticipant->exists && (string) $callParticipant->status === 'kicked') {
            throw new AuthorizationException('You were removed from this call by a group admin.');
        }

        $isNewJoin = (string) $callParticipant->status !== 'joined';

        if ($isNewJoin) {
            $callParticipant->status = 'joined';
            $callParticipant->joined_at = $callParticipant->joined_at ?? now();
            $callParticipant->left_at = null;
            $callParticipant->kicked_by_id = null;
            $callParticipant->save();

            $this->broadcastLifecycle(
                'CallJoined',
                $callLog,
                [
                    'action' => 'joined',
                    'participant_id' => (int) $participant->id,
                    'participant_name' => $this->displayName($participant),
                    'call' => $this->buildCallPayload($callLog, $participant, false),
                ]
            );
        }

        return $this->buildCallPayload($callLog, $participant, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function leaveCall(User $participant, int $callId): array
    {
        $callLog = $this->loadCallOrFail($callId);
        $this->activeConversationParticipant((int) $callLog->conversation_id, (int) $participant->id);

        if ((string) $callLog->status !== 'ongoing') {
            return $this->buildCallPayload($callLog, $participant, false);
        }

        $callParticipant = CallParticipant::query()
            ->with('user')
            ->where('call_log_id', (int) $callLog->id)
            ->where('user_id', (int) $participant->id)
            ->first();

        if (! $callParticipant instanceof CallParticipant) {
            throw ValidationException::withMessages([
                'call' => ['You are not part of this call.'],
            ]);
        }

        if (in_array((string) $callParticipant->status, ['left', 'kicked', 'ended'], true)) {
            return $this->buildCallPayload($callLog, $participant, false);
        }

        $callParticipant->status = 'left';
        $callParticipant->left_at = now();
        $callParticipant->save();

        $this->broadcastLifecycle(
            'CallLeft',
            $callLog,
            [
                'action' => 'left',
                'participant_id' => (int) $participant->id,
                'participant_name' => $this->displayName($participant),
                'call' => $this->buildCallPayload($callLog, $participant, false),
            ]
        );

        if ($this->joinedParticipantCount((int) $callLog->id) === 0) {
            $mediaControlResult = $this->mediaControl->endCallRoom(
                $callLog,
                $this->joinedParticipantIds((int) $callLog->id)
            );

            $callLog = $this->finalizeCall($callLog, 'completed', null);

            // Create a system message to reflect the call ended due to no active participants
            try {
                $initiator = $callLog->initiator;
                $initiatorName = $initiator ? trim((string) (($initiator->first_name ?? '').' '.($initiator->last_name ?? ''))) : 'User';
                if ($initiatorName === '') {
                    $initiatorName = 'User '.(int) ($callLog->initiator_id ?? 'Unknown');
                }

                // Use the participant who just left as the reference
                $this->messageService->createCallEndedMessage($participant, (int) $callLog->conversation_id, [
                    'call_id' => (int) $callLog->id,
                    'status' => 'completed',
                    'duration_seconds' => (int) $callLog->duration_seconds,
                    'call_type' => (string) $callLog->call_type,
                    'had_other_participants' => $this->callHadOtherParticipants($callLog),
                    'initiator_id' => (int) ($callLog->initiator_id ?? 0),
                    'initiator_name' => $initiatorName,
                    'started_at' => $callLog->started_at?->toIso8601String(),
                    'ended_at' => $callLog->ended_at?->toIso8601String(),
                ]);
            } catch (Throwable) {
                // Log message creation failure but don't block the call end operation
            }

            $this->broadcastLifecycle(
                'CallEnded',
                $callLog,
                [
                    'action' => 'ended',
                    'ended_by_id' => (int) $participant->id,
                    'ended_by_name' => $this->displayName($participant),
                    'reason' => 'no_active_participants',
                    'media_control' => $mediaControlResult,
                    'call' => $this->buildCallPayload($callLog, $participant, false),
                ]
            );
        }

        return $this->buildCallPayload($callLog, $participant, false);
    }

    /**
     * @return array<string, mixed>
     */
    public function kickParticipant(User $actor, int $callId, int $targetUserId): array
    {
        $callLog = $this->loadCallOrFail($callId);

        if ((string) $callLog->status !== 'ongoing') {
            throw ValidationException::withMessages([
                'call' => ['This call has already ended.'],
            ]);
        }

        $conversation = $callLog->conversation;
        if (! $conversation instanceof Conversation) {
            throw new ModelNotFoundException('Conversation not found for this call.');
        }

        if (! (bool) $conversation->is_group) {
            throw ValidationException::withMessages([
                'call' => ['Participant removal is only available for group calls.'],
            ]);
        }

        $actorParticipant = $this->activeConversationParticipant((int) $conversation->id, (int) $actor->id);

        $isAdmin = (string) $actorParticipant->role === 'admin';
        $isInitiator = (int) $callLog->initiator_id === (int) $actor->id;

        if (! $isAdmin && ! $isInitiator) {
            throw new AuthorizationException('Only group admins or call initiator can remove participants from an active call.');
        }

        if ((int) $actor->id === $targetUserId) {
            throw ValidationException::withMessages([
                'participant_id' => ['Use leave-call endpoint to leave your own call.'],
            ]);
        }

        $targetConversationParticipant = $this->activeConversationParticipant((int) $conversation->id, $targetUserId);
        if ((string) $targetConversationParticipant->role === 'admin') {
            throw new AuthorizationException('Admin participants cannot be removed from the call.');
        }

        $targetCallParticipant = CallParticipant::query()->firstOrNew([
            'call_log_id' => (int) $callLog->id,
            'user_id' => $targetUserId,
        ]);

        $targetCallParticipant->status = 'kicked';
        $targetCallParticipant->left_at = now();
        $targetCallParticipant->kicked_by_id = (int) $actor->id;
        $targetCallParticipant->save();

        $mediaControlResult = $this->mediaControl->forceRemoveParticipant($callLog, $targetUserId);

        $this->broadcastLifecycle(
            'call.kicked',
            $callLog,
            [
                'action' => 'kicked',
                'participant_id' => $targetUserId,
                'kicked_by_id' => (int) $actor->id,
                'media_control' => $mediaControlResult,
                'call' => $this->buildCallPayload($callLog, $actor, false),
            ]
        );

        return [
            'call' => $this->buildCallPayload($callLog, $actor, false),
            'kicked_participant_id' => $targetUserId,
            'kicked_by_id' => (int) $actor->id,
            'media_control' => $mediaControlResult,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function muteParticipant(User $actor, int $callId, int $targetUserId, string $mediaKind = 'audio', bool $muted = true): array
    {
        $callLog = $this->loadCallOrFail($callId);

        if ((string) $callLog->status !== 'ongoing') {
            throw ValidationException::withMessages([
                'call' => ['This call has already ended.'],
            ]);
        }

        $conversation = $callLog->conversation;
        if (! $conversation instanceof Conversation) {
            throw new ModelNotFoundException('Conversation not found for this call.');
        }

        if (! (bool) $conversation->is_group) {
            throw ValidationException::withMessages([
                'call' => ['Admin media control is only available for group calls.'],
            ]);
        }

        $actorParticipant = $this->activeConversationParticipant((int) $conversation->id, (int) $actor->id);
        $targetParticipant = $this->activeConversationParticipant((int) $conversation->id, $targetUserId);

        $isAdmin = (string) $actorParticipant->role === 'admin';
        $isInitiator = (int) $callLog->initiator_id === (int) $actor->id;

        if (! $isAdmin && ! $isInitiator) {
            throw new AuthorizationException('Only group admins or call initiator can update participant media state.');
        }

        if ((int) $actor->id === $targetUserId) {
            throw ValidationException::withMessages([
                'participant_id' => ['You cannot change your own media state from admin control endpoint.'],
            ]);
        }

        if ((string) $targetParticipant->role === 'admin' && ! $isInitiator) {
            throw new AuthorizationException('Only call initiator can change media state of another admin.');
        }

        $resolvedMediaKind = in_array($mediaKind, ['audio', 'video', 'both'], true) ? $mediaKind : 'audio';

        $mediaControlResult = $this->mediaControl->setParticipantMediaMuted(
            $callLog,
            $targetUserId,
            $resolvedMediaKind,
            $muted
        );

        $payload = [
            'action' => 'media_updated',
            'participant_id' => $targetUserId,
            'updated_by_id' => (int) $actor->id,
            'media_kind' => $resolvedMediaKind,
            'muted' => $muted,
            'media_control' => $mediaControlResult,
            'call' => $this->buildCallPayload($callLog, $actor, false),
        ];

        $this->broadcastLifecycle('call.media_updated', $callLog, $payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public function handleLiveKitWebhook(array $payload, array $headers = [], ?string $rawBody = null): array
    {
        $authHeader = $this->firstHeaderValue($headers, 'Authorization');
        $apiKey = trim((string) config('communication.livekit.api_key', ''));
        $apiSecret = trim((string) config('communication.livekit.api_secret', ''));

        // If standard Authorization header is provided and SDK keys are set, verify signature
        if ($authHeader !== null && $rawBody !== null && $apiKey !== '' && $apiSecret !== '') {
            try {
                $receiver = new WebhookReceiver($apiKey, $apiSecret);
                $receiver->receive($rawBody, $authHeader);
            } catch (Throwable $e) {
                Log::warning('LiveKit webhook signature verification failed: '.$e->getMessage());

                return [
                    'processed' => false,
                    'ignored' => true,
                    'reason' => 'invalid_webhook_signature',
                ];
            }
        } else {
            // Fallback to custom x-livekit-webhook-token
            $expectedToken = trim((string) config('communication.livekit.webhook_token', ''));
            if ($expectedToken !== '') {
                $actualToken = trim((string) ($this->firstHeaderValue($headers, 'x-livekit-webhook-token') ?? ''));

                if ($actualToken === '' || ! hash_equals($expectedToken, $actualToken)) {
                    return [
                        'processed' => false,
                        'ignored' => true,
                        'reason' => 'invalid_webhook_token',
                    ];
                }
            }
        }

        $eventType = strtolower(trim((string) (
            data_get($payload, 'event')
            ?? data_get($payload, 'type')
            ?? data_get($payload, 'event_type')
            ?? ''
        )));

        $roomName = trim((string) (
            data_get($payload, 'room.name')
            ?? data_get($payload, 'room_name')
            ?? data_get($payload, 'room')
            ?? ''
        ));

        if ($roomName === '') {
            return [
                'processed' => false,
                'ignored' => true,
                'reason' => 'room_name_missing',
            ];
        }

        $callLog = CallLog::query()
            ->with('conversation')
            ->where('room_name', $roomName)
            ->where(function ($q) {
                $q->where('status', 'ongoing')
                    ->orWhere('is_live', true);
            })
            ->latest('id')
            ->first();

        if (! $callLog instanceof CallLog) {
            return [
                'processed' => true,
                'ignored' => true,
                'reason' => 'no_ongoing_call_found',
                'room_name' => $roomName,
            ];
        }

        $participantCount = (int) (
            data_get($payload, 'room.num_participants')
            ?? data_get($payload, 'num_participants')
            ?? -1
        );

        $roomClosedEvents = [
            'room_finished',
            'room_deleted',
            'room_ended',
            'room_closed',
            'roomfinished',
        ];

        $leaveEvents = [
            'participant_left',
            'participant_disconnected',
            'participant_left_room',
        ];

        // On a livestream, the publisher (host) leaving makes the stream dead even
        // if viewers remain. LiveKit identity == user id, so we can detect host drop.
        $participantIdentity = (string) (
            data_get($payload, 'participant.identity')
            ?? data_get($payload, 'participant_identity')
            ?? ''
        );
        $hostLeft = (string) $callLog->call_type === 'stream'
            && in_array($eventType, $leaveEvents, true)
            && $participantIdentity !== ''
            && (int) $participantIdentity === (int) $callLog->initiator_id;

        $shouldClose = in_array($eventType, $roomClosedEvents, true)
            || (in_array($eventType, $leaveEvents, true) && $participantCount === 0)
            || ($eventType === '' && $participantCount === 0)
            || $hostLeft;

        // Stream: a VIEWER (not host) disconnected via webhook (app crash / offline).
        // Keep the stream alive but fix the cached viewer count + "X watching" ticker.
        if (
            (string) $callLog->call_type === 'stream'
            && in_array($eventType, $leaveEvents, true)
            && ! $hostLeft
            && $participantIdentity !== ''
        ) {
            $isCoHost = CallParticipant::query()
                ->where('call_log_id', (int) $callLog->id)
                ->where('user_id', (int) $participantIdentity)
                ->where('role', 'co_host')
                ->exists();

            if (! $isCoHost && $callLog->viewer_count > 0) {
                $callLog->decrement('viewer_count');
            }
            $this->broadcastStreamUpdated($callLog, (int) $participantIdentity, 'left');

            return [
                'processed' => true,
                'ignored' => true,
                'reason' => 'stream_viewer_disconnected',
                'event_type' => $eventType,
                'room_name' => $roomName,
            ];
        }

        $hostJoined = (string) $callLog->call_type === 'stream'
            && $participantIdentity !== ''
            && (int) $participantIdentity === (int) $callLog->initiator_id;

        // Stream: auto-revoke unauthorized publishers (e.g. viewer or revoked co-host trying to publish or join with publish permissions)
        if (
            (string) $callLog->call_type === 'stream'
            && in_array($eventType, ['track_published', 'trackpublished', 'participant_joined'], true)
            && $participantIdentity !== ''
        ) {
            $userId = (int) $participantIdentity;
            $isHost = $userId === (int) $callLog->initiator_id;
            $isCoHost = CallParticipant::query()
                ->where('call_log_id', (int) $callLog->id)
                ->where('user_id', $userId)
                ->where('role', 'co_host')
                ->where('status', 'joined')
                ->exists();

            $canPublish = (bool) (data_get($payload, 'participant.permission.canPublish') ?? data_get($payload, 'participant.permission.can_publish') ?? false);

            if (! $isHost && ! $isCoHost && ($canPublish || in_array($eventType, ['track_published', 'trackpublished'], true))) {
                Log::warning("LiveKit webhook: Unauthorized publish detected for user {$userId} in stream {$callLog->id} on {$eventType}. Auto-revoking on LiveKit...", [
                    'room_name' => $roomName,
                    'is_host' => $isHost,
                    'is_co_host' => $isCoHost,
                    'can_publish' => $canPublish,
                ]);
                $mediaControlResult = $this->mediaControl->revokeCoHostPublish($callLog, $userId);

                return [
                    'processed' => true,
                    'ignored' => false,
                    'action' => 'unauthorized_publisher_revoked',
                    'user_id' => $userId,
                    'media_control' => $mediaControlResult,
                    'room_name' => $roomName,
                ];
            }
        }

        if (! $shouldClose) {
            if (in_array($eventType, ['participant_joined', 'room_started'], true)) {
                if ($hostJoined) {
                    Cache::forget(
                        LivestreamHostGraceJob::CACHE_KEY.(int) $callLog->id
                    );
                    if (! $callLog->is_live) {
                        $callLog->update(['is_live' => true]);
                    }
                }
            }

            return [
                'processed' => true,
                'ignored' => true,
                'reason' => 'event_does_not_require_close',
                'event_type' => $eventType,
                'room_name' => $roomName,
            ];
        }

        // Livestream + HOST dropped: do NOT end instantly. Wait a grace period so the
        // host can reconnect (network blip) without killing the stream.
        if ((string) $callLog->call_type === 'stream' && $hostLeft) {
            $graceSeconds = (int) config('communication.livestream_host_grace_seconds', 20);

            Cache::put(
                LivestreamHostGraceJob::CACHE_KEY.(int) $callLog->id,
                true,
                now()->addSeconds($graceSeconds)
            );

            LivestreamHostGraceJob::dispatch(
                (int) $callLog->id,
                (int) $callLog->initiator_id
            )->delay(now()->addSeconds($graceSeconds));

            return [
                'processed' => true,
                'ignored' => false,
                'grace_started' => true,
                'reason' => 'livestream_host_grace_window',
                'room_name' => $roomName,
            ];
        }

        $mediaControlResult = $this->mediaControl->endCallRoom(
            $callLog,
            $this->joinedParticipantIds((int) $callLog->id)
        );

        $callLog = $this->finalizeCall($callLog, 'completed', null);

        // Create a system message to reflect the call ended via webhook
        try {
            $initiator = $callLog->initiator;
            $initiatorName = $initiator ? trim((string) (($initiator->first_name ?? '').' '.($initiator->last_name ?? ''))) : 'User';
            if ($initiatorName === '') {
                $initiatorName = 'User '.(int) ($callLog->initiator_id ?? 'Unknown');
            }

            // Use a system user representation for webhook-initiated terminations
            $systemUser = User::query()->find((int) ($callLog->initiator_id ?? 0));
            if ($systemUser instanceof User) {
                $this->messageService->createCallEndedMessage($systemUser, (int) $callLog->conversation_id, [
                    'call_id' => (int) $callLog->id,
                    'status' => 'completed',
                    'duration_seconds' => (int) $callLog->duration_seconds,
                    'call_type' => (string) $callLog->call_type,
                    'had_other_participants' => $this->callHadOtherParticipants($callLog),
                    'initiator_id' => (int) ($callLog->initiator_id ?? 0),
                    'initiator_name' => $initiatorName,
                    'started_at' => $callLog->started_at?->toIso8601String(),
                    'ended_at' => $callLog->ended_at?->toIso8601String(),
                ]);
            }
        } catch (Throwable) {
            // Log message creation failure but don't block the webhook processing
        }

        if ((string) $callLog->call_type === 'stream') {
            // Livestream ended via webhook (host dropped / room finished):
            // reset live flags + notify followers on user.{id} with LivestreamEnded.
            $callLog->is_live = false;
            $callLog->viewer_count = 0;
            $callLog->save();

            $host = $callLog->initiator;
            $followerIds = $host instanceof User ? $this->followerIds((int) $host->id) : [];

            event(new LivestreamEnded(
                streamId: (int) $callLog->id,
                followerIds: $followerIds,
                payload: $this->buildStreamPayload($callLog, $host ?? $callLog->initiator, null),
            ));
        } else {
            $this->broadcastLifecycle(
                'CallEnded',
                $callLog,
                [
                    'action' => 'ended',
                    'ended_by_id' => null,
                    'reason' => 'livekit_webhook_room_empty',
                    'event_type' => $eventType,
                    'media_control' => $mediaControlResult,
                ]
            );
        }

        return [
            'processed' => true,
            'ignored' => false,
            'closed' => true,
            'call_id' => (int) $callLog->id,
            'room_name' => $roomName,
            'event_type' => $eventType,
            'media_control' => $mediaControlResult,
        ];
    }

    /**
     * Handle Agora webhook for call lifecycle management.
     *
     * @return array<string, mixed>
     */
    public function handleAgoraWebhook(array $payload, array $headers = []): array
    {
        $expectedSecret = trim((string) config('communication.agora.webhook_secret', ''));
        if ($expectedSecret !== '') {
            $signature = trim((string) (
                $this->firstHeaderValue($headers, 'x-agora-signature')
                ?? $this->firstHeaderValue($headers, 'agora-signature')
                ?? $this->firstHeaderValue($headers, 'agora-signature-v2')
                ?? ''
            ));

            if ($signature === '' || ! $this->validateAgoraSignature($payload, $signature, $expectedSecret)) {
                return [
                    'processed' => false,
                    'ignored' => true,
                    'reason' => 'invalid_webhook_signature',
                ];
            }
        }

        // Extract event type (Agora sends as numeric or string)
        $eventType = strtolower(trim((string) (
            data_get($payload, 'eventType')
            ?? data_get($payload, 'type')
            ?? data_get($payload, 'event_type')
            ?? ''
        )));

        // Extract channel name (room identifier for Agora)
        $channelName = trim((string) (
            data_get($payload, 'channelName')
            ?? data_get($payload, 'channel_name')
            ?? data_get($payload, 'channel')
            ?? ''
        ));

        if ($channelName === '') {
            return [
                'processed' => false,
                'ignored' => true,
                'reason' => 'channel_name_missing',
            ];
        }

        $callLog = CallLog::query()
            ->with('conversation')
            ->where('room_name', $channelName)
            ->where('status', 'ongoing')
            ->latest('id')
            ->first();

        if (! $callLog instanceof CallLog) {
            return [
                'processed' => true,
                'ignored' => true,
                'reason' => 'no_ongoing_call_found',
                'channel_name' => $channelName,
            ];
        }

        // Agora channel destruction events
        $channelClosedEvents = [
            'channel_destroy',
            'channel_destroyed',
            'channel_closed',
            'channel_ended',
        ];

        // For Agora, we typically close on channel destruction
        // Agora doesn't provide active participant count in webhooks like LiveKit
        // So we primarily rely on channel_destroy events
        $shouldClose = in_array($eventType, $channelClosedEvents, true);

        if (! $shouldClose) {
            return [
                'processed' => true,
                'ignored' => true,
                'reason' => 'event_does_not_require_close',
                'event_type' => $eventType,
                'channel_name' => $channelName,
            ];
        }

        // Close the call and clean up media control
        // Livestream + HOST dropped: do NOT end instantly. Wait a grace period so the
        // host can reconnect (network blip) without killing the stream.
        if ((string) $callLog->call_type === 'stream' && $hostLeft) {
            $graceSeconds = (int) config('communication.livestream_host_grace_seconds', 20);

            Cache::put(
                LivestreamHostGraceJob::CACHE_KEY.(int) $callLog->id,
                true,
                now()->addSeconds($graceSeconds)
            );

            LivestreamHostGraceJob::dispatch(
                (int) $callLog->id,
                (int) $callLog->initiator_id
            )->delay(now()->addSeconds($graceSeconds));

            return [
                'processed' => true,
                'ignored' => false,
                'grace_started' => true,
                'reason' => 'livestream_host_grace_window',
                'room_name' => $roomName,
            ];
        }

        $mediaControlResult = $this->mediaControl->endCallRoom(
            $callLog,
            $this->joinedParticipantIds((int) $callLog->id)
        );

        $callLog = $this->finalizeCall($callLog, 'completed', null);

        // Create a system message to reflect the call ended via Agora webhook
        try {
            $initiator = $callLog->initiator;
            $initiatorName = $initiator ? trim((string) (($initiator->first_name ?? '').' '.($initiator->last_name ?? ''))) : 'User';
            if ($initiatorName === '') {
                $initiatorName = 'User '.(int) ($callLog->initiator_id ?? 'Unknown');
            }

            // Use a system user representation for webhook-initiated terminations
            $systemUser = User::query()->find((int) ($callLog->initiator_id ?? 0));
            if ($systemUser instanceof User) {
                $this->messageService->createCallEndedMessage($systemUser, (int) $callLog->conversation_id, [
                    'call_id' => (int) $callLog->id,
                    'status' => 'completed',
                    'duration_seconds' => (int) $callLog->duration_seconds,
                    'call_type' => (string) $callLog->call_type,
                    'had_other_participants' => $this->callHadOtherParticipants($callLog),
                    'initiator_id' => (int) ($callLog->initiator_id ?? 0),
                    'initiator_name' => $initiatorName,
                    'started_at' => $callLog->started_at?->toIso8601String(),
                    'ended_at' => $callLog->ended_at?->toIso8601String(),
                ]);
            }
        } catch (Throwable) {
            // Log message creation failure but don't block the webhook processing
        }

        $this->broadcastLifecycle(
            'CallEnded',
            $callLog,
            [
                'action' => 'ended',
                'ended_by_id' => null,
                'reason' => 'agora_webhook_channel_destroyed',
                'event_type' => $eventType,
                'media_control' => $mediaControlResult,
            ]
        );

        return [
            'processed' => true,
            'ignored' => false,
            'closed' => true,
            'call_id' => (int) $callLog->id,
            'channel_name' => $channelName,
            'event_type' => $eventType,
            'media_control' => $mediaControlResult,
        ];
    }

    /**
     * Validate Agora webhook signature using HMAC-SHA256.
     */
    private function validateAgoraSignature(array $payload, string $signature, string $secret): bool
    {
        try {
            // Agora uses HMAC-SHA256 for signature validation
            // Signature is computed over the entire JSON payload
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return false;
            }

            $expectedSignature = base64_encode(hash_hmac('sha256', $json, $secret, true));

            return hash_equals($expectedSignature, $signature);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function endCall(User $actor, int $callId, mixed $status = null): array
    {
        $callLog = $this->loadCallOrFail($callId);

        if ((string) $callLog->status !== 'ongoing') {
            return $this->buildCallPayload($callLog, $actor, false);
        }

        $actorParticipant = $this->activeConversationParticipant((int) $callLog->conversation_id, (int) $actor->id);

        $conversation = $callLog->conversation;
        if ($conversation instanceof Conversation && (bool) $conversation->is_group) {
            $isAdmin = (string) $actorParticipant->role === 'admin';
            $isInitiator = (int) $callLog->initiator_id === (int) $actor->id;

            if (! $isAdmin && ! $isInitiator) {
                throw new AuthorizationException('Only group admins or call initiator can end this call.');
            }
        }

        // if there is at least two joined participants, then call is completed, otherwise it's missed

        $resolvedStatus = is_string($status) && in_array($status, ['completed', 'missed'], true)
            ? $status
            : ($this->callHadOtherParticipants($callLog) ? 'completed' : 'missed');

        $mediaControlResult = $this->mediaControl->endCallRoom(
            $callLog,
            $this->joinedParticipantIds((int) $callLog->id)
        );

        $callLog = $this->finalizeCall($callLog, $resolvedStatus, (int) $actor->id);

        // Create a system message to reflect the call completion
        try {
            // Get the initiator name
            $initiator = $callLog->initiator;
            $initiatorName = $initiator ? trim((string) (($initiator->first_name ?? '').' '.($initiator->last_name ?? ''))) : 'User';
            if ($initiatorName === '') {
                $initiatorName = 'User '.(int) ($callLog->initiator_id ?? 'Unknown');
            }

            $this->messageService->createCallEndedMessage($actor, (int) $callLog->conversation_id, [
                'call_id' => (int) $callLog->id,
                'status' => $resolvedStatus,
                'duration_seconds' => (int) $callLog->duration_seconds,
                'call_type' => (string) $callLog->call_type,
                'had_other_participants' => $this->callHadOtherParticipants($callLog),
                'initiator_id' => (int) ($callLog->initiator_id ?? 0),
                'initiator_name' => $initiatorName,
                'started_at' => $callLog->started_at?->toIso8601String(),
                'ended_at' => $callLog->ended_at?->toIso8601String(),
            ]);
        } catch (Throwable) {
            // Log message creation failure but don't block the call end operation
        }

        $this->broadcastLifecycle(
            'CallEnded',
            $callLog,
            [
                'action' => 'ended',
                'ended_by_id' => (int) $actor->id,
                'media_control' => $mediaControlResult,
                'call' => $this->buildCallPayload($callLog, $actor, false),
            ]
        );

        $payload = $this->buildCallPayload($callLog, $actor, false);
        $payload['media_control'] = $mediaControlResult;

        return $payload;
    }

    private function loadCallOrFail(int $callId): CallLog
    {
        return CallLog::query()
            ->with('conversation')
            ->findOrFail($callId);
    }

    private function activeConversationParticipant(int $conversationId, int $userId): ConversationParticipant
    {
        $participant = ConversationParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->first();

        if (! $participant instanceof ConversationParticipant) {
            throw new AuthorizationException('Conversation not found.');
        }

        return $participant;
    }

    private function assertCallNotBlocked(User $participant, Conversation $conversation): void
    {
        if ((bool) $conversation->is_group) {
            return;
        }

        $otherParticipant = ConversationParticipant::query()
            ->where('conversation_id', (int) $conversation->id)
            ->where('status', 'active')
            ->where('user_id', '!=', (int) $participant->id)
            ->with('user')
            ->first();

        if (! $otherParticipant instanceof ConversationParticipant || ! $otherParticipant->user instanceof User) {
            return;
        }

        if ($participant->hasBlocked((int) $otherParticipant->user_id)) {
            throw new AuthorizationException('You have blocked the other participant.');
        }

        if ($otherParticipant->user->hasBlocked((int) $participant->id)) {
            throw new AuthorizationException('The other participant has blocked you.');
        }
    }

    private function resolveEngine(Conversation $conversation, string $callType): string
    {
        $scaleType = (bool) $conversation->is_group ? 'group' : 'one_to_one';
        $configKey = "communication.call_engine.{$callType}_{$scaleType}";
        $engine = (string) config($configKey, 'livekit');

        if ($engine === 'none') {
            throw new AuthorizationException('Calling feature is currently disabled for this type.');
        }

        return $engine;
    }

    private function buildRoomName(int $conversationId, int $callId): string
    {
        return 'conv_'.$conversationId.'_call_'.$callId;
    }

    // ===================================================================
    // LIVESTREAM methods (call_type = 'stream')
    // Host = LiveKit publisher, viewers = subscribe-only.
    // ===================================================================

    /**
     * Host starts a livestream.
     *
     * @return array<string, mixed>
     */
    public function startStream(
        User $host,
        ?string $title = null,
        ?string $description = null,
        ?string $thumbnailUrl = null,
        ?string $backgroundImageUrl = null,
        ?string $streamLayout = 'solo',
        bool $allowGiftBet = false,
        ?array $customQuestions = null,
        ?int $conversationId = null
    ): array {
        $streamTitle = $title ?: 'Livestream';

        if ($conversationId !== null && $conversationId > 0) {
            $conversation = Conversation::query()->findOrFail($conversationId);
            $this->activeConversationParticipant($conversationId, (int) $host->id);
        } else {
            // Auto-create a conversation in the background for this livestream
            $conversation = Conversation::query()->create([
                'is_group' => true,
                'name' => 'Livestream: '.$streamTitle,
                'avatar' => $thumbnailUrl,
            ]);

            ConversationParticipant::query()->create([
                'conversation_id' => (int) $conversation->id,
                'user_id' => (int) $host->id,
                'role' => 'admin',
                'status' => 'active',
                'joined_at' => now(),
            ]);

            $conversationId = (int) $conversation->id;
        }

        $callLog = DB::transaction(function () use ($conversationId, $host, $title, $description, $thumbnailUrl, $backgroundImageUrl, $streamLayout, $allowGiftBet, $customQuestions): CallLog {
            $callLog = CallLog::query()->create([
                'conversation_id' => $conversationId,
                'initiator_id' => (int) $host->id,
                'ended_by_id' => null,
                'call_type' => 'stream',
                'engine' => 'livekit',
                'room_name' => null,
                'title' => $title,
                'description' => $description,
                'thumbnail_url' => $thumbnailUrl,
                'background_image_url' => $backgroundImageUrl,
                'stream_layout' => $streamLayout ?? 'solo',
                'allow_gift_bet' => $allowGiftBet,
                'custom_questions' => is_array($customQuestions) ? array_values($customQuestions) : null,
                'is_live' => false,
                'viewer_count' => 0,
                'status' => 'ongoing',
                'duration_seconds' => 0,
                'started_at' => now(),
                'ended_at' => null,
            ]);

            $callLog->room_name = $this->buildStreamRoomName($conversationId, (int) $callLog->id);
            $callLog->save();

            $this->seedCallParticipants($callLog, (int) $host->id);

            return $callLog;
        });

        $callLog->loadMissing('conversation');

        $token = $this->generateLiveKitToken($host, (string) $callLog->room_name, 'host');

        $payload = $this->buildStreamPayload($callLog, $host, $token);
        $broadcastPayload = $this->buildStreamPayload($callLog, $host, null);

        // Notify followers via user.{id} + public livestreams channel + call.{conversationId}.
        $followerIds = $this->followerIds((int) $host->id);
        event(new LivestreamStarted(
            streamId: (int) $callLog->id,
            followerIds: $followerIds,
            payload: $broadcastPayload,
            conversationId: (int) $callLog->conversation_id,
        ));

        return $payload;
    }

    /**
     * Viewer joins a live stream (subscribe-only token).
     *
     * @return array<string, mixed>
     */
    public function joinStream(User $viewer, int $streamId): array
    {
        $callLog = $this->loadCallOrFail($streamId);

        if ((string) $callLog->call_type !== 'stream' || (string) $callLog->status !== 'ongoing') {
            throw ValidationException::withMessages([
                'stream' => ['This stream is not live.'],
            ]);
        }

        $this->assertCallNotBlocked($viewer, $callLog->conversation);

        $callParticipant = CallParticipant::query()->firstOrNew([
            'call_log_id' => (int) $callLog->id,
            'user_id' => (int) $viewer->id,
        ]);

        $isNewJoin = (string) $callParticipant->status !== 'joined';

        if ($isNewJoin) {
            $callParticipant->status = 'joined';
            $callParticipant->joined_at = $callParticipant->joined_at ?? now();
            $callParticipant->left_at = null;
            $callParticipant->save();

            // Increment cached viewer count + broadcast the "X watching" ticker.
            $callLog->increment('viewer_count');
            $this->broadcastStreamUpdated($callLog, (int) $viewer->id, 'joined');
        }

        // Host rejoined during the grace window -> cancel the pending forced end
        // and keep the stream live (network blip recovery). Host must get a PUBLISH
        // token (not viewer) so they can resume streaming.
        $isHost = (int) $viewer->id === (int) $callLog->initiator_id;
        $isCoHost = $callParticipant->exists && $callParticipant->role === 'co_host';

        if ($isHost) {
            // We intentionally do NOT clear the grace cache key here.
            // The grace period should only be cancelled when the host ACTUALLY connects to LiveKit
            // (handled by the 'participant_joined' webhook).
        }

        $token = $this->generateLiveKitToken(
            $viewer,
            (string) $callLog->room_name,
            ($isHost || $isCoHost) ? 'host' : 'viewer'
        );

        return $this->buildStreamPayload($callLog, $viewer, $token);
    }

    /**
     * Update livestream settings (title, description, stream_layout, etc.).
     * Only host can update.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateStream(User $host, int $streamId, array $data): array
    {
        $callLog = $this->loadCallOrFail($streamId);

        if ((string) $callLog->call_type !== 'stream') {
            throw ValidationException::withMessages([
                'stream' => ['Not a livestream.'],
            ]);
        }

        if ((int) $callLog->initiator_id !== (int) $host->id) {
            throw new AuthorizationException('Only the host can update this livestream.');
        }

        if ((string) $callLog->status !== 'ongoing' || ! $callLog->is_live) {
            throw ValidationException::withMessages([
                'stream' => ['Livestream is not currently active.'],
            ]);
        }

        $fillableData = array_filter([
            'title' => isset($data['title']) ? (string) $data['title'] : null,
            'description' => isset($data['description']) ? (string) $data['description'] : null,
            'stream_layout' => isset($data['stream_layout']) ? (string) $data['stream_layout'] : null,
            'allow_gift_bet' => isset($data['allow_gift_bet']) ? (bool) $data['allow_gift_bet'] : null,
            'custom_questions' => isset($data['custom_questions']) && is_array($data['custom_questions']) ? array_values($data['custom_questions']) : null,
            'video_url' => isset($data['video_url']) ? (string) $data['video_url'] : null,
        ], fn ($val) => $val !== null);

        if (! empty($fillableData)) {
            $callLog->update($fillableData);
            $callLog->refresh();
        }

        $payload = $this->buildStreamPayload($callLog, $host, null);

        $this->broadcastStreamUpdated($callLog, (int) $host->id, 'updated');

        return $payload;
    }

    /**
     * Viewer or host leaves the stream.
     *
     * @return array<string, mixed>
     */
    public function leaveStream(User $participant, int $streamId): array
    {
        $callLog = $this->loadCallOrFail($streamId);

        if ((string) $callLog->call_type !== 'stream') {
            throw ValidationException::withMessages([
                'stream' => ['Not a livestream.'],
            ]);
        }

        $callParticipant = CallParticipant::query()
            ->where('call_log_id', (int) $callLog->id)
            ->where('user_id', (int) $participant->id)
            ->first();

        if (
            $callParticipant instanceof CallParticipant
            && (string) $callParticipant->status === 'joined'
        ) {
            $callParticipant->status = 'left';
            $callParticipant->left_at = now();
            $callParticipant->save();

            if ($callLog->viewer_count > 0) {
                $callLog->decrement('viewer_count');
            }
            $this->broadcastStreamUpdated($callLog, (int) $participant->id, 'left');
        }

        return $this->buildStreamPayload($callLog, $participant, null);
    }

    /**
     * Host ends the stream.
     *
     * @return array<string, mixed>
     */
    public function endStream(User $host, int $streamId): array
    {
        $callLog = $this->loadCallOrFail($streamId);

        if ((string) $callLog->call_type !== 'stream') {
            throw ValidationException::withMessages([
                'stream' => ['Not a livestream.'],
            ]);
        }

        if ((int) $callLog->initiator_id !== (int) $host->id) {
            throw new AuthorizationException('Only the host can end this stream.');
        }

        $callLog->is_live = false;
        $callLog->viewer_count = 0;
        $callLog = $this->finalizeCall($callLog, 'completed', (int) $host->id);

        // Push end to followers + public feed.
        $followerIds = $this->followerIds((int) $host->id);
        event(new LivestreamEnded(
            streamId: (int) $callLog->id,
            followerIds: $followerIds,
            payload: $this->buildStreamPayload($callLog, $host, null),
        ));

        return $this->buildStreamPayload($callLog, $host, null);
    }

    /**
     * Discovery feed: currently-live streams.
     */
    public function activeStreams(int $perPage = 20, string $scope = 'all'): LengthAwarePaginator
    {
        $query = CallLog::query()
            ->where('call_type', 'stream')
            ->where('is_live', true)
            ->where('status', 'ongoing')
            ->with(['initiator:id,name,avatar']);

        if ($scope === 'popular') {
            $query->where('viewer_count', '>=', 150);
        }

        return $query->orderByDesc('started_at')
            ->paginate(max(1, min($perPage, 100)))
            ->through(fn (CallLog $callLog): array => [
                'livestream_id' => (int) $callLog->id,
                'title' => $callLog->title,
                'description' => $callLog->description,
                'thumbnail_url' => $callLog->thumbnail_url,
                'background_image_url' => $callLog->background_image_url,
                'video_url' => $callLog->video_url,
                'stream_layout' => $callLog->stream_layout ?? 'solo',
                'allow_gift_bet' => (bool) $callLog->allow_gift_bet,
                'custom_questions' => is_array($callLog->custom_questions) ? array_values($callLog->custom_questions) : ($callLog->custom_questions ?? []),
                'viewer_count' => (int) $callLog->viewer_count,
                'join_url' => (string) env('LIVEKIT_WS_URL', 'wss://livekit.castilo.co.uk'),
                'started_at' => $callLog->started_at?->toIso8601String(),
                'host' => $callLog->initiator ? [
                    'id' => (int) $callLog->initiator->id,
                    'name' => $callLog->initiator->name,
                    'avatar' => $this->resolveUserAvatar($callLog->initiator),
                ] : null,
            ]);
    }

    /**
     * Public stream detail (for discovery-feed viewers, NOT conversation members).
     * Does not require conversation membership.
     *
     * @return array<string, mixed>
     */
    public function getStream(User $viewer, int $streamId): array
    {
        $callLog = $this->loadCallOrFail($streamId);

        if ((string) $callLog->call_type !== 'stream') {
            throw ValidationException::withMessages([
                'stream' => ['Not a livestream.'],
            ]);
        }

        $payload = $this->buildStreamPayload($callLog, $viewer, null);

        $payload['poll'] = $this->getStreamPoll($viewer, (int) $callLog->id);

        return $payload;
    }

    /**
     * Webhook/job-safe end: ends a stream as the host without an auth guard.
     * Used by LivestreamHostGraceJob after the grace window expires.
     * No-op if the stream is already ended or not a stream.
     */
    public function endStreamSafely(int $streamId, int $hostId): void
    {
        $callLog = CallLog::query()->with('initiator')->find($streamId);

        if (
            ! $callLog instanceof CallLog
            || (string) $callLog->call_type !== 'stream'
            || (string) $callLog->status !== 'ongoing'
        ) {
            return;
        }

        // Mark grace as consumed so a late rejoin cannot cancel a real end.
        Cache::forget(
            LivestreamHostGraceJob::CACHE_KEY.$streamId
        );

        $host = $callLog->initiator ?? User::query()->find($hostId);

        $callLog->is_live = false;
        $callLog->viewer_count = 0;
        $callLog = $this->finalizeCall($callLog, 'completed', (int) ($host?->id ?? $hostId));

        $followerIds = $host instanceof User ? $this->followerIds((int) $host->id) : [];
        event(new LivestreamEnded(
            streamId: (int) $callLog->id,
            followerIds: $followerIds,
            payload: $this->buildStreamPayload($callLog, $host ?? $callLog->initiator, null),
        ));
    }

    // ----- stream helpers -----

    public function inviteStreamHost(User $host, int $streamId, int $targetUserId): array
    {
        $callLog = $this->loadCallOrFail($streamId);

        if ((string) $callLog->call_type !== 'stream' || (string) $callLog->status !== 'ongoing') {
            throw ValidationException::withMessages(['stream' => ['This stream is not live.']]);
        }

        if ((int) $host->id !== (int) $callLog->initiator_id) {
            throw new AuthorizationException('Only the host can invite co-hosts.');
        }

        $participant = CallParticipant::query()->firstOrNew([
            'call_log_id' => (int) $callLog->id,
            'user_id' => $targetUserId,
        ]);

        $participant->status = 'invited';
        $participant->role = 'co_host';
        $participant->save();

        $payload = [
            'livestream_id' => $callLog->id,
            'host_id' => $host->id,
            'host_name' => $host->name,
            'room_name' => $callLog->room_name,
        ];

        event(new LivestreamHostInvited($targetUserId, $payload));

        $targetUser = User::query()->find($targetUserId);
        if ($targetUser instanceof User) {
            $hostName = $host->name ?? 'Someone';
            $title = 'Livestream Co-Host Invitation';
            $body = "{$hostName} invited you to join their livestream as a co-host.";

            try {
                NotificationService::send(
                    user: $targetUser,
                    type: 'livestream_cohost_invite',
                    title: $title,
                    body: $body,
                    pushTitle: $title,
                    pushBody: $body,
                    data: [
                        'notification_type' => 'livestream_cohost_invite',
                        'livestream_id' => $callLog->id,
                        'host_id' => $host->id,
                        'host_name' => $hostName,
                        'room_name' => $callLog->room_name,
                    ],
                    image: $host->avatar ?? $host->avatar_url ?? null
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return ['message' => 'Co-host invited successfully.'];
    }

    public function acceptStreamHost(User $coHost, int $streamId): array
    {
        $callLog = $this->loadCallOrFail($streamId);

        $participant = CallParticipant::query()->where([
            'call_log_id' => (int) $callLog->id,
            'user_id' => (int) $coHost->id,
        ])->first();

        if (! $participant || $participant->role !== 'co_host') {
            throw new AuthorizationException('You are not invited as a co-host.');
        }

        $participant->status = 'joined';
        $participant->joined_at = $participant->joined_at ?? now();
        $participant->save();

        $token = $this->generateLiveKitToken($coHost, (string) $callLog->room_name, 'host');

        return $this->buildStreamPayload($callLog, $coHost, $token);
    }

    public function rejectStreamHost(User $coHost, int $streamId): array
    {
        $callLog = $this->loadCallOrFail($streamId);

        $participant = CallParticipant::query()->where([
            'call_log_id' => (int) $callLog->id,
            'user_id' => (int) $coHost->id,
        ])->first();

        if ($participant && $participant->role === 'co_host') {
            $participant->role = 'viewer';
            if ($participant->status === 'invited') {
                $participant->status = 'left';
            }
            $participant->save();
        }

        return ['message' => 'Co-host invitation rejected.'];
    }

    public function revokeStreamHost(User $host, int $streamId, int $targetUserId): array
    {
        $callLog = $this->loadCallOrFail($streamId);

        if ((int) $host->id !== (int) $callLog->initiator_id) {
            throw new AuthorizationException('Only the host can revoke co-hosts.');
        }

        $participant = CallParticipant::query()->where([
            'call_log_id' => (int) $callLog->id,
            'user_id' => $targetUserId,
        ])->first();

        if ($participant) {
            $participant->role = 'viewer';
            $participant->save();
        }

        // Always revoke publish permissions & mute tracks on media server (LiveKit/Agora)
        $mediaControlResult = $this->mediaControl->revokeCoHostPublish($callLog, $targetUserId);

        if (! (bool) ($mediaControlResult['success'] ?? false)) {
            // Keep authorization state consistent: the participant remains a
            // co-host in the database until the media server confirms revoke.
            if ($participant) {
                $participant->role = 'co_host';
                $participant->save();
            }

            Log::error('Livestream co-host revoke failed at media server', [
                'livestream_id' => $callLog->id,
                'target_user_id' => $targetUserId,
                'media_control' => $mediaControlResult,
            ]);

            throw new RuntimeException('Failed to revoke co-host permissions on the media server.');
        }

        event(new LivestreamHostRevoked(
            targetUserId: $targetUserId,
            conversationId: (int) $callLog->conversation_id,
            payload: [
                'livestream_id' => $callLog->id,
                'conversation_id' => (int) $callLog->conversation_id,
                'revoked_user_id' => $targetUserId,
                'host_id' => $host->id,
                'host_name' => $host->name,
                'role' => 'viewer',
                'message' => 'Your co-host permissions have been revoked.',
            ],
        ));

        $targetUser = User::query()->find($targetUserId);
        if ($targetUser instanceof User) {
            $hostName = $host->name ?? 'Host';
            $title = 'Co-Host Status Revoked';
            $body = "{$hostName} has revoked your co-host permissions on the livestream.";

            try {
                NotificationService::send(
                    user: $targetUser,
                    type: 'livestream_cohost_revoked',
                    title: $title,
                    body: $body,
                    pushTitle: $title,
                    pushBody: $body,
                    data: [
                        'notification_type' => 'livestream_cohost_revoked',
                        'livestream_id' => $callLog->id,
                        'host_id' => $host->id,
                        'host_name' => $hostName,
                        'room_name' => $callLog->room_name,
                    ],
                    image: $host->avatar ?? $host->avatar_url ?? null
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return [
            'message' => 'Co-host revoked successfully.',
            'media_control' => $mediaControlResult,
        ];
    }

    private function buildStreamRoomName(int $conversationId, int $streamId): string
    {
        return 'conv_'.$conversationId.'_stream_'.$streamId;
    }

    private function resolveUserAvatar(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        if (! empty($user->avatar)) {
            return str_starts_with($user->avatar, 'http')
                ? $user->avatar
                : asset('storage/'.ltrim($user->avatar, '/'));
        }

        $name = trim((string) ($user->name ?? 'User'));

        if ($name === '') {
            $name = 'User '.$user->id;
        }

        return 'https://ui-avatars.com/api/?name='.urlencode($name).'&background=random&size=256';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStreamPayload(CallLog $callLog, User $user, ?string $token): array
    {
        $participant = CallParticipant::query()->where([
            'call_log_id' => (int) $callLog->id,
            'user_id' => (int) $user->id,
        ])->first();

        $role = $participant ? $participant->role : ((int) $user->id === (int) $callLog->initiator_id ? 'host' : 'viewer');

        $hostUser = $callLog->initiator ?? User::query()->find((int) $callLog->initiator_id);
        $hostData = $hostUser ? [
            'id' => (int) $hostUser->id,
            'name' => (string) $hostUser->name,
            'avatar' => $this->resolveUserAvatar($hostUser),
        ] : null;

        $coHosts = CallParticipant::query()->with('user')
            ->where('call_log_id', (int) $callLog->id)
            ->where('role', 'co_host')
            ->where('status', 'joined')
            ->get()
            ->map(fn ($p) => $p->user ? [
                'id' => (int) $p->user->id,
                'name' => (string) $p->user->name,
                'avatar' => $this->resolveUserAvatar($p->user),
            ] : null)
            ->filter()
            ->values()
            ->all();

        $activeUsers = CallParticipant::query()
            ->where('call_log_id', (int) $callLog->id)
            ->where('status', 'joined')
            ->where('role', 'viewer')
            ->where('user_id', '!=', (int) $callLog->initiator_id)
            ->with('user:id,name,avatar')
            ->get()
            ->map(fn (CallParticipant $cp): ?array => $cp->user ? [
                'user_id' => (int) $cp->user->id,
                'name' => (string) $cp->user->name,
                'avatar' => $this->resolveUserAvatar($cp->user),
                'role' => 'viewer',
                'joined_at' => $cp->joined_at?->toIso8601String(),
            ] : null)
            ->filter()
            ->values()
            ->all();

        return [
            'livestream_id' => (int) $callLog->id,
            'call_type' => (string) $callLog->call_type,
            'started_at' => $callLog->started_at?->toIso8601String(),
            'title' => $callLog->title,
            'description' => $callLog->description,
            'thumbnail_url' => $callLog->thumbnail_url,
            'background_url' => $callLog->background_image_url,
            'video_url' => $callLog->video_url,
            'stream_layout' => $callLog->stream_layout ?? 'solo',
            'allow_gift_bet' => (bool) $callLog->allow_gift_bet,
            'custom_questions' => is_array($callLog->custom_questions) ? array_values($callLog->custom_questions) : ($callLog->custom_questions ?? []),
            'is_live' => (bool) $callLog->is_live,
            'viewer_count' => (int) $callLog->viewer_count,
            'likes_count' => (int) ($callLog->likes_count ?? 0),
            'is_liked' => CommunicationLike::query()
                ->where('user_id', (int) $user->id)
                ->where('likeable_type', CallLog::class)
                ->where('likeable_id', (int) $callLog->id)
                ->exists(),
            'status' => (string) $callLog->status,
            'room_name' => (string) ($callLog->room_name ?? ''),
            'join_url' => (string) env('LIVEKIT_WS_URL', 'wss://livekit.castilo.co.uk'),
            'token' => $token,   // null for non-token payloads
            'role' => $role,
            'host' => $hostData,
            'co_hosts' => $coHosts,
            'active_users' => $activeUsers,
        ];
    }

    private function broadcastStreamUpdated(CallLog $callLog, int $userId, string $action): void
    {
        event(new LivestreamUpdated(
            streamId: (int) $callLog->id,
            payload: [
                'livestream_id' => (int) $callLog->id,
                'title' => $callLog->title,
                'description' => $callLog->description,
                'stream_layout' => $callLog->stream_layout ?? 'solo',
                'viewer_count' => (int) $callLog->viewer_count,
                'action' => $action,
                'user_id' => $userId,
            ],
        ));
    }

    /**
     * @return array<int>
     */
    private function followerIds(int $userId): array
    {
        if (! class_exists(UserConnection::class)) {
            return [];
        }

        return UserConnection::query()
            ->where('recipient_id', $userId)
            ->where('connection_type', 'follow')
            ->pluck('requester_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function seedCallParticipants(CallLog $callLog, int $initiatorId): void
    {
        $activeParticipants = ConversationParticipant::query()
            ->where('conversation_id', (int) $callLog->conversation_id)
            ->where('status', 'active')
            ->get(['user_id']);

        $timestamp = now();

        $rows = $activeParticipants
            ->map(function (ConversationParticipant $participant) use ($callLog, $initiatorId, $timestamp): array {
                $isInitiator = (int) $participant->user_id === $initiatorId;

                return [
                    'call_log_id' => (int) $callLog->id,
                    'user_id' => (int) $participant->user_id,
                    'status' => $isInitiator ? 'joined' : 'invited',
                    'role' => $isInitiator ? 'host' : 'viewer',
                    'joined_at' => $isInitiator ? $timestamp : null,
                    'left_at' => null,
                    'kicked_by_id' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            })
            ->values()
            ->all();

        if ($rows === []) {
            return;
        }

        CallParticipant::query()->upsert(
            $rows,
            ['call_log_id', 'user_id'],
            ['status', 'joined_at', 'left_at', 'kicked_by_id', 'updated_at']
        );
    }

    private function joinedParticipantCount(int $callId): int
    {
        return (int) CallParticipant::query()
            ->where('call_log_id', $callId)
            ->where('status', 'joined')
            ->count();
    }

    private function callHadOtherParticipants(CallLog $callLog): bool
    {
        return CallParticipant::query()
            ->where('call_log_id', (int) $callLog->id)
            ->whereNotNull('joined_at')
            ->where('user_id', '!=', (int) $callLog->initiator_id)
            ->exists();
    }

    private function closeTimedOutOngoingCallInConversation(int $conversationId): ?CallLog
    {
        $ongoingCall = CallLog::query()
            ->with('conversation')
            ->where('conversation_id', $conversationId)
            ->where('status', 'ongoing')
            ->latest('id')
            ->first();

        if (! $ongoingCall instanceof CallLog) {
            return null;
        }

        return $this->closeTimedOutOngoingCall($ongoingCall);
    }

    public function closeTimedOutOngoingCallById(int $callId): ?CallLog
    {
        $callLog = CallLog::query()
            ->with('conversation')
            ->find($callId);

        if (! $callLog instanceof CallLog) {
            return null;
        }

        return $this->closeTimedOutOngoingCall($callLog);
    }

    private function closeTimedOutOngoingCall(CallLog $callLog): CallLog
    {
        if (! $this->shouldCloseTimedOutOngoingCall($callLog)) {
            return $callLog;
        }

        $mediaControlResult = $this->mediaControl->endCallRoom(
            $callLog,
            $this->joinedParticipantIds((int) $callLog->id)
        );

        $callLog = $this->finalizeCall($callLog, 'missed', null);

        // Create a system message to reflect the missed call
        try {
            $initiator = $callLog->initiator;
            $initiatorName = $initiator ? trim((string) (($initiator->first_name ?? '').' '.($initiator->last_name ?? ''))) : 'User';
            if ($initiatorName === '') {
                $initiatorName = 'User '.(int) ($callLog->initiator_id ?? 'Unknown');
            }

            $systemUser = User::query()->find((int) ($callLog->initiator_id ?? 0));
            if ($systemUser instanceof User) {
                $this->messageService->createCallEndedMessage($systemUser, (int) $callLog->conversation_id, [
                    'call_id' => (int) $callLog->id,
                    'status' => 'missed',
                    'duration_seconds' => (int) $callLog->duration_seconds,
                    'call_type' => (string) $callLog->call_type,
                    'had_other_participants' => $this->callHadOtherParticipants($callLog),
                    'initiator_id' => (int) ($callLog->initiator_id ?? 0),
                    'initiator_name' => $initiatorName,
                    'started_at' => $callLog->started_at?->toIso8601String(),
                    'ended_at' => $callLog->ended_at?->toIso8601String(),
                ]);
            }
        } catch (Throwable) {
            // Log message creation failure but don't block the call timeout handling
        }

        $this->broadcastLifecycle(
            'CallEnded',
            $callLog,
            [
                'action' => 'ended',
                'ended_by_id' => null,
                'ended_by_name' => 'The system',
                'reason' => 'call_ring_timeout',
                'media_control' => $mediaControlResult,
            ]
        );

        return $callLog;
    }

    private function shouldCloseTimedOutOngoingCall(CallLog $callLog): bool
    {
        if ((string) $callLog->status !== 'ongoing') {
            return false;
        }

        if (! $callLog->started_at) {
            return false;
        }

        $conversation = $callLog->conversation;
        $isGroup = $conversation instanceof Conversation && (bool) $conversation->is_group;

        $timeoutConfigKey = $isGroup
            ? 'communication.call_ring_timeout_seconds_group'
            : 'communication.call_ring_timeout_seconds_one_to_one';
        $timeoutSeconds = max((int) config($timeoutConfigKey, 5), 1);

        $startedAt = Carbon::parse($callLog->started_at);

        if ($startedAt->copy()->addSeconds($timeoutSeconds)->isFuture()) {
            return false;
        }

        $joinedOthers = (int) CallParticipant::query()
            ->where('call_log_id', (int) $callLog->id)
            ->where('status', 'joined')
            ->where('user_id', '!=', (int) $callLog->initiator_id)
            ->count();

        return $joinedOthers === 0;
    }

    /**
     * @return array<int>
     */
    private function joinedParticipantIds(int $callId): array
    {
        return CallParticipant::query()
            ->where('call_log_id', $callId)
            ->where('status', 'joined')
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function finalizeCall(CallLog $callLog, string $status, ?int $endedById): CallLog
    {
        if ((string) $callLog->status !== 'ongoing') {
            return $callLog;
        }

        $endedAt = now();
        $durationSeconds = $this->calculateCallDurationSeconds($callLog, $endedAt);

        $callLog->status = in_array($status, ['completed', 'missed'], true) ? $status : 'completed';
        $callLog->ended_at = $endedAt;
        $callLog->ended_by_id = $endedById;
        $callLog->duration_seconds = $durationSeconds;
        $callLog->save();

        CallParticipant::query()
            ->where('call_log_id', (int) $callLog->id)
            ->whereIn('status', ['invited', 'joined'])
            ->update([
                'status' => 'ended',
                'left_at' => $endedAt,
                'updated_at' => $endedAt,
            ]);

        return $callLog->fresh(['conversation']) ?? $callLog;
    }

    private function calculateCallDurationSeconds(CallLog $callLog, \DateTimeInterface $endedAt): int
    {
        $participants = CallParticipant::query()
            ->where('call_log_id', (int) $callLog->id)
            ->whereNotNull('joined_at')
            ->get(['joined_at', 'left_at']);

        if ($participants->count() < 2) {
            return 0;
        }

        Log::error('Calculating call duration with multiple participants', [
            'call_log_id' => $callLog->id,
            'participant_count' => $participants->count(),
        ]);

        $events = [];
        $endedAtTimestamp = $endedAt->getTimestamp();

        foreach ($participants as $participant) {
            $joinedAt = $participant->joined_at;

            if (! $joinedAt instanceof \DateTimeInterface) {
                continue;
            }

            $leftAt = $participant->left_at ?? $endedAt;

            if (! $leftAt instanceof \DateTimeInterface) {
                $leftAt = $endedAt;
            }

            $joinedAtTimestamp = $joinedAt->getTimestamp();
            $leftAtTimestamp = min($leftAt->getTimestamp(), $endedAtTimestamp);

            if ($leftAtTimestamp <= $joinedAtTimestamp) {
                continue;
            }

            $events[] = ['time' => $joinedAtTimestamp, 'delta' => 1];
            $events[] = ['time' => $leftAtTimestamp, 'delta' => -1];
        }

        if ($events === []) {
            return 0;
        }

        usort(
            $events,
            static fn (array $left, array $right): int => $left['time'] <=> $right['time']
        );

        $activeCount = 0;
        $totalSeconds = 0;
        $currentTime = null;
        $index = 0;
        $eventCount = count($events);

        while ($index < $eventCount) {
            $eventTime = $events[$index]['time'];

            if ($currentTime !== null && $eventTime > $currentTime && $activeCount >= 2) {
                $totalSeconds += $eventTime - $currentTime;
            }

            while ($index < $eventCount && $events[$index]['time'] === $eventTime) {
                $activeCount += $events[$index]['delta'];
                $index++;
            }

            $currentTime = $eventTime;
        }

        return max($totalSeconds, 0);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function participantPayload(int $callId): array
    {
        return CallParticipant::query()
            ->where('call_log_id', $callId)
            ->with(['user:id,first_name,last_name,avatar'])
            ->orderByRaw("CASE WHEN status = 'joined' THEN 0 WHEN status = 'invited' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->get()
            ->map(function (CallParticipant $participant): array {
                $user = $participant->user;

                return [
                    'user_id' => (int) $participant->user_id,
                    'name' => $user instanceof User ? $this->displayName($user) : ('User '.(int) $participant->user_id),
                    'avatar' => $user?->avatar,
                    'status' => (string) $participant->status,
                    'joined_at' => $participant->joined_at?->toISOString(),
                    'left_at' => $participant->left_at?->toISOString(),
                    'kicked_by_id' => $participant->kicked_by_id !== null ? (int) $participant->kicked_by_id : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCallPayload(CallLog $callLog, User $viewer, bool $includeToken): array
    {
        $callLog->loadMissing('conversation');

        $conversation = $callLog->conversation;
        if (! $conversation instanceof Conversation) {
            throw new ModelNotFoundException('Conversation not found for this call.');
        }

        $engine = trim((string) ($callLog->engine ?? ''));
        if ($engine === '') {
            $engine = $this->resolveEngine($conversation, (string) $callLog->call_type);
        }

        $roomName = trim((string) ($callLog->room_name ?? ''));
        if ($roomName === '') {
            $roomName = $this->buildRoomName((int) $callLog->conversation_id, (int) $callLog->id);
        }

        $livekit = null;
        $agora = null;

        if ($engine === 'livekit') {
            $livekit = [
                'join_url' => $this->normalizedLiveKitUrl(),
                'token' => null,
            ];

            if ($includeToken && (string) $callLog->status === 'ongoing') {
                $livekit['token'] = $this->generateLiveKitToken($viewer, $roomName);
            }
        }

        if ($engine === 'agora') {
            $agora = [
                'app_id' => (string) config('communication.agora.app_id'),
                'uid' => (int) $viewer->id,
                'token' => null,
            ];

            if ($includeToken && (string) $callLog->status === 'ongoing') {
                $agora['token'] = $this->generateAgoraToken($viewer, $roomName);
            }
        }

        return [
            'call_id' => (int) $callLog->id,
            'conversation_id' => (int) $callLog->conversation_id,
            'call_type' => (string) $callLog->call_type,
            'scale_type' => $conversation->is_group ? 'group' : 'one_to_one',
            'engine' => $engine,
            'status' => (string) $callLog->status,
            'room_name' => $roomName,

            'livekit' => $livekit,
            'agora' => $agora,

            'started_at' => $callLog->started_at?->toISOString(),
            'ended_at' => $callLog->ended_at?->toISOString(),
            'duration_seconds' => $this->resolveDurationSeconds($callLog),
        ];
    }

    private function resolveDurationSeconds(CallLog $callLog): int
    {
        $stored = (int) ($callLog->duration_seconds ?? 0);

        if ($stored > 0) {
            return $stored;
        }

        if ($callLog->ended_at instanceof \DateTimeInterface) {
            return $this->calculateCallDurationSeconds($callLog, $callLog->ended_at);
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function broadcastLifecycle(string $eventName, CallLog $callLog, array $payload): void
    {
        if (! array_key_exists('call', $payload)) {
            $viewer = User::query()->find((int) ($payload['ended_by_id'] ?? $callLog->initiator_id));

            if (! $viewer instanceof User && $callLog->initiator instanceof User) {
                $viewer = $callLog->initiator;
            }

            if ($viewer instanceof User) {
                $payload['call'] = $this->buildCallPayload($callLog, $viewer, false);
            }
        }

        $callback = function () use ($eventName, $callLog, $payload) {
            event(new CallLifecycleUpdated(
                $eventName,
                (int) $callLog->conversation_id,
                $this->messages->getParticipantIds((int) $callLog->conversation_id),
                $payload
            ));
        };

        if (app()->runningInConsole()) {
            $callback();
        } else {
            dispatch($callback)->afterResponse();
        }
    }

    /**
     * @param  'host'|'viewer'  $mode  host publishes, viewer subscribes only
     */
    private function generateLiveKitToken(User $user, string $roomName, string $mode = 'host'): string
    {
        $apiKey = (string) config('communication.livekit.api_key', '');
        $apiSecret = (string) config('communication.livekit.api_secret', '');

        if ($apiKey === '' || $apiSecret === '') {
            if (config('app.env') === 'local' || config('app.debug')) {
                return 'mock_livekit_token_'.bin2hex(random_bytes(32));
            }
            throw new RuntimeException('LiveKit credentials are not configured.');
        }

        $isHost = $mode === 'host';

        $tokenOptions = (new AccessTokenOptions)
            ->setIdentity((string) $user->id)
            ->setName($this->displayName($user))
            ->setTtl((int) config('communication.livekit.token_ttl', 3600));

        $grant = (new VideoGrant)
            ->setRoomJoin(true)
            ->setRoomName($roomName)
            ->setCanPublish($isHost)          // host publishes; viewer is subscribe-only
            ->setCanSubscribe(true)
            ->setCanPublishData($isHost);

        $token = new AccessToken($apiKey, $apiSecret, $tokenOptions);
        $token->setGrant($grant);

        return $token->toJwt();
    }

    private function generateAgoraToken(User $user, string $roomName): ?string
    {
        $appId = (string) config('communication.agora.app_id');
        $appCertificate = (string) config('communication.agora.app_certificate');

        if ($appId === '') {
            throw new RuntimeException('Agora configurations missing.');
        }

        if ($appCertificate === '') {
            // If no certificate is provided, most Agora setups run in simplified AppID-only mode (No Token).
            // So no token is needed.
            return null;
        }

        $ttl = max((int) config('communication.agora.token_ttl', 3600), 1);
        $expiresAt = now()->getTimestamp() + $ttl;
        $uid = (int) $user->id;

        if ($uid <= 0) {
            $uid = 1;
        }

        $token = RtcTokenBuilder::buildTokenWithUid(
            $appId,
            $appCertificate,
            $roomName,
            $uid,
            RtcTokenBuilder::RolePublisher,
            $expiresAt
        );

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Failed to generate Agora token.');
        }

        return $token;
    }

    private function normalizedLiveKitUrl(): ?string
    {
        $url = trim((string) config('communication.livekit.url', ''));

        if ($url === '') {
            return null;
        }

        return rtrim($url, '/');
    }

    private function displayName(User $user): string
    {
        $name = trim((string) ($user->first_name ?? '').' '.(string) ($user->last_name ?? ''));

        if ($name !== '') {
            return $name;
        }

        return 'User '.(int) $user->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCallHistoryItem(CallLog $callLog, int $viewerId): array
    {
        $participant = $callLog->participants
            ->first(fn (CallParticipant $item): bool => (int) $item->user_id === $viewerId);

        $direction = (int) $callLog->initiator_id === $viewerId ? 'outgoing' : 'incoming';
        $viewerStatus = (string) ($participant?->status ?? 'invited');

        $isMissed = (string) $callLog->status === 'missed'
            || ($direction === 'incoming' && in_array($viewerStatus, ['invited'], true));

        $conversation = $callLog->conversation;

        return [
            'call_id' => (int) $callLog->id,
            'conversation_id' => (int) $callLog->conversation_id,
            'status' => (string) $callLog->status,
            'call_type' => (string) $callLog->call_type,
            'engine' => (string) ($callLog->engine ?? ''),
            'room_name' => (string) ($callLog->room_name ?? ''),
            'direction' => $direction,
            'viewer_status' => $viewerStatus,
            'is_missed_for_viewer' => $isMissed,
            'duration_seconds' => (int) ($callLog->duration_seconds ?? 0),
            'started_at' => $callLog->started_at?->toISOString(),
            'ended_at' => $callLog->ended_at?->toISOString(),
            'ended_by_id' => $callLog->ended_by_id !== null ? (int) $callLog->ended_by_id : null,
            'conversation' => [
                'is_group' => (bool) ($conversation?->is_group ?? false),
                'name' => $conversation?->name,
                'avatar' => $conversation?->avatar,
            ],
            'initiator' => [
                'id' => (int) $callLog->initiator_id,
                'name' => $callLog->initiator instanceof User ? $this->displayName($callLog->initiator) : ('User '.(int) $callLog->initiator_id),
                'avatar' => $callLog->initiator?->avatar,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function firstHeaderValue(array $headers, string $key): ?string
    {
        $raw = $headers[$key] ?? $headers[strtolower($key)] ?? null;

        if (is_array($raw)) {
            $first = $raw[0] ?? null;

            return is_scalar($first) ? (string) $first : null;
        }

        return is_scalar($raw) ? (string) $raw : null;
    }

    // ===================================================================
    // LIVESTREAM COMMENTS & LIKES METHODS
    // ===================================================================

    /**
     * Add a comment or reply to a livestream.
     *
     * @return array<string, mixed>
     */
    public function addStreamComment(User $user, int $streamId, string $commentText, ?int $parentId = null): array
    {
        $callLog = $this->loadCallOrFail($streamId);

        if ((string) $callLog->call_type !== 'stream') {
            throw ValidationException::withMessages([
                'stream' => ['Not a livestream.'],
            ]);
        }

        if ($parentId !== null && $parentId > 0) {
            $parentComment = LivestreamComment::query()
                ->where('id', $parentId)
                ->where('call_log_id', (int) $callLog->id)
                ->first();

            if (! $parentComment instanceof LivestreamComment) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Parent comment not found in this livestream.'],
                ]);
            }
        } else {
            $parentId = null;
        }

        $comment = DB::transaction(function () use ($callLog, $user, $commentText, $parentId): LivestreamComment {
            $comment = LivestreamComment::query()->create([
                'call_log_id' => (int) $callLog->id,
                'user_id' => (int) $user->id,
                'parent_id' => $parentId,
                'comment' => trim($commentText),
                'likes_count' => 0,
                'replies_count' => 0,
            ]);

            if ($parentId !== null) {
                LivestreamComment::query()->where('id', $parentId)->increment('replies_count');
            }

            return $comment;
        });

        $comment->loadMissing('user');

        return $this->formatStreamComment($comment, $user);
    }

    /**
     * Get paginated comments for a livestream.
     *
     * @return array<string, mixed>
     */
    public function getStreamComments(User $user, int $streamId, int $page = 1, int $perPage = 10): array
    {
        $callLog = $this->loadCallOrFail($streamId);

        if ((string) $callLog->call_type !== 'stream') {
            throw ValidationException::withMessages([
                'stream' => ['Not a livestream.'],
            ]);
        }

        $query = LivestreamComment::query()
            ->where('call_log_id', (int) $callLog->id)
            ->whereNull('parent_id')
            ->with(['user', 'replies.user', 'replies' => function ($q) {
                $q->orderBy('created_at', 'asc');
            }])
            ->orderByDesc('created_at');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        // Fetch user's liked comment IDs in bulk
        $commentIds = $paginator->pluck('id')->merge(
            $paginator->pluck('replies')->flatten()->pluck('id')
        )->unique()->filter()->all();

        $likedCommentIds = [];
        if (! empty($commentIds)) {
            $likedCommentIds = CommunicationLike::query()
                ->where('user_id', (int) $user->id)
                ->where('likeable_type', LivestreamComment::class)
                ->whereIn('likeable_id', $commentIds)
                ->pluck('likeable_id')
                ->map(fn ($id) => (int) $id)
                ->flip()
                ->all();
        }

        $formattedComments = collect($paginator->items())->map(
            fn (LivestreamComment $comment) => $this->formatStreamComment($comment, $user, $likedCommentIds)
        )->all();

        return [
            'livestream_id' => (int) $callLog->id,
            'comments' => $formattedComments,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * Toggle like/unlike for a livestream comment or reply.
     *
     * @return array<string, mixed>
     */
    public function toggleCommentLike(User $user, int $commentId): array
    {
        $comment = LivestreamComment::query()->findOrFail($commentId);

        $like = CommunicationLike::query()
            ->where('user_id', (int) $user->id)
            ->where('likeable_type', LivestreamComment::class)
            ->where('likeable_id', (int) $comment->id)
            ->first();

        if ($like instanceof CommunicationLike) {
            $like->delete();
            if ($comment->likes_count > 0) {
                $comment->decrement('likes_count');
            }
            $isLiked = false;
            $message = 'Comment unliked.';
        } else {
            CommunicationLike::query()->create([
                'user_id' => (int) $user->id,
                'likeable_type' => LivestreamComment::class,
                'likeable_id' => (int) $comment->id,
            ]);
            $comment->increment('likes_count');
            $isLiked = true;
            $message = 'Comment liked.';
        }

        $comment->refresh();

        return [
            'comment_id' => (int) $comment->id,
            'is_liked' => $isLiked,
            'likes_count' => (int) $comment->likes_count,
            'message' => $message,
        ];
    }

    /**
     * Toggle like/unlike for a livestream itself.
     *
     * @return array<string, mixed>
     */
    public function toggleStreamLike(User $user, int $streamId): array
    {
        $callLog = $this->loadCallOrFail($streamId);

        if ((string) $callLog->call_type !== 'stream') {
            throw ValidationException::withMessages([
                'stream' => ['Not a livestream.'],
            ]);
        }

        $like = CommunicationLike::query()
            ->where('user_id', (int) $user->id)
            ->where('likeable_type', CallLog::class)
            ->where('likeable_id', (int) $callLog->id)
            ->first();

        if ($like instanceof CommunicationLike) {
            $like->delete();
            if ($callLog->likes_count > 0) {
                $callLog->decrement('likes_count');
            }
            $isLiked = false;
            $message = 'Livestream unliked.';
        } else {
            CommunicationLike::query()->create([
                'user_id' => (int) $user->id,
                'likeable_type' => CallLog::class,
                'likeable_id' => (int) $callLog->id,
            ]);
            $callLog->increment('likes_count');
            $isLiked = true;
            $message = 'Livestream liked.';
        }

        $callLog->refresh();

        return [
            'livestream_id' => (int) $callLog->id,
            'is_liked' => $isLiked,
            'likes_count' => (int) $callLog->likes_count,
            'message' => $message,
        ];
    }

    /**
     * Delete a comment or reply.
     *
     * @return array<string, mixed>
     */
    public function deleteStreamComment(User $user, int $commentId): array
    {
        $comment = LivestreamComment::query()->findOrFail($commentId);

        $callLog = CallLog::query()->find($comment->call_log_id);
        $isHost = $callLog instanceof CallLog && (int) $callLog->initiator_id === (int) $user->id;

        if ((int) $comment->user_id !== (int) $user->id && ! $isHost) {
            throw new AuthorizationException('You are not authorized to delete this comment.');
        }

        DB::transaction(function () use ($comment): void {
            if ($comment->parent_id !== null) {
                LivestreamComment::query()->where('id', $comment->parent_id)->where('replies_count', '>', 0)->decrement('replies_count');
            }
            $comment->delete();
        });

        return [
            'comment_id' => $commentId,
            'deleted' => true,
        ];
    }

    /**
     * Format a single comment and its replies.
     *
     * @param  array<int, mixed>|null  $likedCommentIds
     * @return array<string, mixed>
     */
    private function formatStreamComment(LivestreamComment $comment, User $user, ?array $likedCommentIds = null): array
    {
        $commenterName = trim((string) ($comment->user?->name ?? 'User'));
        if ($commenterName === '') {
            $commenterName = 'User '.(int) $comment->user_id;
        }

        $isLiked = $likedCommentIds !== null
            ? isset($likedCommentIds[(int) $comment->id])
            : CommunicationLike::query()
                ->where('user_id', (int) $user->id)
                ->where('likeable_type', LivestreamComment::class)
                ->where('likeable_id', (int) $comment->id)
                ->exists();

        $replies = [];
        if ($comment->relationLoaded('replies') && $comment->replies->isNotEmpty()) {
            $replies = $comment->replies->map(
                fn (LivestreamComment $reply) => $this->formatStreamComment($reply, $user, $likedCommentIds)
            )->all();
        }

        return [
            'comment_id' => (int) $comment->id,
            'parent_id' => $comment->parent_id !== null ? (int) $comment->parent_id : null,
            'comment' => $comment->comment,
            'commenter' => [
                'id' => (int) $comment->user_id,
                'name' => $commenterName,
                'avatar' => $this->resolveUserAvatar($comment->user),
            ],
            'likes_count' => (int) $comment->likes_count,
            'replies_count' => (int) $comment->replies_count,
            'is_liked' => $isLiked,
            'is_own_comment' => (int) $user->id === (int) $comment->user_id,
            'created_at' => $comment->created_at?->diffForHumans() ?? 'just now',
            'replies' => $replies,
        ];
    }

    // ===================================================================
    // LIVESTREAM POLL METHODS
    // ===================================================================

    /**
     * Create and start a new poll for a livestream.
     * Only the host can create a poll.
     *
     * @param  array<string>  $options
     * @return array<string, mixed>
     */
    public function createStreamPoll(User $host, int $streamId, string $question, array $options): array
    {
        $callLog = $this->loadCallOrFail($streamId);

        if ((string) $callLog->call_type !== 'stream') {
            throw ValidationException::withMessages([
                'stream' => ['Not a livestream.'],
            ]);
        }

        if ((int) $callLog->initiator_id !== (int) $host->id) {
            throw new AuthorizationException('Only the host can create a poll.');
        }

        if ((string) $callLog->status !== 'ongoing' || ! $callLog->is_live) {
            throw ValidationException::withMessages([
                'stream' => ['Livestream is not currently active.'],
            ]);
        }

        $cleanOptions = array_values(array_filter(array_map('trim', $options), fn ($opt) => $opt !== ''));

        if (count($cleanOptions) < 2) {
            throw ValidationException::withMessages([
                'options' => ['At least 2 poll options are required.'],
            ]);
        }

        $poll = DB::transaction(function () use ($callLog, $host, $question, $cleanOptions): LivestreamPoll {
            // Close any existing active polls on this stream
            LivestreamPoll::query()
                ->where('call_log_id', (int) $callLog->id)
                ->where('status', 'active')
                ->update(['status' => 'ended']);

            $poll = LivestreamPoll::query()->create([
                'call_log_id' => (int) $callLog->id,
                'creator_id' => (int) $host->id,
                'question' => trim($question),
                'status' => 'active',
                'total_votes' => 0,
            ]);

            foreach ($cleanOptions as $optText) {
                LivestreamPollOption::query()->create([
                    'poll_id' => (int) $poll->id,
                    'option_text' => $optText,
                    'votes_count' => 0,
                ]);
            }

            return $poll;
        });

        $poll->load('options');

        $formatted = $this->formatStreamPoll($poll, $host);

        event(new LivestreamPollUpdated(
            streamId: (int) $callLog->id,
            payload: array_merge($formatted, ['action' => 'started']),
            conversationId: (int) $callLog->conversation_id,
        ));

        return $formatted;
    }

    /**
     * Get current/active poll details for a livestream.
     *
     * @return array<string, mixed>|null
     */
    public function getStreamPoll(User $user, int $streamId): ?array
    {
        $callLog = $this->loadCallOrFail($streamId);

        $poll = LivestreamPoll::query()
            ->where('call_log_id', (int) $callLog->id)
            ->where('status', 'active')
            ->with('options')
            ->latest()
            ->first();

        if (! $poll instanceof LivestreamPoll) {
            $poll = LivestreamPoll::query()
                ->where('call_log_id', (int) $callLog->id)
                ->with('options')
                ->latest()
                ->first();
        }

        if (! $poll instanceof LivestreamPoll) {
            return null;
        }

        return $this->formatStreamPoll($poll, $user);
    }

    /**
     * Vote on an active poll option.
     *
     * @return array<string, mixed>
     */
    public function voteStreamPoll(User $user, int $pollId, int $optionId): array
    {
        $poll = LivestreamPoll::query()->with('options')->findOrFail($pollId);

        if ((string) $poll->status !== 'active') {
            throw ValidationException::withMessages([
                'poll' => ['This poll has already ended.'],
            ]);
        }

        $option = LivestreamPollOption::query()
            ->where('id', $optionId)
            ->where('poll_id', (int) $poll->id)
            ->first();

        if (! $option instanceof LivestreamPollOption) {
            throw ValidationException::withMessages([
                'option_id' => ['Selected option does not belong to this poll.'],
            ]);
        }

        $existingVote = LivestreamPollVote::query()
            ->where('poll_id', (int) $poll->id)
            ->where('user_id', (int) $user->id)
            ->first();

        DB::transaction(function () use ($poll, $option, $user, $existingVote): void {
            if ($existingVote instanceof LivestreamPollVote) {
                // If user voted for the same option, no changes needed
                if ((int) $existingVote->option_id === (int) $option->id) {
                    return;
                }

                // Switch vote from old option to new option
                $oldOption = LivestreamPollOption::query()->find($existingVote->option_id);
                if ($oldOption instanceof LivestreamPollOption && $oldOption->votes_count > 0) {
                    $oldOption->decrement('votes_count');
                }

                $existingVote->update(['option_id' => (int) $option->id]);
                $option->increment('votes_count');
            } else {
                // First time voting
                LivestreamPollVote::query()->create([
                    'poll_id' => (int) $poll->id,
                    'option_id' => (int) $option->id,
                    'user_id' => (int) $user->id,
                ]);

                $option->increment('votes_count');
                $poll->increment('total_votes');
            }
        });

        $poll->refresh();
        $poll->load('options');

        $formatted = $this->formatStreamPoll($poll, $user);

        $callLog = CallLog::query()->find($poll->call_log_id);

        event(new LivestreamPollUpdated(
            streamId: (int) $poll->call_log_id,
            payload: array_merge($formatted, ['action' => 'voted']),
            conversationId: $callLog instanceof CallLog ? (int) $callLog->conversation_id : null,
        ));

        return $formatted;
    }

    /**
     * End an active poll.
     *
     * @return array<string, mixed>
     */
    public function endStreamPoll(User $host, int $pollId): array
    {
        $poll = LivestreamPoll::query()->with('options')->findOrFail($pollId);

        $callLog = CallLog::query()->find($poll->call_log_id);
        $isHost = $callLog instanceof CallLog && (int) $callLog->initiator_id === (int) $host->id;

        if ((int) $poll->creator_id !== (int) $host->id && ! $isHost) {
            throw new AuthorizationException('Only the host or poll creator can end this poll.');
        }

        $poll->update(['status' => 'ended']);
        $poll->refresh();

        $formatted = $this->formatStreamPoll($poll, $host);

        event(new LivestreamPollUpdated(
            streamId: (int) $poll->call_log_id,
            payload: array_merge($formatted, ['action' => 'ended']),
            conversationId: $callLog instanceof CallLog ? (int) $callLog->conversation_id : null,
        ));

        return $formatted;
    }

    /**
     * Format a poll model for API output.
     *
     * @return array<string, mixed>
     */
    private function formatStreamPoll(LivestreamPoll $poll, User $user): array
    {
        $userVote = LivestreamPollVote::query()
            ->where('poll_id', (int) $poll->id)
            ->where('user_id', (int) $user->id)
            ->first();

        $totalVotes = (int) $poll->total_votes;

        $options = $poll->options->map(function (LivestreamPollOption $opt) use ($totalVotes): array {
            $votes = (int) $opt->votes_count;
            $percentage = $totalVotes > 0 ? (int) round(($votes / $totalVotes) * 100) : 0;

            return [
                'option_id' => (int) $opt->id,
                'option' => (string) $opt->option_text,
                'votes_count' => $votes,
                'percentage' => $percentage,
            ];
        })->values()->all();

        return [
            'poll_id' => (int) $poll->id,
            'livestream_id' => (int) $poll->call_log_id,
            'question' => (string) $poll->question,
            'status' => (string) $poll->status,
            'total_votes' => $totalVotes,
            'user_voted_option_id' => $userVote instanceof LivestreamPollVote ? (int) $userVote->option_id : null,
            'has_voted' => $userVote instanceof LivestreamPollVote,
            'options' => $options,
            'created_at' => $poll->created_at?->toIso8601String(),
        ];
    }

    // ===================================================================
    // LIVESTREAM GIFTING METHODS
    // ===================================================================

    /**
     * Create a Stripe PaymentIntent for a livestream gift.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sendStreamGift(User $sender, int $streamId, array $data): array
    {
        $callLog = $this->loadCallOrFail($streamId);

        if ((string) $callLog->call_type !== 'stream') {
            throw ValidationException::withMessages([
                'stream' => ['Not a livestream.'],
            ]);
        }

        if ((string) $callLog->status !== 'ongoing') {
            throw ValidationException::withMessages([
                'stream' => ['This livestream has ended.'],
            ]);
        }

        $hostId = (int) $callLog->initiator_id;
        if ((int) $sender->id === $hostId) {
            throw ValidationException::withMessages([
                'sender' => ['You cannot send a gift to yourself.'],
            ]);
        }

        $senderProfile = Profile::where('user_id', (int) $sender->id)->first();
        if (! $senderProfile) {
            throw ValidationException::withMessages([
                'sender' => ['Sender profile not found. Complete your profile.'],
            ]);
        }

        $host = User::findOrFail($hostId);
        $hostProfile = Profile::where('user_id', $hostId)->first();
        if (! $hostProfile) {
            throw ValidationException::withMessages([
                'host' => ['Host profile not found.'],
            ]);
        }

        $giftId = isset($data['gift_id']) ? (int) $data['gift_id'] : null;
        $giftType = isset($data['gift_type']) ? (string) $data['gift_type'] : null;
        $amount = isset($data['amount']) ? (float) $data['amount'] : 0.0;

        if ($giftId !== null && $giftId > 0) {
            $gift = Gift::where('id', $giftId)->where('is_active', true)->first();
            if (! $gift) {
                throw ValidationException::withMessages([
                    'gift_id' => ['The selected gift is invalid or inactive.'],
                ]);
            }
            // If amount was not explicitly passed, fall back to the gift's coin cost
            if ($amount <= 0) {
                $amount = (float) $gift->coin_cost;
            }
            $giftType = $giftType ?: $gift->title;
        } else {
            // No gift_id, so amount must be passed
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Gift amount must be greater than 0.'],
                ]);
            }

            $isValidPreset = LivestreamAmountPreset::active()
                ->where('amount', $amount)
                ->exists();
            if (! $isValidPreset) {
                throw ValidationException::withMessages([
                    'amount' => ['Selected amount is not a valid preset.'],
                ]);
            }
            $giftType = $giftType ?: 'Amount Preset';
        }

        $messageText = isset($data['message']) ? trim((string) $data['message']) : null;
        $sortOrder = isset($data['sort_order']) ? (int) $data['sort_order'] : 0;

        $senderWallet = GiftWallet::firstOrCreate(
            ['user_id' => $senderProfile->id],
            [
                'balance' => 0,
                'total_deposited' => 0,
                'total_withdrawn' => 0,
                'total_gifted' => 0,
                'total_received' => 0,
            ]
        );

        // If user has sufficient wallet balance, deduct directly
        if ((float) $senderWallet->balance >= $amount) {
            return DB::transaction(function () use ($sender, $senderProfile, $hostProfile, $senderWallet, $callLog, $giftId, $giftType, $amount, $messageText, $sortOrder): array {
                // Deduct from sender wallet
                $senderWallet->balance -= $amount;
                $senderWallet->total_gifted += $amount;
                $senderWallet->save();

                // Credit host wallet
                $hostWallet = GiftWallet::firstOrCreate(
                    ['user_id' => $hostProfile->id],
                    [
                        'balance' => 0,
                        'total_deposited' => 0,
                        'total_withdrawn' => 0,
                        'total_gifted' => 0,
                        'total_received' => 0,
                    ]
                );
                $hostWallet->balance += $amount;
                $hostWallet->total_received += $amount;
                $hostWallet->save();

                $reference = 'GIFT-'.strtoupper(Str::random(10));

                // Sender transaction (debit)
                WalletTransaction::create([
                    'wallet_id' => $senderWallet->id,
                    'user_id' => $senderProfile->id,
                    'type' => 'gift_sent',
                    'amount' => -$amount,
                    'balance_after' => $senderWallet->balance,
                    'reference' => $reference,
                    'payment_method' => 'wallet',
                    'description' => $messageText ?: "Livestream gift sent on Stream #{$callLog->id}",
                    'status' => 'completed',
                    'recipient_id' => $hostProfile->id,
                    'completed_at' => now(),
                ]);

                // Host transaction (credit)
                WalletTransaction::create([
                    'wallet_id' => $hostWallet->id,
                    'user_id' => $hostProfile->id,
                    'type' => 'gift_received',
                    'amount' => $amount,
                    'balance_after' => $hostWallet->balance,
                    'reference' => 'GIFT-RECV-'.strtoupper(Str::random(10)),
                    'payment_method' => 'wallet',
                    'description' => $messageText ?: "Livestream gift received on Stream #{$callLog->id}",
                    'status' => 'completed',
                    'recipient_id' => $hostProfile->id,
                    'completed_at' => now(),
                ]);

                // Create completed livestream gift record
                $livestreamGift = LivestreamGift::create([
                    'call_log_id' => (int) $callLog->id,
                    'sender_id' => (int) $sender->id,
                    'gift_id' => $giftId,
                    'gift_type' => $giftType,
                    'amount' => $amount,
                    'message' => $messageText,
                    'sort_order' => $sortOrder,
                    'stripe_payment_intent_id' => null,
                    'status' => 'completed',
                ]);

                $formatted = $this->formatConfirmedGiftPayload($livestreamGift);

                // Broadcast real-time event
                try {
                    event(new LivestreamGiftSent(
                        streamId: (int) $callLog->id,
                        payload: $formatted,
                        conversationId: (int) $callLog->conversation_id
                    ));
                } catch (Throwable $e) {
                    Log::warning('LivestreamGiftSent broadcast error: '.$e->getMessage());
                }

                return [
                    'gift_log_id' => (int) $livestreamGift->id,
                    'payment_intent_id' => '',
                    'client_secret' => '',
                    'amount' => (float) $amount,
                    'currency' => 'usd',
                ];
            });
        }

        // Otherwise fallback to Stripe PaymentIntent creation
        $stripeSecret = config('services.stripe.secret') ?? 'sk_test_placeholder';
        $stripe = new StripeClient($stripeSecret);

        $amountInCents = (int) round($amount * 100);

        try {
            $paymentIntent = $stripe->paymentIntents->create([
                'amount' => $amountInCents,
                'currency' => 'usd',
                'metadata' => [
                    'stream_id' => (string) $callLog->id,
                    'sender_id' => (string) $sender->id,
                    'host_id' => (string) $host->id,
                    'gift_id' => (string) ($giftId ?? ''),
                    'gift_type' => (string) $giftType,
                    'message' => (string) ($messageText ?? ''),
                ],
                'description' => "Gift contribution of \${$amount} on Stream #{$callLog->id} by Sender #{$sender->id}",
            ]);
        } catch (Throwable $e) {
            Log::error('Stripe PaymentIntent creation failed: '.$e->getMessage());
            throw ValidationException::withMessages([
                'payment' => ['Stripe integration failed: '.$e->getMessage()],
            ]);
        }

        // Save pending gift log
        $livestreamGift = LivestreamGift::create([
            'call_log_id' => (int) $callLog->id,
            'sender_id' => (int) $sender->id,
            'gift_id' => $giftId,
            'gift_type' => $giftType,
            'amount' => $amount,
            'message' => $messageText,
            'sort_order' => $sortOrder,
            'stripe_payment_intent_id' => $paymentIntent->id,
            'status' => 'pending',
        ]);

        return [
            'gift_log_id' => (int) $livestreamGift->id,
            'payment_intent_id' => (string) $paymentIntent->id,
            'client_secret' => (string) $paymentIntent->client_secret,
            'amount' => (float) $amount,
            'currency' => 'usd',
        ];
    }

    /**
     * Confirm a livestream gift payment.
     *
     * @return array<string, mixed>
     */
    public function confirmStreamGiftPayment(User $user, string $paymentIntentId): array
    {
        $livestreamGift = LivestreamGift::where('stripe_payment_intent_id', $paymentIntentId)
            ->firstOrFail();

        if ($livestreamGift->status === 'completed') {
            return $this->formatConfirmedGiftPayload($livestreamGift);
        }

        if ($livestreamGift->status === 'failed') {
            throw ValidationException::withMessages([
                'payment' => ['This payment has already failed.'],
            ]);
        }

        // Verify status with Stripe API
        $stripeSecret = config('services.stripe.secret') ?? 'sk_test_placeholder';
        $stripe = new StripeClient($stripeSecret);

        try {
            $paymentIntent = $stripe->paymentIntents->retrieve($paymentIntentId);
        } catch (Throwable $e) {
            Log::error('Stripe PaymentIntent retrieve failed on confirmation: '.$e->getMessage());
            throw ValidationException::withMessages([
                'payment' => ['Failed to verify payment with Stripe.'],
            ]);
        }

        if ($paymentIntent->status !== 'succeeded') {
            throw ValidationException::withMessages([
                'payment' => ['Payment has not succeeded. Status: '.$paymentIntent->status],
            ]);
        }

        // Complete the transaction
        $callLog = CallLog::findOrFail($livestreamGift->call_log_id);
        $sender = User::findOrFail($livestreamGift->sender_id);
        $hostId = (int) $callLog->initiator_id;
        $hostProfile = Profile::where('user_id', $hostId)->firstOrFail();
        $senderProfile = Profile::where('user_id', (int) $sender->id)->firstOrFail();

        $recipientWallet = GiftWallet::firstOrCreate(
            ['user_id' => $hostProfile->id],
            [
                'balance' => 0.0,
                'total_deposited' => 0.0,
                'total_withdrawn' => 0.0,
                'total_gifted' => 0.0,
                'total_received' => 0.0,
            ]
        );

        $amount = (float) $livestreamGift->amount;

        $livestreamGift = DB::transaction(function () use ($livestreamGift, $senderProfile, $hostProfile, $recipientWallet, $amount, $paymentIntentId) {
            // Credit recipient wallet
            $recipientWallet->balance += $amount;
            $recipientWallet->total_received += $amount;
            $recipientWallet->save();

            // Log wallet transactions
            WalletTransaction::create([
                'user_id' => $senderProfile->id,
                'type' => 'gift_sent',
                'amount' => -$amount,
                'payment_method' => 'stripe',
                'stripe_payment_intent_id' => $paymentIntentId,
                'description' => $livestreamGift->message ?? 'Livestream gift sent via Stripe',
                'status' => 'completed',
                'recipient_id' => $hostProfile->id,
                'completed_at' => now(),
            ]);

            WalletTransaction::create([
                'wallet_id' => $recipientWallet->id,
                'user_id' => $hostProfile->id,
                'type' => 'gift_received',
                'amount' => $amount,
                'balance_after' => $recipientWallet->balance,
                'payment_method' => 'stripe',
                'stripe_payment_intent_id' => $paymentIntentId,
                'description' => $livestreamGift->message ?? 'Livestream gift received via Stripe',
                'status' => 'completed',
                'recipient_id' => $hostProfile->id,
                'completed_at' => now(),
            ]);

            // Update status
            $livestreamGift->update(['status' => 'completed']);

            return $livestreamGift;
        });

        $formatted = $this->formatConfirmedGiftPayload($livestreamGift);

        // Broadcast real-time event
        event(new LivestreamGiftSent(
            streamId: (int) $callLog->id,
            payload: $formatted,
            conversationId: (int) $callLog->conversation_id
        ));

        return $formatted;
    }

    /**
     * Format a confirmed gift model for output.
     */
    private function formatConfirmedGiftPayload(LivestreamGift $livestreamGift): array
    {
        $sender = User::findOrFail($livestreamGift->sender_id);
        $callLog = CallLog::findOrFail($livestreamGift->call_log_id);

        return [
            'gift_id' => (int) $livestreamGift->id,
            'livestream_id' => (int) $callLog->id,
            'sender' => [
                'id' => (int) $sender->id,
                'name' => $this->displayName($sender),
                'avatar' => $this->resolveUserAvatar($sender),
            ],
            'gift' => [
                'id' => $livestreamGift->gift_id,
                'type' => $livestreamGift->gift_type,
                'amount' => (float) $livestreamGift->amount,
                'message' => $livestreamGift->message,
                'sort_order' => (int) $livestreamGift->sort_order,
                'status' => $livestreamGift->status,
            ],
            'created_at' => $livestreamGift->created_at?->toIso8601String(),
        ];
    }

    /**
     * Get paginated gifts sent during a livestream.
     *
     * @return array<string, mixed>
     */
    public function getStreamGifts(User $user, int $streamId, int $page = 1, int $perPage = 10): array
    {
        $callLog = $this->loadCallOrFail($streamId);

        if ((string) $callLog->call_type !== 'stream') {
            throw ValidationException::withMessages([
                'stream' => ['Not a livestream.'],
            ]);
        }

        $query = LivestreamGift::query()
            ->where('call_log_id', (int) $callLog->id)
            ->with(['sender', 'gift'])
            ->orderByDesc('created_at');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $formattedGifts = collect($paginator->items())->map(function (LivestreamGift $gift): array {
            $senderName = $gift->sender instanceof User ? $this->displayName($gift->sender) : 'User';

            return [
                'id' => (int) $gift->id,
                'sender' => [
                    'id' => (int) $gift->sender_id,
                    'name' => $senderName,
                    'avatar' => $this->resolveUserAvatar($gift->sender),
                ],
                'gift_id' => $gift->gift_id !== null ? (int) $gift->gift_id : null,
                'gift_type' => $gift->gift_type,
                'amount' => (float) $gift->amount,
                'message' => $gift->message,
                'sort_order' => (int) $gift->sort_order,
                'created_at' => $gift->created_at?->diffForHumans() ?? 'just now',
            ];
        })->all();

        return [
            'livestream_id' => (int) $callLog->id,
            'gifts' => $formattedGifts,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * Get active livestream amount presets.
     */
    public function getAmountPresets(): Collection
    {
        return LivestreamAmountPreset::active()->get();
    }

    /**
     * Get the top 10 gift senders (leaderboard) for a livestream.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTopGifters(int $streamId): array
    {
        $callLog = $this->loadCallOrFail($streamId);

        if ((string) $callLog->call_type !== 'stream') {
            throw ValidationException::withMessages([
                'stream' => ['Not a livestream.'],
            ]);
        }

        $results = LivestreamGift::query()
            ->select('sender_id', DB::raw('SUM(amount) as total_amount'))
            ->where('call_log_id', (int) $callLog->id)
            ->where('status', 'completed')
            ->groupBy('sender_id')
            ->orderByDesc('total_amount')
            ->limit(10)
            ->with('sender')
            ->get();

        return $results->map(function ($row, $index): array {
            $senderName = $row->sender instanceof User ? $this->displayName($row->sender) : 'User';

            return [
                'rank' => $index + 1,
                'sender' => [
                    'id' => (int) $row->sender_id,
                    'name' => $senderName,
                    'avatar' => $this->resolveUserAvatar($row->sender),
                ],
                'total_amount' => (float) $row->total_amount,
            ];
        })->all();
    }

    /**
     * Get the host's products, services, jobs, and events for a livestream.
     *
     * @return array<string, mixed>
     */
    public function getHostItems(int $streamId): array
    {
        $callLog = $this->loadCallOrFail($streamId);

        if ((string) $callLog->call_type !== 'stream') {
            throw ValidationException::withMessages([
                'stream' => ['Not a livestream.'],
            ]);
        }

        $hostId = (int) $callLog->initiator_id;
        $hostProfile = Profile::where('user_id', $hostId)->first();

        if (! $hostProfile) {
            return [
                'products' => [],
                'services' => [],
                'jobs' => [],
                'events' => [],
            ];
        }

        $profileId = (int) $hostProfile->id;

        // Fetch Products
        $products = Product::where('seller_id', $profileId)
            ->active()
            ->get()
            ->map(function ($product): array {
                $imageUrl = $product->image_url ? (str_starts_with($product->image_url, 'http') ? $product->image_url : asset('storage/'.$product->image_url)) : null;

                return [
                    'id' => (int) $product->id,
                    'title' => (string) $product->title,
                    'price' => (float) $product->price,
                    'currency' => (string) ($product->currency ?? 'USD'),
                    'image_url' => $imageUrl,
                    'stock_quantity' => (int) $product->stock_quantity,
                ];
            })->all();

        // Fetch Services
        $services = Service::where('provider_id', $profileId)
            ->active()
            ->get()
            ->map(function ($service): array {
                $imageUrl = $service->image_url ? (str_starts_with($service->image_url, 'http') ? $service->image_url : asset('storage/'.$service->image_url)) : null;

                return [
                    'id' => (int) $service->id,
                    'title' => (string) $service->title,
                    'price' => (float) $service->price,
                    'currency' => (string) ($service->currency ?? 'USD'),
                    'image_url' => $imageUrl,
                ];
            })->all();

        // Fetch Jobs
        $jobs = JobListing::where('poster_id', $profileId)
            ->active()
            ->get()
            ->map(function ($job): array {
                return [
                    'id' => (int) $job->id,
                    'title' => (string) $job->title,
                    'company' => (string) $job->company,
                    'location' => (string) $job->location,
                    'salary_min' => (float) $job->salary_min,
                    'salary_max' => (float) $job->salary_max,
                    'job_type' => (string) $job->job_type,
                ];
            })->all();

        // Fetch Events
        $events = Event::where('organizer_id', $profileId)
            ->active()
            ->get()
            ->map(function ($event): array {
                $imageUrl = $event->image_url ? (str_starts_with($event->image_url, 'http') ? $event->image_url : asset('storage/'.$event->image_url)) : null;

                return [
                    'id' => (int) $event->id,
                    'title' => (string) $event->title,
                    'price' => (float) $event->price,
                    'currency' => (string) ($event->currency ?? 'USD'),
                    'image_url' => $imageUrl,
                    'event_date' => $event->event_date?->toIso8601String(),
                ];
            })->all();

        return [
            'products' => $products,
            'services' => $services,
            'jobs' => $jobs,
            'events' => $events,
        ];
    }

    /**
     * Feature or unfeature a specific item on a livestream.
     *
     * @return array<string, mixed>
     */
    public function featureItem(User $user, int $streamId, array $data): array
    {
        $callLog = $this->loadCallOrFail($streamId);

        if ((string) $callLog->call_type !== 'stream') {
            throw ValidationException::withMessages([
                'stream' => ['Not a livestream.'],
            ]);
        }

        // Verify the user is the host
        if ((int) $callLog->initiator_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'host' => ['Only the host can feature items.'],
            ]);
        }

        $hostProfile = Profile::where('user_id', $user->id)->first();
        if (! $hostProfile) {
            throw ValidationException::withMessages([
                'profile' => ['Host profile not found.'],
            ]);
        }

        $itemType = (string) $data['item_type'];
        $itemId = (int) $data['item_id'];
        $isFeatured = filter_var($data['is_featured'] ?? true, FILTER_VALIDATE_BOOLEAN);

        $profileId = (int) $hostProfile->id;

        // Verify ownership
        switch ($itemType) {
            case 'product':
                $exists = Product::where('id', $itemId)->where('seller_id', $profileId)->exists();
                break;
            case 'service':
                $exists = Service::where('id', $itemId)->where('provider_id', $profileId)->exists();
                break;
            case 'job':
                $exists = JobListing::where('id', $itemId)->where('poster_id', $profileId)->exists();
                break;
            case 'event':
                $exists = Event::where('id', $itemId)->where('organizer_id', $profileId)->exists();
                break;
            default:
                throw ValidationException::withMessages([
                    'item_type' => ['Invalid item type.'],
                ]);
        }

        if (! $exists) {
            throw ValidationException::withMessages([
                'item_id' => ['The selected item does not exist or does not belong to you.'],
            ]);
        }

        // Update or create the featured item record
        $record = LivestreamFeaturedItem::updateOrCreate(
            [
                'call_log_id' => (int) $callLog->id,
                'item_type' => $itemType,
                'item_id' => $itemId,
            ],
            [
                'is_featured' => $isFeatured,
            ]
        );

        return [
            'id' => (int) $record->id,
            'call_log_id' => (int) $record->call_log_id,
            'item_type' => (string) $record->item_type,
            'item_id' => (int) $record->item_id,
            'is_featured' => (bool) $record->is_featured,
        ];
    }

    /**
     * Get the featured products, services, jobs, and events for a livestream.
     *
     * @return array<string, mixed>
     */
    public function getFeaturedItems(int $streamId): array
    {
        $callLog = $this->loadCallOrFail($streamId);

        if ((string) $callLog->call_type !== 'stream') {
            throw ValidationException::withMessages([
                'stream' => ['Not a livestream.'],
            ]);
        }

        // Get all featured records where is_featured is true
        $featuredRecords = LivestreamFeaturedItem::where('call_log_id', (int) $callLog->id)
            ->where('is_featured', true)
            ->get();

        $productIds = $featuredRecords->where('item_type', 'product')->pluck('item_id')->all();
        $serviceIds = $featuredRecords->where('item_type', 'service')->pluck('item_id')->all();
        $jobIds = $featuredRecords->where('item_type', 'job')->pluck('item_id')->all();
        $eventIds = $featuredRecords->where('item_type', 'event')->pluck('item_id')->all();

        // Fetch Products
        $products = empty($productIds) ? [] : Product::whereIn('id', $productIds)
            ->active()
            ->get()
            ->map(function ($product): array {
                $imageUrl = $product->image_url ? (str_starts_with($product->image_url, 'http') ? $product->image_url : asset('storage/'.$product->image_url)) : null;

                return [
                    'id' => (int) $product->id,
                    'title' => (string) $product->title,
                    'price' => (float) $product->price,
                    'currency' => (string) ($product->currency ?? 'USD'),
                    'image_url' => $imageUrl,
                    'stock_quantity' => (int) $product->stock_quantity,
                ];
            })->all();

        // Fetch Services
        $services = empty($serviceIds) ? [] : Service::whereIn('id', $serviceIds)
            ->active()
            ->get()
            ->map(function ($service): array {
                $imageUrl = $service->image_url ? (str_starts_with($service->image_url, 'http') ? $service->image_url : asset('storage/'.$service->image_url)) : null;

                return [
                    'id' => (int) $service->id,
                    'title' => (string) $service->title,
                    'price' => (float) $service->price,
                    'currency' => (string) ($service->currency ?? 'USD'),
                    'image_url' => $imageUrl,
                ];
            })->all();

        // Fetch Jobs
        $jobs = empty($jobIds) ? [] : JobListing::whereIn('id', $jobIds)
            ->active()
            ->get()
            ->map(function ($job): array {
                return [
                    'id' => (int) $job->id,
                    'title' => (string) $job->title,
                    'company' => (string) $job->company,
                    'location' => (string) $job->location,
                    'salary_min' => (float) $job->salary_min,
                    'salary_max' => (float) $job->salary_max,
                    'job_type' => (string) $job->job_type,
                ];
            })->all();

        // Fetch Events
        $events = empty($eventIds) ? [] : Event::whereIn('id', $eventIds)
            ->active()
            ->get()
            ->map(function ($event): array {
                $imageUrl = $event->image_url ? (str_starts_with($event->image_url, 'http') ? $event->image_url : asset('storage/'.$event->image_url)) : null;

                return [
                    'id' => (int) $event->id,
                    'title' => (string) $event->title,
                    'price' => (float) $event->price,
                    'currency' => (string) ($event->currency ?? 'USD'),
                    'image_url' => $imageUrl,
                    'event_date' => $event->event_date?->toIso8601String(),
                ];
            })->all();

        return [
            'products' => $products,
            'services' => $services,
            'jobs' => $jobs,
            'events' => $events,
        ];
    }

    /**
     * Get questions for a livestream.
     * Returns custom questions if set, otherwise generates AI questions and saves them to the stream.
     *
     * @return array{source: string, count: int, questions: array<int, array{index: int, question: string, answer: string}>}
     */
    public function getLivestreamQuestions(int $streamId, int $quantity = 5, ?string $topic = null): array
    {
        $callLog = $this->loadCallOrFail($streamId);

        $customQuestions = is_array($callLog->custom_questions) ? $callLog->custom_questions : [];

        // If questions already exist on the stream, return them with indices
        if (! empty($customQuestions)) {
            $normalized = $this->aiQuestionService->normalizeQuestions($customQuestions);
            if (! empty($normalized)) {
                $withIndices = array_map(function ($item, $index) {
                    return [
                        'index' => (int) $index,
                        'question' => (string) $item['question'],
                        'answer' => (string) $item['answer'],
                    ];
                }, $normalized, array_keys($normalized));

                return [
                    'source' => 'custom',
                    'count' => count($withIndices),
                    'questions' => $withIndices,
                ];
            }
        }

        // If no questions exist, generate using AI and SAVE them to the livestream
        $aiQuestions = $this->aiQuestionService->generateQuestions($quantity, $topic);

        // Store into callLog so all viewers and the backend share the exact same questions & answers
        $callLog->custom_questions = $aiQuestions;
        $callLog->save();

        $withIndices = array_map(function ($item, $index) {
            return [
                'index' => (int) $index,
                'question' => (string) $item['question'],
                'answer' => (string) $item['answer'],
            ];
        }, $aiQuestions, array_keys($aiQuestions));

        return [
            'source' => 'ai',
            'count' => count($withIndices),
            'questions' => $withIndices,
        ];
    }

    /**
     * Generate AI questions on demand.
     *
     * @return array{source: string, count: int, questions: array<int, array{question: string, answer: string}>}
     */
    public function generateAiQuestions(int $quantity = 5, ?string $topic = null): array
    {
        $questions = $this->aiQuestionService->generateQuestions($quantity, $topic);

        return [
            'source' => 'ai',
            'count' => count($questions),
            'questions' => $questions,
        ];
    }

    /**
     * Simple Instant Game Bet API.
     * Evaluates answer against stored stream questions, calculates winnings/host share, and returns result.
     *
     * @param  array{question_index: int|string, selected_answer: string, amount: float|int|string}  $data
     * @return array<string, mixed>
     */
    public function placeGameBet(User $user, int $streamId, array $data): array
    {
        $callLog = $this->loadCallOrFail($streamId);

        if (! $callLog->allow_gift_bet) {
            throw ValidationException::withMessages([
                'allow_gift_bet' => ['Gamification gift betting is not enabled for this livestream.'],
            ]);
        }

        $amount = (float) ($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Bet amount must be greater than 0.'],
            ]);
        }

        $selectedAnswer = strtolower(trim((string) ($data['selected_answer'] ?? '')));
        if ($selectedAnswer !== 'true' && $selectedAnswer !== 'false') {
            throw ValidationException::withMessages([
                'selected_answer' => ['Selected answer must be either "true" or "false".'],
            ]);
        }

        $questionIndex = (int) ($data['question_index'] ?? 0);
        $questions = is_array($callLog->custom_questions) ? $callLog->custom_questions : [];

        // If stream questions don't exist yet, auto-generate and save them
        if (empty($questions)) {
            $questions = $this->aiQuestionService->generateQuestions(5);
            $callLog->custom_questions = $questions;
            $callLog->save();
        }

        if (! isset($questions[$questionIndex])) {
            throw ValidationException::withMessages([
                'question_index' => ["Question index {$questionIndex} does not exist on this livestream."],
            ]);
        }

        $currentQuestion = $questions[$questionIndex];
        $correctAnswer = strtolower(trim((string) ($currentQuestion['answer'] ?? 'true')));
        $questionText = (string) ($currentQuestion['question'] ?? 'Trivia Question');

        $isCorrect = ($selectedAnswer === $correctAnswer);
        $hostEarned = round($amount * 0.15, 2); // Host earns 15%
        $payoutAmount = $isCorrect ? round($amount + ($amount * 0.60), 2) : 0.00; // Winners share 60%
        $netProfit = $isCorrect ? round($amount * 0.60, 2) : -$amount;

        $resultData = [
            'is_correct' => $isCorrect,
            'question_index' => $questionIndex,
            'question' => $questionText,
            'selected_answer' => $selectedAnswer,
            'correct_answer' => $correctAnswer,
            'stake_amount' => (float) $amount,
            'your_profit' => (float) $netProfit,
            'host_earned' => (float) $hostEarned,
        ];

        return DB::transaction(function () use ($user, $callLog, $amount, $questionIndex, $questionText, $isCorrect, $netProfit, $hostEarned, $resultData): array {
            // 1. Resolve user profile and update user wallet
            $userProfileId = (int) ($user->profile?->id ?? Profile::where('user_id', $user->id)->value('id'));
            if ($userProfileId > 0) {
                $userWallet = GiftWallet::firstOrCreate(
                    ['user_id' => $userProfileId],
                    ['balance' => 0, 'total_deposited' => 0, 'total_withdrawn' => 0, 'total_gifted' => 0, 'total_received' => 0]
                );

                if ((float) $userWallet->balance < $amount) {
                    throw ValidationException::withMessages([
                        'amount' => ['Insufficient wallet balance.'],
                    ]);
                }

                if ($isCorrect) {
                    // Win: credit net profit to user wallet
                    $userWallet->balance += $netProfit;
                    $userWallet->total_received += $netProfit;
                    $userWallet->save();

                    WalletTransaction::create([
                        'wallet_id' => $userWallet->id,
                        'user_id' => $userProfileId,
                        'type' => 'gift_received',
                        'amount' => $netProfit,
                        'balance_after' => $userWallet->balance,
                        'reference' => 'BET-WIN-'.strtoupper(Str::random(10)),
                        'description' => "Won game bet on Stream #{$callLog->id} ({$questionText})",
                        'status' => 'completed',
                        'completed_at' => now(),
                    ]);
                } else {
                    // Lose: deduct stake from user wallet
                    $userWallet->balance -= $amount;
                    $userWallet->total_gifted += $amount;
                    $userWallet->save();

                    WalletTransaction::create([
                        'wallet_id' => $userWallet->id,
                        'user_id' => $userProfileId,
                        'type' => 'gift_sent',
                        'amount' => -$amount,
                        'balance_after' => $userWallet->balance,
                        'reference' => 'BET-LOSE-'.strtoupper(Str::random(10)),
                        'description' => "Lost game bet on Stream #{$callLog->id} ({$questionText})",
                        'status' => 'completed',
                        'completed_at' => now(),
                    ]);
                }
            }

            // 2. Credit host earnings (15%) to host wallet
            $hostId = (int) ($callLog->initiator_id ?: ($callLog->initiator?->id ?? 0));
            $hostProfileId = $hostId > 0 ? (int) Profile::where('user_id', $hostId)->value('id') : 0;

            if ($hostProfileId > 0 && $hostEarned > 0) {
                $hostWallet = GiftWallet::firstOrCreate(
                    ['user_id' => $hostProfileId],
                    ['balance' => 0, 'total_deposited' => 0, 'total_withdrawn' => 0, 'total_gifted' => 0, 'total_received' => 0]
                );
                $hostWallet->balance += $hostEarned;
                $hostWallet->total_received += $hostEarned;
                $hostWallet->save();

                WalletTransaction::create([
                    'wallet_id' => $hostWallet->id,
                    'user_id' => $hostProfileId,
                    'type' => 'gift_received',
                    'amount' => $hostEarned,
                    'balance_after' => $hostWallet->balance,
                    'reference' => 'HOST-EARN-'.strtoupper(Str::random(10)),
                    'description' => "Host earnings from game bet on Stream #{$callLog->id}",
                    'status' => 'completed',
                    'recipient_id' => $hostProfileId,
                    'completed_at' => now(),
                ]);
            }

            // 3. Broadcast host earnings event
            if ($hostId > 0 && $hostEarned > 0) {
                try {
                    event(new LivestreamHostEarningsUpdated(
                        hostId: $hostId,
                        payload: [
                            'livestream_id' => (int) $callLog->id,
                            'host_id' => $hostId,
                            'host_earned' => (float) $hostEarned,
                            'question_index' => (int) $questionIndex,
                            'question' => (string) $questionText,
                            'from_user_id' => (int) $user->id,
                            'from_user_name' => (string) ($user->name ?? 'Viewer'),
                            'message' => 'You earned '.number_format((float) $hostEarned, 2).' from bet',
                        ],
                        streamId: (int) $callLog->id,
                        conversationId: (int) ($callLog->conversation_id ?? 0)
                    ));
                } catch (Throwable $e) {
                    Log::warning('LivestreamHostEarningsUpdated broadcast error: '.$e->getMessage());
                }
            }

            return $resultData;
        });
    }
}
