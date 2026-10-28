<?php

declare(strict_types=1);

namespace Modules\Communication\Repositories;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Communication\Models\Message;
use Modules\Communication\Models\MessageUserDetail;
use Modules\Communication\Repositories\Contracts\MessageRepositoryInterface;
use RuntimeException;

class MongoDbMessageRepository implements MessageRepositoryInterface
{
    public function __construct(private readonly MariaDbMessageRepository $mariaDbRepository)
    {
        if (! method_exists(DB::connection('mongodb'), 'collection')) {
            throw new RuntimeException('mongodb/laravel-mongodb package is required for MESSAGE_DB_DRIVER=mongodb.');
        }
    }

    public function paginateConversationsForUser(int $userId, int $perPage = 20, ?string $search = null): LengthAwarePaginator
    {
        return $this->mariaDbRepository->paginateConversationsForUser($userId, $perPage, $search);
    }

    public function paginateMessages(int $conversationId, int $userId, int $perPage = 50, array $excludeSenderIds = []): LengthAwarePaginator
    {
        $page = max(1, LengthAwarePaginator::resolveCurrentPage());
        $skip = ($page - 1) * $perPage;

        $collection = $this->mongoCollection();
        $totalQuery = $collection->where('conversation_id', $conversationId);
        if ($excludeSenderIds !== []) {
            $totalQuery = $totalQuery->whereNotIn('sender_id', $excludeSenderIds);
        }

        $total = (int) $totalQuery->count();

        $rowsQuery = $this->mongoCollection()
            ->where('conversation_id', $conversationId);
        if ($excludeSenderIds !== []) {
            $rowsQuery = $rowsQuery->whereNotIn('sender_id', $excludeSenderIds);
        }

        $rows = $rowsQuery
            ->orderBy('created_at', 'desc')
            ->skip($skip)
            ->take($perPage)
            ->get()
            ->all();

        $messages = collect($rows)
            ->map(fn ($row): Message => $this->hydrateMessage($this->normalizeDocument($row)))
            ->values();

        $this->attachSenderAndUserDetails($messages, $userId);

        return new LengthAwarePaginator(
            $messages->all(),
            $total,
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => 'page',
            ]
        );
    }

    public function searchMessages(int $conversationId, int $userId, string $query, int $perPage = 50, array $excludeSenderIds = []): LengthAwarePaginator
    {
        // Search against relational shadow rows to keep behavior consistent across drivers.
        return $this->mariaDbRepository->searchMessages($conversationId, $userId, $query, $perPage, $excludeSenderIds);
    }

    public function findMessage(string $messageId, bool $withDetails = false): ?Message
    {
        $document = $this->mongoCollection()->where('id', $messageId)->first();
        if ($document === null) {
            return $this->mariaDbRepository->findMessage($messageId, $withDetails);
        }

        $message = $this->hydrateMessage($this->normalizeDocument($document));

        $sender = User::query()->select('id', 'name', 'avatar')->find((int) $message->sender_id);
        $message->setRelation('sender', $sender);

        if ($withDetails) {
            $details = MessageUserDetail::query()
                ->where('message_id', $messageId)
                ->with('user:id,name,avatar')
                ->get();

            $message->setRelation('userDetails', $details);
        }

        return $message;
    }

    public function create(array $payload): Message
    {
        $mongoPayload = $this->mongoPayload($payload);
        $this->mongoCollection()->insert($mongoPayload);

        // Keep a relational shadow row so receipt tracking and joins remain available.
        Message::query()->updateOrCreate(['id' => $payload['id']], $payload);

        $message = $this->hydrateMessage($mongoPayload);

        $sender = User::query()->select('id', 'name', 'avatar')->find((int) $message->sender_id);
        $message->setRelation('sender', $sender);

        return $message;
    }

    public function getParticipantIds(int $conversationId, ?int $excludeUserId = null): array
    {
        return $this->mariaDbRepository->getParticipantIds($conversationId, $excludeUserId);
    }

    public function upsertMessageDetails(string $messageId, array $userIds): void
    {
        $this->mariaDbRepository->upsertMessageDetails($messageId, $userIds);
    }

    public function upsertReaction(string $messageId, int $userId, ?string $reaction): void
    {
        $this->mariaDbRepository->upsertReaction($messageId, $userId, $reaction);
    }

    public function aggregateReactions(string $messageId, int $authUserId): array
    {
        return $this->mariaDbRepository->aggregateReactions($messageId, $authUserId);
    }

    public function update(string $messageId, array $payload): bool
    {
        $updatedMongo = (int) $this->mongoCollection()->where('id', $messageId)->update($this->mongoPayload($payload)) > 0;
        $updatedMaria = $this->mariaDbRepository->update($messageId, $payload);

        return $updatedMongo || $updatedMaria;
    }

    private function attachSenderAndUserDetails(Collection $messages, int $userId): void
    {
        if ($messages->isEmpty()) {
            return;
        }

        $senderIds = $messages
            ->pluck('sender_id')
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $messageIds = $messages
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();

        $senders = User::query()
            ->select('id', 'name', 'avatar')
            ->whereIn('id', $senderIds)
            ->get()
            ->keyBy('id');

        $details = MessageUserDetail::query()
            ->whereIn('message_id', $messageIds)
            ->where('user_id', $userId)
            ->get()
            ->groupBy('message_id');

        foreach ($messages as $message) {
            $message->setRelation('sender', $senders->get((int) $message->sender_id));
            $message->setRelation('userDetails', $details->get((string) $message->id, collect()));
        }
    }

    private function hydrateMessage(array $attributes): Message
    {
        $message = new Message;
        $message->forceFill($attributes);
        $message->exists = true;

        return $message;
    }

    private function normalizeDocument(mixed $row): array
    {
        $document = is_array($row) ? $row : (array) $row;

        if (isset($document['_id']) && ! isset($document['id'])) {
            $document['id'] = (string) $document['_id'];
        }

        unset($document['_id']);

        foreach (['created_at', 'updated_at'] as $dateField) {
            if (isset($document[$dateField]) && ! $document[$dateField] instanceof \DateTimeInterface) {
                $document[$dateField] = Carbon::parse((string) $document[$dateField]);
            }
        }

        foreach (['attachments', 'metadata', 'reactions_summary'] as $jsonField) {
            if (isset($document[$jsonField]) && is_object($document[$jsonField])) {
                $document[$jsonField] = (array) $document[$jsonField];
            }
        }

        return $document;
    }

    private function mongoPayload(array $payload): array
    {
        $normalized = $payload;

        if (isset($normalized['created_at']) && $normalized['created_at'] !== null && ! $normalized['created_at'] instanceof \DateTimeInterface) {
            $normalized['created_at'] = Carbon::parse((string) $normalized['created_at']);
        }

        if (isset($normalized['updated_at']) && $normalized['updated_at'] !== null && ! $normalized['updated_at'] instanceof \DateTimeInterface) {
            $normalized['updated_at'] = Carbon::parse((string) $normalized['updated_at']);
        }

        return $normalized;
    }

    private function mongoCollection()
    {
        return DB::connection('mongodb')->collection('messages');
    }
}
