<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Communication\Helpers\TimeHelper;
use Modules\Communication\Models\Message;

class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $authUserId = (int) ($request->user()?->id ?? 0);

        $participants = $this->relationLoaded('participants')
          ? $this->participants
          : collect();

        $otherParticipant = $participants->first(
            fn ($participant): bool => (int) $participant->user_id !== $authUserId
        );

        $otherUser = $otherParticipant?->user;

        $displayName = $this->is_group
          ? ((string) ($this->name ?? ('Group #'.$this->id)))
          : $this->displayUserName($otherUser?->name, $otherUser?->id);

        $rawAvatar = $this->is_group
          ? $this->avatar
          : ($otherUser?->avatar ? asset('storage/'.$otherUser->avatar) : null);

        $displayAvatar = $rawAvatar ?? 'https://ui-avatars.com/api/?name='.urlencode($displayName).'&background=random&size=256';

        $lastMessage = $this->relationLoaded('latestMessage') ? $this->latestMessage : null;

        $displayTimestamp = $lastMessage?->created_at?->toISOString() ?? $this->updated_at?->toISOString() ?? now()->toISOString();

        return [
            'conversation_id' => (int) $this->id,
            // 'is_group' => (bool) $this->is_group,
            // 'is_blocked' => $this->resolveBlockedStatus($request, $otherUser),
            'display_info' => [
                'other_user_id' => (int) $otherUser?->id,
                'name' => $displayName,
                'avatar' => $displayAvatar,
                'display_time' => TimeHelper::formatChatTimestamp($displayTimestamp),
                'is_online' => $this->resolveOnlineStatus($participants, $authUserId, (int) ($otherUser?->id ?? 0)),
                // 'total_participants' => (int) ($this->total_participants ?? $participants->count()),
            ],
            'unread_count' => (int) ($this->unread_count ?? 0),
            'last_message' => $lastMessage !== null ? [
                // 'id' => (string) $lastMessage->id,
                // 'sender_id' => (int) $lastMessage->sender_id,
                // 'type' => (string) $lastMessage->type,
                // 'content' => $lastMessage->content,
                // 'attachments' => $lastMessage->attachments,
                // 'metadata' => $lastMessage->metadata,
                // 'status' => (string) $lastMessage->status,
                // 'note'   => '::::::: status can be one of sent, delivered, read. It represents the status for the current user. :::::: type can be one fo text, attachment, system_call_event(if user is calling), system_chat_event(if user is invited to a group) :::::::',
                // 'reactions_summary' => $lastMessage->reactions_summary ?? [],
                // 'created_at' => $lastMessage->created_at?->toISOString(),

                // if wave sent user sent a wave message , if attachment then say user sent an attchment and if text then show the text
                'content' => match ((string) $lastMessage->type) {
                    'wave' => 'Sent a wave',
                    'attachment' => 'Sent an attachment',
                    'system_call_event' => $this->formatCallEventMessage($lastMessage),
                    'system_chat_event' => $lastMessage->content,
                    default => $lastMessage->content,
                },
            ] : null,
        ];
    }

    private function displayUserName(?string $name, ?int $fallbackId): string
    {
        $fullName = trim((string) $name);

        if ($fullName !== '') {
            return $fullName;
        }

        return 'User '.(int) $fallbackId;
    }

    private function resolveBlockedStatus(Request $request, mixed $otherUser): bool
    {
        if ((bool) $this->is_group || ! $otherUser instanceof User) {
            return false;
        }

        $currentUser = $request->user();
        if (! $currentUser instanceof User) {
            return false;
        }

        return $currentUser->hasBlocked((int) $otherUser->id)
          || $otherUser->hasBlocked((int) $currentUser->id);
    }

    private function resolveOnlineStatus(Collection $participants, int $authUserId, int $otherUserId): bool
    {
        if (! (bool) $this->is_group) {
            return $otherUserId > 0 && Cache::has($this->onlineCacheKey($otherUserId));
        }

        foreach ($participants as $participant) {
            $participantUserId = (int) ($participant->user_id ?? 0);

            if ($participantUserId === 0 || $participantUserId === $authUserId) {
                continue;
            }

            if (Cache::has($this->onlineCacheKey($participantUserId))) {
                return true;
            }
        }

        return false;
    }

    private function onlineCacheKey(int $userId): string
    {
        return 'communication:user:online:'.$userId;
    }

    private function formatCallEventMessage(mixed $message): string
    {
        if (! $message instanceof Message) {
            return $message?->content ?? 'Call event';
        }

        return (string) ($message->content ?? 'Call event');
    }
}
