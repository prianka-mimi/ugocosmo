<?php

declare(strict_types=1);

namespace Modules\Communication\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Communication\Models\Conversation;
use Modules\Communication\Models\ConversationParticipant;
use Modules\Communication\Models\Message;
use Modules\Communication\Models\MessageUserDetail;
use Modules\Communication\Repositories\Contracts\MessageRepositoryInterface;

class MariaDbMessageRepository implements MessageRepositoryInterface
{
    public function paginateConversationsForUser(int $userId, int $perPage = 20, ?string $search = null): LengthAwarePaginator
    {
        $query = Conversation::query()
            ->whereHas('participants', function ($query) use ($userId): void {
                $query
                    ->where('user_id', $userId)
                    ->where('status', 'active')
                    ->whereNull('deleted_from_list_at');
            })
            ->whereHas('messages', function ($query) use ($userId): void {
                $query->whereRaw(
                    "communication_messages.id > COALESCE((SELECT cp.history_cleared_message_id FROM conversation_participants cp WHERE cp.conversation_id = communication_messages.conversation_id AND cp.user_id = ? AND cp.status = 'active' LIMIT 1), '')",
                    [$userId]
                );
            })
            ->with([
                'participants' => function ($query): void {
                    $query->where('status', 'active')
                        ->select([
                            'id',
                            'conversation_id',
                            'user_id',
                            'role',
                            'status',
                            'notification_enabled',
                            'receive_audio_call_enabled',
                            'history_cleared_message_id',
                            'last_msg_delivery_id',
                            'last_msg_delivery_at',
                            'last_msg_read_id',
                            'last_msg_read_at',
                            'joined_at',
                            'left_at',
                        ]);
                },
                'latestMessage' => function ($query) use ($userId): void {
                    $query->whereRaw(
                        "communication_messages.id > COALESCE((SELECT cp.history_cleared_message_id FROM conversation_participants cp WHERE cp.conversation_id = communication_messages.conversation_id AND cp.user_id = ? AND cp.status = 'active' LIMIT 1), '')",
                        [$userId]
                    );
                },
                'participants.user' => function ($query) {
                    $query->select(['id', 'name', 'avatar']);
                },
                'latestMessage.sender:id,name,avatar',
            ])
            ->withCount([
                'participants as total_participants' => function ($query): void {
                    $query->where('status', 'active');
                },
                'messages as unread_count' => function ($query) use ($userId): void {
                    $query
                        ->whereRaw(
                            "communication_messages.id > COALESCE((SELECT cp.history_cleared_message_id FROM conversation_participants cp WHERE cp.conversation_id = communication_messages.conversation_id AND cp.user_id = ? AND cp.status = 'active' LIMIT 1), '')",
                            [$userId]
                        )
                        ->where('sender_id', '!=', $userId)
                        ->whereDoesntHave('userDetails', function ($receiptQuery) use ($userId): void {
                            $receiptQuery->where('user_id', $userId)->whereNotNull('read_at');
                        });
                },
        ])
            ->addSelect([
                'last_message_created_at' => Message::query()
                    ->select('created_at')
                    ->whereColumn('conversation_id', 'communication_conversations.id')
                    ->whereRaw(
                        "communication_messages.id > COALESCE((SELECT cp.history_cleared_message_id FROM conversation_participants cp WHERE cp.conversation_id = communication_conversations.id AND cp.user_id = ? AND cp.status = 'active' LIMIT 1), '')",
                        [$userId]
                    )
                    ->latest('created_at')
                    ->limit(1),
        ]);

        // Apply name search: match the other participant's name (private chats) OR the group name.
        if ($search !== null && $search !== '') {
            $like = '%'.$search.'%';

            $query->where(function ($q) use ($userId, $like): void {
                // Match group name
                $q->where(function ($groupQ) use ($like): void {
                    $groupQ->where('communication_conversations.is_group', true)
                        ->where('communication_conversations.name', 'LIKE', $like);
                });

                // Match the other participant's name in private conversations
                $q->orWhere(function ($privateQ) use ($userId, $like): void {
                    $privateQ->where('communication_conversations.is_group', false)
                        ->whereExists(function ($sub) use ($userId, $like): void {
                            $sub->select(DB::raw('1'))
                                ->from('conversation_participants as cp_other')
                                ->join('users', 'users.id', '=', 'cp_other.user_id')
                                ->whereColumn('cp_other.conversation_id', 'communication_conversations.id')
                                ->where('cp_other.user_id', '!=', $userId)
                                ->where('cp_other.status', 'active')
                                ->where('users.name', 'LIKE', $like);
                        });
                });
            });
        }

        return $query
            ->orderByDesc('last_message_created_at')
            ->orderByDesc('communication_conversations.updated_at')
            ->paginate($perPage);
    }

    public function paginateMessages(int $conversationId, int $userId, int $perPage = 50, array $excludeSenderIds = []): LengthAwarePaginator
    {
        $historyClearedMessageId = $this->historyClearedMessageId($conversationId, $userId);

        $query = Message::query()
            ->where('conversation_id', $conversationId)
            ->when($excludeSenderIds !== [], function ($query) use ($excludeSenderIds): void {
                $query->whereNotIn('sender_id', $excludeSenderIds);
            })
            ->with([
                'sender:id,name,avatar',
                'userDetails' => function ($query) use ($userId): void {
                    $query
                        ->where('user_id', $userId)
                        ->select([
                            'id',
                            'message_id',
                            'user_id',
                            'delivered_at',
                            'read_at',
                            'reaction',
                            'reaction_added_at',
                        ]);
                },
            ]);

        if ($historyClearedMessageId !== null) {
            $query->where('id', '>', $historyClearedMessageId);
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function searchMessages(int $conversationId, int $userId, string $query, int $perPage = 50, array $excludeSenderIds = []): LengthAwarePaginator
    {
        $search = trim($query);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        $historyClearedMessageId = $this->historyClearedMessageId($conversationId, $userId);

        $messageQuery = Message::query()
            ->where('conversation_id', $conversationId)
            ->when($excludeSenderIds !== [], function ($messageQuery) use ($excludeSenderIds): void {
                $messageQuery->whereNotIn('sender_id', $excludeSenderIds);
            })
            ->where(function ($messageQuery) use ($like): void {
                $messageQuery
                    ->where('content', 'LIKE', $like)
                    ->orWhere('type', 'LIKE', $like);
            })
            ->with([
                'sender:id,name,avatar',
                'userDetails' => function ($detailQuery) use ($userId): void {
                    $detailQuery
                        ->where('user_id', $userId)
                        ->select([
                            'id',
                            'message_id',
                            'user_id',
                            'delivered_at',
                            'read_at',
                            'reaction',
                            'reaction_added_at',
                        ]);
                },
            ]);

        if ($historyClearedMessageId !== null) {
            $messageQuery->where('id', '>', $historyClearedMessageId);
        }

        return $messageQuery
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function findMessage(string $messageId, bool $withDetails = false): ?Message
    {
        $query = Message::query()
            ->whereKey($messageId)
            ->with([
                'sender:id,name,avatar',
            ]);

        if ($withDetails) {
            $query->with([
                'userDetails.user:id,name,avatar',
            ]);
        }

        return $query->first();
    }

    public function create(array $payload): Message
    {
        return Message::query()->create($payload);
    }

    public function getParticipantIds(int $conversationId, ?int $excludeUserId = null): array
    {
        $query = ConversationParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('status', 'active');

        if ($excludeUserId !== null) {
            $query->where('user_id', '!=', $excludeUserId);
        }

        return $query->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();
    }

    public function upsertMessageDetails(string $messageId, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $existing = MessageUserDetail::query()
            ->where('message_id', $messageId)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $missingUserIds = array_values(array_diff($userIds, $existing));
        if ($missingUserIds === []) {
            return;
        }

        $rows = [];
        foreach ($missingUserIds as $userId) {
            $rows[] = [
                'message_id' => $messageId,
                'user_id' => $userId,
                'delivered_at' => null,
                'read_at' => null,
                'reaction' => null,
                'reaction_added_at' => null,
            ];
        }

        DB::table('message_user_details')->insert($rows);
    }

    public function upsertReaction(string $messageId, int $userId, ?string $reaction): void
    {
        $detail = MessageUserDetail::query()->firstOrNew([
            'message_id' => $messageId,
            'user_id' => $userId,
        ]);

        $detail->reaction = $reaction;
        $detail->reaction_added_at = $reaction !== null ? now() : null;

        if ($detail->delivered_at === null) {
            $detail->delivered_at = now();
        }

        $detail->save();
    }

    public function aggregateReactions(string $messageId, int $authUserId): array
    {
        $rawRows = MessageUserDetail::query()
            ->where('message_id', $messageId)
            ->whereNotNull('reaction')
            ->select([
                'reaction',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN user_id = '.(int) $authUserId.' THEN 1 ELSE 0 END) as reacted_by_me'),
            ])
            ->groupBy('reaction')
            ->orderByDesc('total')
            ->get();

        return $rawRows->map(static function ($row): array {
            return [
                'type' => (string) $row->reaction,
                'count' => (int) $row->total,
                'reacted_by_me' => (int) $row->reacted_by_me > 0,
            ];
        })->values()->all();
    }

    public function update(string $messageId, array $payload): bool
    {
        return Message::query()->whereKey($messageId)->update($payload) > 0;
    }

    private function historyClearedMessageId(int $conversationId, int $userId): ?string
    {
        $value = ConversationParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->value('history_cleared_message_id');

        return $value !== null ? (string) $value : null;
    }
}
