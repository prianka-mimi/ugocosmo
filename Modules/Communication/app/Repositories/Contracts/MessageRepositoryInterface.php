<?php

declare(strict_types=1);

namespace Modules\Communication\Repositories\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Communication\Models\Message;

interface MessageRepositoryInterface
{
    public function paginateConversationsForUser(int $userId, int $perPage = 20, ?string $search = null): LengthAwarePaginator;

    public function paginateMessages(int $conversationId, int $userId, int $perPage = 50, array $excludeSenderIds = []): LengthAwarePaginator;

    public function searchMessages(int $conversationId, int $userId, string $query, int $perPage = 50, array $excludeSenderIds = []): LengthAwarePaginator;

    public function findMessage(string $messageId, bool $withDetails = false): ?Message;

    public function create(array $payload): Message;

    public function getParticipantIds(int $conversationId, ?int $excludeUserId = null): array;

    public function upsertMessageDetails(string $messageId, array $userIds): void;

    public function upsertReaction(string $messageId, int $userId, ?string $reaction): void;

    public function aggregateReactions(string $messageId, int $authUserId): array;

    public function update(string $messageId, array $payload): bool;
}
