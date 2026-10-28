<?php

namespace App\Manager;

use App\Models\Profile;
use App\Models\UserBlock;
use App\Models\UserConnection;
use Illuminate\Support\Facades\Auth;

class ConnectionManager
{
    protected NotificationManager $notificationManager;

    public function __construct(NotificationManager $notificationManager)
    {
        $this->notificationManager = $notificationManager;
    }

    public function connections(array $params = [], int $perPage = 20): array
    {
        $profileId = (int) $this->profileId();

        $sent = UserConnection::accepted()
            ->byRequester($profileId)
            ->whereIn('connection_type', ['professional', 'social'])
            ->with('recipient.user')
            ->get()
            ->map(fn (UserConnection $c) => [
                'id' => $c->id,
                'user' => $this->formatProfile($c->recipient),
                'connection_type' => $c->connection_type,
                'status' => $c->status,
                'created_at' => $c->created_at?->toIso8601String(),
            ]);

        $received = UserConnection::accepted()
            ->byRecipient($profileId)
            ->whereIn('connection_type', ['professional', 'social'])
            ->with('requester.user')
            ->get()
            ->map(fn (UserConnection $c) => [
                'id' => $c->id,
                'user' => $this->formatProfile($c->requester),
                'connection_type' => $c->connection_type,
                'status' => $c->status,
                'created_at' => $c->created_at?->toIso8601String(),
            ]);

        return collect($sent->all())->merge(collect($received->all()))
            ->unique(fn ($item) => $item['user']['id'] ?? $item['id'])
            ->values()
            ->all();
    }

    public function pendingRequests(array $params = [], int $perPage = 20): array
    {
        $profileId = (int) $this->profileId();

        return UserConnection::pending()
            ->byRecipient($profileId)
            ->with('requester.user')
            ->get()
            ->map(fn (UserConnection $c) => [
                'id' => $c->id,
                'user' => $this->formatProfile($c->requester),
                'connection_type' => $c->connection_type,
                'status' => $c->status,
                'created_at' => $c->created_at?->toIso8601String(),
            ])
            ->all();
    }

    public function sentRequests(array $params = [], int $perPage = 20): array
    {
        $profileId = (int) $this->profileId();

        return UserConnection::pending()
            ->byRequester($profileId)
            ->with('recipient.user')
            ->get()
            ->map(fn (UserConnection $c) => [
                'id' => $c->id,
                'user' => $this->formatProfile($c->recipient),
                'connection_type' => $c->connection_type,
                'status' => $c->status,
                'created_at' => $c->created_at?->toIso8601String(),
            ])
            ->all();
    }

    public function sendRequest(Profile $profile, string $type): UserConnection
    {
        $profileId = (int) $this->profileId();

        if ((int) $profile->id === $profileId) {
            throw new \Exception('Cannot send a connection request to yourself', 422);
        }

        $existing = UserConnection::where(function ($q) use ($profileId, $profile) {
            $q->where('requester_id', $profileId)->where('recipient_id', $profile->id);
        })->orWhere(function ($q) use ($profileId, $profile) {
            $q->where('requester_id', $profile->id)->where('recipient_id', $profileId);
        })->whereIn('connection_type', ['professional', 'social', 'friend'])
            ->first();

        if ($existing) {
            if ($existing->status === 'accepted') {
                throw new \Exception('Already connected with this user', 422);
            }
            if ($existing->status === 'pending') {
                throw new \Exception('Connection request already sent and is pending', 422);
            }
        }

        $connection = UserConnection::create([
            'requester_id' => $profileId,
            'recipient_id' => $profile->id,
            'connection_type' => $type,
            'status' => 'pending',
        ]);

        // Auto-follow: Requester follows recipient
        UserConnection::updateOrCreate(
            ['requester_id' => $profileId, 'recipient_id' => $profile->id, 'connection_type' => 'follow'],
            ['status' => 'accepted']
        );

        $name = Auth::guard('jwt')->user()?->profile?->full_name ?? 'Someone';
        $this->notificationManager->dispatch(
            (int) $profile->id,
            'social.connection_requested',
            'Connection Request',
            "{$name} sent you a connection request.",
            [
                'requester_id' => $profileId,
                'connection_id' => $connection->id,
            ]
        );

        return $connection->fresh();
    }

    public function acceptRequest(UserConnection $connection): UserConnection
    {
        $profileId = (int) $this->profileId();

        if ((int) $connection->recipient_id !== $profileId) {
            throw new \Exception('You can only accept requests sent to you', 403);
        }

        if ($connection->status !== 'pending') {
            throw new \Exception('This request is no longer pending', 422);
        }

        $connection->update(['status' => 'accepted']);

        // Auto-follow: Recipient follows requester (mutual follow)
        UserConnection::updateOrCreate(
            ['requester_id' => $connection->recipient_id, 'recipient_id' => $connection->requester_id, 'connection_type' => 'follow'],
            ['status' => 'accepted']
        );

        $name = Auth::guard('jwt')->user()?->profile?->full_name ?? 'Someone';
        $this->notificationManager->dispatch(
            (int) $connection->requester_id,
            'social.connection_accepted',
            'Connection Request Accepted',
            "{$name} accepted your connection request.",
            [
                'recipient_id' => $profileId,
                'connection_id' => $connection->id,
            ]
        );

        return $connection->fresh()->load('requester');
    }

    public function declineRequest(UserConnection $connection): void
    {
        $profileId = (int) $this->profileId();

        if ((int) $connection->recipient_id !== $profileId) {
            throw new \Exception('You can only decline requests sent to you', 403);
        }

        // Auto-unfollow: Requester unfollows recipient
        UserConnection::where('requester_id', $connection->requester_id)
            ->where('recipient_id', $connection->recipient_id)
            ->where('connection_type', 'follow')
            ->delete();

        $connection->delete();
    }

    public function removeConnection(UserConnection $connection): void
    {
        $profileId = (int) $this->profileId();

        if ((int) $connection->requester_id !== $profileId && (int) $connection->recipient_id !== $profileId) {
            throw new \Exception('You can only remove your own connections', 403);
        }

        // Auto-unfollow in both directions
        UserConnection::where('connection_type', 'follow')
            ->where(function ($q) use ($connection) {
                $q->where('requester_id', $connection->requester_id)->where('recipient_id', $connection->recipient_id);
            })->orWhere(function ($q) use ($connection) {
                $q->where('requester_id', $connection->recipient_id)->where('recipient_id', $connection->requester_id);
            })->delete();

        $connection->delete();
    }

    public function follow(Profile $profile): array
    {
        $profileId = (int) $this->profileId();

        if ((int) $profile->id === $profileId) {
            throw new \Exception('Cannot follow yourself', 422);
        }

        $existing = UserConnection::where('requester_id', $profileId)
            ->where('recipient_id', $profile->id)
            ->first();

        if ($existing && $existing->status === 'accepted') {
            return ['is_following' => true];
        }

        if ($existing && $existing->status === 'pending' && $existing->connection_type === 'friend') {
            // Already have a pending friend request - promote to accepted
            $existing->update(['status' => 'accepted']);

            return ['is_following' => true];
        }

        UserConnection::updateOrCreate(
            ['requester_id' => $profileId, 'recipient_id' => $profile->id],
            ['connection_type' => 'follow', 'status' => 'accepted']
        );

        $name = Auth::guard('jwt')->user()?->profile?->full_name ?? 'Someone';
        $this->notificationManager->dispatch(
            (int) $profile->id,
            'social.followed',
            'New Follower',
            "{$name} started following you.",
            [
                'follower_id' => $profileId,
            ]
        );

        return ['is_following' => true];
    }

    public function unfollow(Profile $profile): array
    {
        $profileId = (int) $this->profileId();

        UserConnection::where('requester_id', $profileId)
            ->where('recipient_id', $profile->id)
            ->where('connection_type', 'follow')
            ->delete();

        return ['is_following' => false];
    }

    public function block(Profile $profile): array
    {
        $profileId = (int) $this->profileId();

        if ((int) $profile->id === $profileId) {
            throw new \Exception('Cannot block yourself', 422);
        }

        // Remove any existing connection
        UserConnection::where(function ($q) use ($profileId, $profile) {
            $q->where('requester_id', $profileId)->where('recipient_id', $profile->id);
        })->orWhere(function ($q) use ($profileId, $profile) {
            $q->where('requester_id', $profile->id)->where('recipient_id', $profileId);
        })->delete();

        // Create or ensure block exists
        UserBlock::firstOrCreate([
            'blocker_id' => $profileId,
            'blocked_id' => $profile->id,
        ]);

        return ['blocked' => true];
    }

    public function unblock(Profile $profile): array
    {
        $profileId = (int) $this->profileId();

        UserBlock::byBlocker($profileId)->where('blocked_id', $profile->id)->delete();

        return ['blocked' => false];
    }

    public function blockedUsers(array $params = [], int $perPage = 20): array
    {
        $profileId = (int) $this->profileId();

        return UserBlock::byBlocker($profileId)
            ->with('blocked.user')
            ->get()
            ->map(fn (UserBlock $block) => $this->formatProfile($block->blocked))
            ->values()
            ->all();
    }

    public function isConnected(int $profileIdA, int $profileIdB): bool
    {
        return UserConnection::where(function ($q) use ($profileIdA, $profileIdB) {
            $q->where('requester_id', $profileIdA)->where('recipient_id', $profileIdB);
        })->orWhere(function ($q) use ($profileIdA, $profileIdB) {
            $q->where('requester_id', $profileIdB)->where('recipient_id', $profileIdA);
        })->where('status', 'accepted')->exists();
    }

    public function isBlocked(int $profileIdA, int $profileIdB): bool
    {
        return UserBlock::where('blocker_id', $profileIdA)
            ->where('blocked_id', $profileIdB)
            ->exists();
    }

    public function followers(int $profileId, int $perPage = 20): array
    {
        return UserConnection::where('recipient_id', $profileId)
            ->where('connection_type', 'follow')
            ->where('status', 'accepted')
            ->with('requester.user')
            ->get()
            ->map(fn (UserConnection $c) => $this->formatProfile($c->requester))
            ->filter()
            ->values()
            ->all();
    }

    public function following(int $profileId, int $perPage = 20): array
    {
        return UserConnection::where('requester_id', $profileId)
            ->where('connection_type', 'follow')
            ->where('status', 'accepted')
            ->with('recipient.user')
            ->get()
            ->map(fn (UserConnection $c) => $this->formatProfile($c->recipient))
            ->filter()
            ->values()
            ->all();
    }

    protected function formatProfile(?Profile $profile): ?array
    {
        if (! $profile) {
            return null;
        }

        return [
            'id' => $profile->id,
            'full_name' => $profile->full_name,
            'username' => $profile->username,
            'avatar_url' => $profile->avatar_url,
            'bio' => $profile->bio,
            'location' => $profile->location,
            'is_verified' => (bool) $profile->user?->is_verified,
            'account_type' => $profile->user?->account_type,
        ];
    }

    protected function profileId(): int
    {
        $user = Auth::guard('jwt')->user();
        $profile = $user->profile;

        if (! $profile) {
            throw new \Exception('Profile not found. Complete your profile first.', 400);
        }

        return (int) $profile->id;
    }
}
