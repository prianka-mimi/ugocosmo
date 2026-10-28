<?php

declare(strict_types=1);

namespace Modules\Communication\Services;

use Agence104\LiveKit\RoomServiceClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livekit\ParticipantPermission;
use Modules\Communication\Models\CallLog;
use Modules\Communication\Models\Conversation;

class CallMediaControlService
{
    /**
     * Revoke co-host publish permissions on media server (LiveKit/Agora).
     *
     * @return array<string, mixed>
     */
    public function revokeCoHostPublish(CallLog $callLog, int $userId): array
    {
        $engine = $this->resolvedEngine($callLog);
        $roomName = trim((string) ($callLog->room_name ?? ''));

        if ($roomName === '') {
            return $this->normalizeResponse('revoke_publish', $engine, false, false, 'room_name_missing');
        }

        if ($engine === 'livekit' || (string) $callLog->call_type === 'stream') {
            return $this->revokeLiveKitCoHostPublish($roomName, (string) $userId);
        }

        if ($engine === 'agora') {
            return $this->normalizeResponse('revoke_publish', 'agora', true, true);
        }

        return $this->normalizeResponse('revoke_publish', $engine, false, false, 'provider_not_supported');
    }

    /**
     * @return array<string, mixed>
     */
    public function setParticipantMediaMuted(CallLog $callLog, int $userId, string $mediaKind = 'audio', bool $muted = true): array
    {
        $engine = $this->resolvedEngine($callLog);
        $roomName = trim((string) ($callLog->room_name ?? ''));

        if ($roomName === '') {
            return $this->normalizeResponse('media_control', $engine, false, false, 'room_name_missing');
        }

        if ($engine === 'livekit') {
            return $this->muteLiveKitParticipantTracks($roomName, (string) $userId, $mediaKind, $muted);
        }

        if ($engine === 'agora') {
            return $this->normalizeResponse('media_control', 'agora', false, false, 'agora_server_mute_not_supported');
        }

        return $this->normalizeResponse('media_control', $engine, false, false, 'provider_does_not_support_server_mute');
    }

    /**
     * @return array<string, mixed>
     */
    public function forceRemoveParticipant(CallLog $callLog, int $userId): array
    {
        $engine = $this->resolvedEngine($callLog);
        $roomName = trim((string) ($callLog->room_name ?? ''));

        if ($roomName === '') {
            return $this->normalizeResponse('remove', $engine, false, false, 'room_name_missing');
        }

        if ($engine === 'livekit') {
            return $this->removeLiveKitParticipant($roomName, (string) $userId);
        }

        if ($engine === 'agora') {
            return $this->kickAgoraParticipant($roomName, (string) $userId);
        }

        return $this->normalizeResponse('remove', $engine, false, false, 'provider_does_not_support_server_eject');
    }

    /**
     * @param  array<int>  $participantIds
     * @return array<string, mixed>
     */
    public function endCallRoom(CallLog $callLog, array $participantIds): array
    {
        $engine = $this->resolvedEngine($callLog);
        $roomName = trim((string) ($callLog->room_name ?? ''));

        if ($roomName === '') {
            return $this->normalizeResponse('end_call', $engine, false, false, 'room_name_missing');
        }

        if ($engine === 'livekit') {
            return $this->deleteLiveKitRoom($roomName);
        }

        if ($engine === 'agora') {
            return $this->kickAgoraParticipants($roomName, $participantIds);
        }

        return $this->normalizeResponse('end_call', $engine, false, false, 'provider_does_not_support_server_end');
    }

    /**
     * @return array<string, mixed>
     */
    private function removeLiveKitParticipant(string $roomName, string $identity): array
    {
        $client = $this->liveKitRoomServiceClient();

        if (! $client instanceof RoomServiceClient) {
            return $this->normalizeResponse('remove', 'livekit', false, false, 'livekit_credentials_missing');
        }

        try {
            $client->removeParticipant($roomName, $identity);

            return $this->normalizeResponse('remove', 'livekit', true, true);
        } catch (\Throwable $exception) {
            Log::warning('LiveKit removeParticipant failed', [
                'room_name' => $roomName,
                'identity' => $identity,
                'error' => $exception->getMessage(),
            ]);

            return $this->normalizeResponse('remove', 'livekit', true, false, 'livekit_remove_failed');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function deleteLiveKitRoom(string $roomName): array
    {
        $client = $this->liveKitRoomServiceClient();

        if (! $client instanceof RoomServiceClient) {
            return $this->normalizeResponse('end_call', 'livekit', false, false, 'livekit_credentials_missing');
        }

        try {
            $client->deleteRoom($roomName);

            return $this->normalizeResponse('end_call', 'livekit', true, true);
        } catch (\Throwable $exception) {
            $message = strtolower((string) $exception->getMessage());

            if (str_contains($message, 'requested room does not exist')) {
                return $this->normalizeResponse('end_call', 'livekit', true, true, 'room_already_closed');
            }

            Log::warning('LiveKit deleteRoom failed', [
                'room_name' => $roomName,
                'error' => $exception->getMessage(),
            ]);

            return $this->normalizeResponse('end_call', 'livekit', true, false, 'livekit_delete_room_failed');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function muteLiveKitParticipantTracks(string $roomName, string $identity, string $mediaKind, bool $muted): array
    {
        $client = $this->liveKitRoomServiceClient();

        if (! $client instanceof RoomServiceClient) {
            return $this->normalizeResponse('media_control', 'livekit', false, false, 'livekit_credentials_missing');
        }

        try {
            $targetParticipant = $client->getParticipant($roomName, $identity);

            $allowedSources = match ($mediaKind) {
                'video' => ['CAMERA', 'SCREEN_SHARE', 'SCREEN_SHARE_AUDIO'],
                'both' => ['CAMERA', 'MICROPHONE', 'SCREEN_SHARE', 'SCREEN_SHARE_AUDIO'],
                default => ['MICROPHONE'],
            };

            $updatedTrackCount = 0;

            foreach ($targetParticipant->getTracks() as $trackInfo) {
                $source = strtoupper((string) ($trackInfo->getSource() ?? ''));
                if (! in_array($source, $allowedSources, true)) {
                    continue;
                }

                $trackSid = (string) $trackInfo->getSid();
                if ($trackSid === '') {
                    continue;
                }

                $client->mutePublishedTrack($roomName, $identity, $trackSid, $muted);
                $updatedTrackCount++;
            }

            return $this->normalizeResponse('media_control', 'livekit', true, true);
        } catch (\Throwable $exception) {
            $message = strtolower((string) $exception->getMessage());
            if (str_contains($message, 'does not exist') || str_contains($message, 'not found')) {
                return $this->normalizeResponse('media_control', 'livekit', true, true, 'participant_not_found_in_room');
            }

            Log::warning('LiveKit mutePublishedTrack failed', [
                'room_name' => $roomName,
                'identity' => $identity,
                'media_kind' => $mediaKind,
                'muted' => $muted,
                'error' => $exception->getMessage(),
            ]);

            return $this->normalizeResponse('media_control', 'livekit', true, false, 'livekit_mute_failed');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function kickAgoraParticipant(string $roomName, string $uid): array
    {
        if (! $this->agoraServerControlEnabled()) {
            return $this->normalizeResponse('remove', 'agora', false, false, 'agora_management_credentials_missing');
        }

        $response = Http::withBasicAuth(
            (string) config('communication.agora.customer_id', ''),
            (string) config('communication.agora.customer_secret', '')
        )
            ->timeout(8)
            ->post('https://api.agora.io/dev/v1/kicking-rule', [
                'appid' => (string) config('communication.agora.app_id', ''),
                'cname' => $roomName,
                'uid' => $uid,
                'time' => (int) config('communication.agora.kick_rule_seconds', 120),
            ]);

        if ($response->successful()) {
            return $this->normalizeResponse('remove', 'agora', true, true);
        }

        Log::warning('Agora kicking-rule request failed', [
            'room_name' => $roomName,
            'uid' => $uid,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return $this->normalizeResponse('remove', 'agora', true, false, 'agora_kick_rule_failed');
    }

    /**
     * @param  array<int>  $participantIds
     * @return array<string, mixed>
     */
    private function kickAgoraParticipants(string $roomName, array $participantIds): array
    {
        if ($participantIds === []) {
            return $this->normalizeResponse('end_call', 'agora', true, true);
        }

        collect($participantIds)
            ->unique()
            ->values()
            ->each(fn (int $userId): array => $this->kickAgoraParticipant($roomName, (string) $userId));

        return $this->normalizeResponse('end_call', 'agora', true, true);
    }

    private function agoraServerControlEnabled(): bool
    {
        $appId = trim((string) config('communication.agora.app_id', ''));
        $customerId = trim((string) config('communication.agora.customer_id', ''));
        $customerSecret = trim((string) config('communication.agora.customer_secret', ''));

        return $appId !== '' && $customerId !== '' && $customerSecret !== '';
    }

    private function resolvedEngine(CallLog $callLog): string
    {
        $engine = strtolower(trim((string) ($callLog->engine ?? '')));

        if ($engine !== '') {
            return $engine;
        }

        if ((string) $callLog->call_type === 'stream') {
            return 'livekit';
        }

        $conversation = $callLog->conversation;

        if (! $conversation instanceof Conversation) {
            return 'agora';
        }

        $scaleType = (bool) $conversation->is_group ? 'group' : 'one_to_one';
        $configKey = 'communication.call_engine.'.(string) $callLog->call_type.'_'.$scaleType;

        return strtolower((string) config($configKey, 'agora'));
    }

    /**
     * @return array<string, mixed>
     */
    private function revokeLiveKitCoHostPublish(string $roomName, string $identity): array
    {
        $client = $this->liveKitRoomServiceClient();

        if (! $client instanceof RoomServiceClient) {
            return $this->normalizeResponse('revoke_publish', 'livekit', false, false, 'livekit_credentials_missing');
        }

        $permissionUpdated = false;
        $tracksMuted = false;
        $participantRemoved = false;
        $errors = [];

        // Permission downgrade is the security-critical operation. If this fails,
        // an old publish-capable JWT may still be usable after reconnecting.
        try {
            $permission = (new ParticipantPermission)
                ->setCanSubscribe(true)
                ->setCanPublish(false)
                ->setCanPublishData(false);

            $client->updateParticipant(
                roomName: $roomName,
                identity: $identity,
                metadata: null,
                permission: $permission
            );
            $permissionUpdated = true;
            Log::info("LiveKit updateParticipant SUCCESS for {$identity} in {$roomName}");
        } catch (\Throwable $exception) {
            $msg = strtolower((string) $exception->getMessage());
            if (str_contains($msg, 'does not exist') || str_contains($msg, 'not found')) {
                $errors[] = 'participant_not_in_room';
            } else {
                $errors[] = 'permission_update_failed';
            }
            Log::warning("LiveKit updateParticipant FAILED for {$identity} in {$roomName}: ".$exception->getMessage());
        }

        try {
            $muteRes = $this->muteLiveKitParticipantTracks($roomName, $identity, 'both', true);
            $tracksMuted = (bool) ($muteRes['success'] ?? false);
            if (! $tracksMuted) {
                $errors[] = 'track_mute_failed';
            }
            Log::info("LiveKit muteTracks for {$identity} in {$roomName}: ".json_encode($muteRes));
        } catch (\Throwable $exception) {
            $errors[] = 'track_mute_failed';
            Log::warning("LiveKit muteTracks FAILED for {$identity} in {$roomName}: ".$exception->getMessage());
        }

        try {
            $client->removeParticipant($roomName, $identity);
            $participantRemoved = true;
            Log::info("LiveKit removeParticipant SUCCESS for {$identity} in {$roomName}");
        } catch (\Throwable $exception) {
            $msg = strtolower((string) $exception->getMessage());
            if (str_contains($msg, 'does not exist') || str_contains($msg, 'not found')) {
                $errors[] = 'participant_not_in_room';
            } else {
                $errors[] = 'participant_remove_failed';
            }
            Log::warning("LiveKit removeParticipant FAILED for {$identity} in {$roomName}: ".$exception->getMessage());
        }

        // If the participant is not actively in the room, they are already not publishing
        $notInRoom = in_array('participant_not_in_room', $errors, true);
        $success = ($permissionUpdated && $participantRemoved) || $notInRoom;

        return [
            'action' => 'revoke_publish',
            'provider' => 'livekit',
            'attempted' => true,
            'success' => $success,
            'permission_updated' => $permissionUpdated,
            'tracks_muted' => $tracksMuted,
            'participant_removed' => $participantRemoved,
            'errors' => array_values(array_unique($errors)),
            ...(! $success ? ['reason' => 'livekit_revoke_incomplete'] : []),
        ];
    }

    private function liveKitRoomServiceClient(): ?RoomServiceClient
    {
        $rawHost = trim((string) (config('communication.livekit.url') ?: env('LIVEKIT_URL', '')));
        $apiKey = trim((string) (config('communication.livekit.api_key') ?: env('LIVEKIT_API_KEY', '')));
        $apiSecret = trim((string) (config('communication.livekit.api_secret') ?: env('LIVEKIT_API_SECRET', '')));

        if ($rawHost === '' || $apiKey === '' || $apiSecret === '') {
            return null;
        }

        $host = $this->normalizeLiveKitHost($rawHost);

        return new RoomServiceClient($host, $apiKey, $apiSecret);
    }

    private function normalizeLiveKitHost(string $rawHost): string
    {
        $normalized = rtrim($rawHost, '/');

        if (str_starts_with($normalized, 'wss://')) {
            return 'https://'.substr($normalized, 6);
        }

        if (str_starts_with($normalized, 'ws://')) {
            return 'http://'.substr($normalized, 5);
        }

        return $normalized;
    }

    /**
     * Normalize media control response to a consistent structure across all providers.
     *
     * @param  string  $action  The action type: 'media_control', 'remove', or 'end_call'
     * @param  string  $provider  The provider: 'livekit', 'agora', etc.
     * @param  bool  $attempted  Whether the action was attempted
     * @param  bool  $success  Whether the action succeeded
     * @param  string|null  $reason  Optional failure reason code
     * @return array<string, mixed>
     */
    private function normalizeResponse(
        string $action,
        string $provider,
        bool $attempted,
        bool $success,
        ?string $reason = null
    ): array {
        $response = [
            'action' => $action,
            'provider' => $provider,
            'attempted' => $attempted,
            'success' => $success,
        ];

        if ($reason !== null && $reason !== '') {
            $response['reason'] = $reason;
        }

        return $response;
    }
}
