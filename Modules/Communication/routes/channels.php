<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Modules\Communication\Models\ConversationParticipant;

Broadcast::channel('user.{id}', function (User $user, int $id): bool {
    return (int) $user->id === $id;
});

Broadcast::channel('livestreams', function (): array|bool {
    // Public discovery feed channel — any authenticated user may listen.
    return true;
});

Broadcast::channel('call.{conversationId}', function (User $user, int $conversationId): array|bool {
    $isParticipant = ConversationParticipant::query()
        ->where('conversation_id', $conversationId)
        ->where('user_id', (int) $user->id)
        ->where('status', 'active')
        ->exists();

    if (! $isParticipant) {
        return false;
    }

    $name = trim((string) ($user->name ?? ''));
    if ($name === '') {
        $name = 'User '.(int) $user->id;
    }

    return [
        'id' => (int) $user->id,
        'name' => $name,
        'avatar' => $user->avatar,
    ];
});
