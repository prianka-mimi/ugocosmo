<?php

declare(strict_types=1);

namespace Modules\Communication\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth as FacadesAuth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Models\Conversation;
use Modules\Communication\Models\ConversationParticipant;
use Modules\Communication\Models\Message;

class GroupManagementService
{
    public function __construct(
        private readonly MessageService $messageService,
        private readonly GroupAvatarService $groupAvatarService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getGroupSettings(User $actor, int $conversationId): array
    {
        $this->loadConversation($conversationId);
        $this->activeParticipant($conversationId, (int) $actor->id);

        return $this->buildGroupSettingsSnapshot($conversationId, (int) $actor->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateNotificationPreference(User $actor, int $conversationId, bool $enabled): array
    {
        $this->loadConversation($conversationId);
        $actorParticipant = $this->activeParticipant($conversationId, (int) $actor->id);

        if ((bool) $actorParticipant->notification_enabled !== $enabled) {
            $actorParticipant->notification_enabled = $enabled;
            $actorParticipant->save();
        }

        return $this->buildGroupSettingsSnapshot($conversationId, (int) $actor->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateAudioCallPreference(User $actor, int $conversationId, bool $enabled): array
    {
        $this->loadConversation($conversationId);
        $actorParticipant = $this->activeParticipant($conversationId, (int) $actor->id);

        if ((bool) $actorParticipant->receive_audio_call_enabled !== $enabled) {
            $actorParticipant->receive_audio_call_enabled = $enabled;
            $actorParticipant->save();
        }

        return $this->buildGroupSettingsSnapshot($conversationId, (int) $actor->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateGroupName(User $actor, int $conversationId, string $groupName): array
    {
        $conversation = $this->loadGroupConversation($conversationId);
        // for now everryone can update group name, we can add admin only restriction later if needed
        // $actorParticipant = $this->activeParticipant($conversationId, (int) $actor->id);
        // $this->assertAdmin($actorParticipant);

        $name = trim($groupName);

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => ['Group name is required.'],
            ]);
        }

        $oldName = trim((string) ($conversation->name ?? ''));

        if ($oldName === $name) {
            return $this->buildGroupSettingsSnapshot($conversationId, (int) $actor->id);
        }

        $conversation->update(['name' => $name]);

        $actorName = $this->userDisplayName($actor);
        $this->sendSystemEvent(
            $actor,
            $conversationId,
            sprintf('%s changed group name to "%s"', $actorName, $name),
            [
                'event' => 'group_name_changed',
                'old_name' => $oldName,
                'new_name' => $name,
            ]
        );

        return $this->buildGroupSettingsSnapshot($conversationId, (int) $actor->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateGroupAvatar(User $actor, int $conversationId, ?string $avatar): array
    {
        $conversation = $this->loadGroupConversation($conversationId);
        // $actorParticipant = $this->activeParticipant($conversationId, (int) $actor->id);
        // $this->assertAdmin($actorParticipant);

        $oldAvatar = trim((string) ($conversation->avatar ?? ''));
        $newAvatar = trim((string) ($avatar ?? ''));

        if ($newAvatar === '') {
            $newAvatar = (string) ($this->groupAvatarService->generateConversationAvatar(
                $conversationId,
                $this->activeParticipantIds($conversationId)
            ) ?? '');
        }

        $conversation->update([
            'avatar' => $newAvatar !== '' ? $newAvatar : null,
        ]);

        $actorName = $this->userDisplayName($actor);
        $this->sendSystemEvent(
            $actor,
            $conversationId,
            sprintf('%s updated the group avatar', $actorName),
            [
                'event' => 'group_avatar_changed',
                'old_avatar' => $oldAvatar !== '' ? $oldAvatar : null,
                'new_avatar' => $newAvatar !== '' ? $newAvatar : null,
            ]
        );

        return $this->buildGroupSettingsSnapshot($conversationId, (int) $actor->id);
    }

    /**
     * @param  array<int>  $memberIds
     * @return array<string, mixed>
     */
    public function addMembers(User $actor, int $conversationId, array $memberIds): array
    {
        $this->loadGroupConversation($conversationId);
        // $actorParticipant = $this->activeParticipant($conversationId, (int) $actor->id);
        // $this->assertAdmin($actorParticipant);

        $candidateMemberIds = collect($memberIds)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->reject(fn (int $id): bool => $id === (int) $actor->id)
            ->unique()
            ->values()
            ->all();

        if ($candidateMemberIds === []) {
            throw ValidationException::withMessages([
                'participant_ids' => ['At least one valid participant id is required.'],
            ]);
        }

        $users = User::query()
            ->whereIn('id', $candidateMemberIds)
            ->get(['id', 'name', 'avatar'])
            ->keyBy('id');

        $existingParticipants = ConversationParticipant::query()
            ->where('conversation_id', $conversationId)
            ->whereIn('user_id', $candidateMemberIds)
            ->get()
            ->keyBy('user_id');

        $addedUserIds = [];

        DB::transaction(function () use ($conversationId, $candidateMemberIds, $existingParticipants, &$addedUserIds): void {
            $rowsToInsert = [];

            foreach ($candidateMemberIds as $memberId) {
                $existing = $existingParticipants->get($memberId);

                if ($existing instanceof ConversationParticipant) {
                    if ((string) $existing->status === 'active') {
                        continue;
                    }

                    $existing->status = 'active';
                    $existing->role = 'member';
                    $existing->notification_enabled = true;
                    $existing->receive_audio_call_enabled = true;
                    $existing->joined_at = now();
                    $existing->left_at = null;
                    $existing->save();

                    $addedUserIds[] = $memberId;

                    continue;
                }

                $rowsToInsert[] = [
                    'conversation_id' => $conversationId,
                    'user_id' => $memberId,
                    'role' => 'member',
                    'status' => 'active',
                    'notification_enabled' => true,
                    'receive_audio_call_enabled' => true,
                    'joined_at' => now(),
                    'left_at' => null,
                ];

                $addedUserIds[] = $memberId;
            }

            if ($rowsToInsert !== []) {
                ConversationParticipant::query()->insert($rowsToInsert);
            }
        });

        if ($addedUserIds === []) {
            return $this->buildGroupSettingsSnapshot($conversationId, (int) $actor->id);
        }

        $addedNames = collect($addedUserIds)
            ->map(fn (int $userId): string => $this->userDisplayName($users->get($userId)))
            ->filter()
            ->values()
            ->all();

        $actorName = $this->userDisplayName($actor);
        $this->sendSystemEvent(
            $actor,
            $conversationId,
            sprintf('%s added %s to the group', $actorName, implode(', ', $addedNames)),
            [
                'event' => 'members_added',
                'member_ids' => $addedUserIds,
                'member_names' => $addedNames,
            ]
        );

        $this->refreshGeneratedAvatarIfNeeded($conversationId);

        return $this->buildGroupSettingsSnapshot($conversationId, (int) $actor->id);
    }

    /**
     * @param  array<int>  $memberIds
     * @return array<string, mixed>
     */
    public function promoteMembersToAdmin(User $actor, int $conversationId, array $memberIds): array
    {
        $this->loadGroupConversation($conversationId);
        $actorParticipant = $this->activeParticipant($conversationId, (int) $actor->id);
        $this->assertAdmin($actorParticipant);

        $requestedMemberIds = collect($memberIds)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->reject(fn (int $id): bool => $id === (int) $actor->id)
            ->unique()
            ->values()
            ->all();

        if ($requestedMemberIds === []) {
            throw ValidationException::withMessages([
                'participant_ids' => ['At least one valid participant id is required.'],
            ]);
        }

        $activeParticipants = ConversationParticipant::query()
            ->where('conversation_id', $conversationId)
            ->whereIn('user_id', $requestedMemberIds)
            ->where('status', 'active')
            ->get(['id', 'user_id', 'role'])
            ->keyBy('user_id');

        if ($activeParticipants->isEmpty()) {
            throw ValidationException::withMessages([
                'participant_ids' => ['Selected participants are not active members of this group.'],
            ]);
        }

        $promotedIds = [];

        foreach ($activeParticipants as $participant) {
            if ((string) $participant->role === 'admin') {
                continue;
            }

            $participant->role = 'admin';
            $participant->save();
            $promotedIds[] = (int) $participant->user_id;
        }

        if ($promotedIds === []) {
            throw ValidationException::withMessages([
                'participant_ids' => ['Selected participants are already admins.'],
            ]);
        }

        $users = User::query()
            ->whereIn('id', $promotedIds)
            ->get(['id', 'name', 'avatar'])
            ->keyBy('id');

        $promotedNames = collect($promotedIds)
            ->map(fn (int $memberId): string => $this->userDisplayName($users->get($memberId)))
            ->filter()
            ->values()
            ->all();

        $actorName = $this->userDisplayName($actor);
        $this->sendSystemEvent(
            $actor,
            $conversationId,
            sprintf('%s promoted %s to admin', $actorName, implode(', ', $promotedNames)),
            [
                'event' => 'members_promoted_to_admin',
                'member_ids' => $promotedIds,
                'member_names' => $promotedNames,
            ]
        );

        return $this->buildGroupSettingsSnapshot($conversationId, (int) $actor->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeMembers(User $actor, int $conversationId, array $memberIds): array
    {
        $this->loadGroupConversation($conversationId);
        $actorParticipant = $this->activeParticipant($conversationId, (int) $actor->id);
        $this->assertAdmin($actorParticipant);

        $requestedMemberIds = collect($memberIds)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($requestedMemberIds === []) {
            throw ValidationException::withMessages([
                'participant_ids' => ['At least one valid participant id is required.'],
            ]);
        }

        if (in_array((int) $actor->id, $requestedMemberIds, true)) {
            throw ValidationException::withMessages([
                'participant_ids' => ['Use leave group action instead of removing yourself.'],
            ]);
        }

        $activeParticipants = ConversationParticipant::query()
            ->where('conversation_id', $conversationId)
            ->whereIn('user_id', $requestedMemberIds)
            ->where('status', 'active')
            ->get(['id', 'user_id', 'role']);

        if ($activeParticipants->isEmpty()) {
            throw ValidationException::withMessages([
                'participant_ids' => ['Selected participants are not active members of this group.'],
            ]);
        }

        $adminParticipantIds = $activeParticipants
            ->filter(fn (ConversationParticipant $participant): bool => (string) $participant->role === 'admin')
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        if ($adminParticipantIds !== []) {
            throw ValidationException::withMessages([
                'participant_ids' => ['Cannot remove admin.'],
            ]);
        }

        $removedMemberIds = $activeParticipants
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        ConversationParticipant::query()
            ->where('conversation_id', $conversationId)
            ->whereIn('user_id', $removedMemberIds)
            ->update([
                'status' => 'kicked',
                'left_at' => now(),
            ]);

        $users = User::query()
            ->whereIn('id', $removedMemberIds)
            ->get(['id', 'name', 'avatar'])
            ->keyBy('id');

        $removedNames = collect($removedMemberIds)
            ->map(fn (int $memberId): string => $this->userDisplayName($users->get($memberId)))
            ->filter()
            ->values()
            ->all();

        $actorName = $this->userDisplayName($actor);
        $this->sendSystemEvent(
            $actor,
            $conversationId,
            sprintf('%s removed %s from the group', $actorName, implode(', ', $removedNames)),
            [
                'event' => 'members_kicked',
                'member_ids' => $removedMemberIds,
                'member_names' => $removedNames,
            ]
        );

        $this->refreshGeneratedAvatarIfNeeded($conversationId);

        return $this->buildGroupSettingsSnapshot($conversationId, (int) $actor->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function clearChatHistory(User $actor, int $conversationId): array
    {
        $this->loadGroupConversation($conversationId);
        $actorParticipant = $this->activeParticipant($conversationId, (int) $actor->id);

        $actorParticipant->history_cleared_message_id = $this->latestConversationMessageId($conversationId);
        $actorParticipant->save();

        return $this->buildGroupSettingsSnapshot($conversationId, (int) $actor->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function clearPrivateChatHistory(User $actor, int $conversationId): array
    {
        $conversation = $this->loadConversation($conversationId);
        $actorParticipant = $this->activeParticipant($conversationId, (int) $actor->id);

        if ((bool) $conversation->is_group) {
            throw ValidationException::withMessages([
                'conversation_id' => ['Use group history clear action for group chats.'],
            ]);
        }

        $actorParticipant->history_cleared_message_id = $this->latestConversationMessageId($conversationId);
        $actorParticipant->save();

        return [
            'conversation_id' => (int) $conversation->id,
            'is_group' => false,
            'cleared_from_my_side' => true,
            'history_cleared_message_id' => $actorParticipant->history_cleared_message_id,
        ];
    }

    public function clearConversationHistory(User $actor, int $conversationId): array
    {
        $conversation = $this->loadConversation($conversationId);

        if ((bool) $conversation->is_group) {
            return $this->clearChatHistory($actor, $conversationId);
        }

        return $this->clearPrivateChatHistory($actor, $conversationId);
    }

    /**
     * @return array<string, mixed>
     */
    public function leaveGroup(User $actor, int $conversationId): array
    {
        $this->loadGroupConversation($conversationId);

        $actorParticipant = $this->activeParticipant($conversationId, (int) $actor->id);
        $wasAdmin = (string) $actorParticipant->role === 'admin';

        if ($wasAdmin) {
            $this->ensureOtherAdminExistsIfNeeded($conversationId, $actorParticipant, 'leave the group');
        }

        $actorName = $this->userDisplayName($actor);
        $this->sendSystemEvent(
            $actor,
            $conversationId,
            sprintf('%s left the group', $actorName),
            [
                'event' => 'member_left',
                'member_id' => (int) $actor->id,
            ]
        );

        $actorParticipant->status = 'left';
        $actorParticipant->left_at = now();
        $actorParticipant->save();

        $transferredAdminTo = null;

        $remainingActive = ConversationParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('status', 'active')
            ->count();

        if ($remainingActive === 0) {
            Conversation::query()->whereKey($conversationId)->delete();

            return [
                'conversation_id' => $conversationId,
                'left' => true,
                'conversation_deleted' => true,
            ];
        }

        $this->refreshGeneratedAvatarIfNeeded($conversationId);

        $snapshot = $this->buildGroupSettingsSnapshot($conversationId, (int) $actor->id);
        $snapshot['left'] = true;
        $snapshot['transferred_admin_to'] = $transferredAdminTo;

        return $snapshot;
    }

    public function deleteGroup(User $actor, int $conversationId): void
    {
        $this->loadGroupConversation($conversationId);
        $actorParticipant = $this->activeParticipant($conversationId, (int) $actor->id);
        $this->assertAdmin($actorParticipant);
        $this->ensureOtherAdminExistsIfNeeded($conversationId, $actorParticipant, 'delete the group');

        $actorName = $this->userDisplayName($actor);
        $this->sendSystemEvent(
            $actor,
            $conversationId,
            sprintf('%s deleted the group', $actorName),
            [
                'event' => 'group_deleted',
            ]
        );

        Conversation::query()->whereKey($conversationId)->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteConversation(User $actor, int $conversationId): array
    {
        $conversation = $this->loadConversation($conversationId);
        $actorParticipant = $this->activeParticipant($conversationId, (int) $actor->id);

        $actorParticipant->history_cleared_message_id = $this->latestConversationMessageId($conversationId);
        $actorParticipant->deleted_from_list_at = now();
        $actorParticipant->save();

        return [
            'conversation_id' => $conversationId,
            'is_group' => (bool) $conversation->is_group,
            'deleted_for_me' => true,
            'deleted_from_list_at' => $actorParticipant->deleted_from_list_at,
            'history_cleared_message_id' => $actorParticipant->history_cleared_message_id,
        ];
    }

    private function sendSystemEvent(User $actor, int $conversationId, string $content, array $metadata): void
    {
        $metadata['actor'] = [
            'id' => (int) $actor->id,
            'name' => $this->userDisplayName($actor),
            'avatar' => $actor->avatar,
        ];

        $this->messageService->sendMessage($actor, $conversationId, [
            'type' => 'system_chat_event',
            'content' => $content,
            'metadata' => $metadata,
            'attachments' => [],
        ]);
    }

    private function loadConversation(int $conversationId): Conversation
    {
        $conversation = Conversation::query()->find($conversationId);

        if (! $conversation instanceof Conversation) {
            throw (new ModelNotFoundException)->setModel(Conversation::class, [$conversationId]);
        }

        return $conversation;
    }

    private function loadGroupConversation(int $conversationId): Conversation
    {
        $conversation = $this->loadConversation($conversationId);

        if (! (bool) $conversation->is_group) {
            throw ValidationException::withMessages([
                'conversation_id' => ['This action is available only for group chats.'],
            ]);
        }

        return $conversation;
    }

    private function activeParticipant(int $conversationId, int $userId): ConversationParticipant
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

    private function assertAdmin(ConversationParticipant $participant): void
    {
        if ((string) $participant->role !== 'admin') {
            throw new AuthorizationException('Only group admin can perform this action.');
        }
    }

    /**
     * @return array<int>
     */
    private function activeParticipantIds(int $conversationId): array
    {
        return ConversationParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('status', 'active')
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function refreshGeneratedAvatarIfNeeded(int $conversationId): void
    {
        $conversation = Conversation::query()->find($conversationId);

        if (! $conversation instanceof Conversation) {
            return;
        }

        $currentAvatar = trim((string) ($conversation->avatar ?? ''));
        $isGeneratedAvatar = str_contains($currentAvatar, '/communication/conversations/avatars/group_');

        if ($currentAvatar !== '' && ! $isGeneratedAvatar) {
            return;
        }

        $avatarUrl = $this->groupAvatarService->generateConversationAvatar($conversationId, $this->activeParticipantIds($conversationId));

        if ($avatarUrl === null) {
            return;
        }

        $conversation->update(['avatar' => $avatarUrl]);
    }

    private function transferAdminIfNeeded(int $conversationId): ?int
    {
        $alreadyHasAdmin = ConversationParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('status', 'active')
            ->where('role', 'admin')
            ->exists();

        if ($alreadyHasAdmin) {
            return null;
        }

        $nextAdmin = ConversationParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('status', 'active')
            ->orderBy('joined_at')
            ->orderBy('id')
            ->first();

        if (! $nextAdmin instanceof ConversationParticipant) {
            return null;
        }

        $nextAdmin->role = 'admin';
        $nextAdmin->save();

        return (int) $nextAdmin->user_id;
    }

    private function ensureOtherAdminExistsIfNeeded(int $conversationId, ConversationParticipant $actorParticipant, string $action): void
    {
        $otherActiveAdmins = ConversationParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('status', 'active')
            ->where('user_id', '!=', $actorParticipant->user_id)
            ->where('role', 'admin')
            ->count();

        if ($otherActiveAdmins > 0) {
            return;
        }

        $otherActiveMembers = ConversationParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('status', 'active')
            ->where('user_id', '!=', $actorParticipant->user_id)
            ->with('user:id,name')
            ->orderBy('joined_at')
            ->get();

        if ($otherActiveMembers->isEmpty()) {
            return;
        }

        $availableNames = $otherActiveMembers
            ->map(fn (ConversationParticipant $participant): string => $this->userDisplayName($participant->user))
            ->filter()
            ->values()
            ->all();

        throw ValidationException::withMessages([
            'conversation_id' => [
                'You are the only admin of this group. Please promote another member to admin before you can '.$action.'.',
            ],
        ]);
    }

    private function latestConversationMessageId(int $conversationId): ?string
    {
        $messageId = Message::query()
            ->where('conversation_id', $conversationId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('id');

        return $messageId !== null ? (string) $messageId : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildGroupSnapshot(int $conversationId): array
    {
        $conversation = Conversation::query()->findOrFail($conversationId);

        $totalParticipants = ConversationParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('status', 'active')
            ->count();

        return [
            'conversation_id' => (int) $conversation->id,
            'is_group' => (bool) $conversation->is_group,
            'name' => (string) ($conversation->name ?? ''),
            'avatar' => $conversation->avatar,
            'total_participants' => $totalParticipants,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildGroupSettingsSnapshot(int $conversationId, int $viewerId): array
    {
        $conversation = Conversation::query()
            ->whereKey($conversationId)
            ->with([
                'participants' => function ($query) use ($viewerId): void {
                    $query
                        ->where(function ($query): void {
                            $query
                                ->where('status', 'active');
                        })
                        ->orWhere('user_id', $viewerId)
                        ->select([
                            'id',
                            'conversation_id',
                            'user_id',
                            'role',
                            'status',
                            'notification_enabled',
                            'receive_audio_call_enabled',
                            'joined_at',
                            'left_at',
                        ])
                        ->orderByRaw("CASE WHEN role = 'admin' THEN 0 ELSE 1 END")
                        ->orderBy('joined_at')
                        ->orderBy('id');
                },
                'participants.user:id,name,avatar,blocked_users',
            ])
            ->firstOrFail();

        $viewer = User::query()->find($viewerId);

        $viewerParticipant = $conversation->participants->first(
            fn (ConversationParticipant $participant): bool => (int) $participant->user_id === $viewerId
        );

        if (! $viewerParticipant instanceof ConversationParticipant) {
            throw new AuthorizationException('Conversation not found.');
        }

        $isGroup = (bool) $conversation->is_group;
        $isAdmin = (string) $viewerParticipant->role === 'admin';

        $activeParticipants = $conversation->participants
            ->filter(fn (ConversationParticipant $participant): bool => (string) $participant->status === 'active');

        $otherUsers = $activeParticipants
            ->filter(fn (ConversationParticipant $participant): bool => (int) $participant->user_id !== $viewerId)
            ->map(fn (ConversationParticipant $participant) => $participant->user)
            ->filter(fn ($user): bool => $user instanceof User)
            ->values();

        $isBlocked = false;

        if ($viewer instanceof User) {
            $isBlocked = $otherUsers->contains(fn (User $user): bool => $user->hasBlocked($viewerId))
              || $otherUsers->contains(fn (User $user): bool => $viewer->hasBlocked((int) $user->id));
        }

        $otherParticipant = $activeParticipants->first(
            fn (ConversationParticipant $participant): bool => (int) $participant->user_id !== $viewerId
        );

        $displayName = $isGroup
          ? ((string) ($conversation->name ?? ('Group #'.(int) $conversation->id)))
          : $this->userDisplayName($otherParticipant?->user);

        $displayAvatar = $isGroup
          ? $conversation->avatar
          : $otherParticipant?->user?->avatar;

        $members = $activeParticipants
            ->map(function (ConversationParticipant $participant): array {
                return [
                    'user_id' => (int) $participant->user_id,
                    'name' => $this->userDisplayName($participant->user),
                    'avatar' => $participant->user?->avatar,
                    'role' => (string) $participant->role,
                    'is_me' => (int) $participant->user_id === FacadesAuth::id(),
                ];
            })
            ->values()
            ->all();

        return [
            'conversation_id' => (int) $conversation->id,
            'is_group' => $isGroup,
            'name' => $displayName,
            'avatar' => $displayAvatar,
            'total_participants' => $activeParticipants->count(),
            'notification_enabled' => (bool) $viewerParticipant->notification_enabled,
            'receive_audio_call_enabled' => (bool) $viewerParticipant->receive_audio_call_enabled,
            'role' => (string) $viewerParticipant->role,
            'is_blocked' => $isBlocked,
            'members' => $members,
        ];
    }

    private function userDisplayName(?User $user): string
    {
        if ($user === null) {
            return 'Unknown';
        }

        $name = trim((string) ($user->name ?? ''));

        if ($name !== '') {
            return $name;
        }

        return 'User '.(int) $user->id;
    }
}
