<?php

namespace App\Manager;

use App\Models\Profile;
use App\Models\Story;
use App\Models\StoryReaction;
use App\Models\StoryView;
use App\Models\UserBlock;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class StoryManager
{
    protected NotificationManager $notificationManager;

    public function __construct(NotificationManager $notificationManager)
    {
        $this->notificationManager = $notificationManager;
    }

    public function active(array $params = [], int $perPage = 20)
    {
        $user = Auth::guard('jwt')->user();
        $profileId = (int) $user->profile?->id;

        $blockedIds = UserBlock::byBlocker($profileId)->pluck('blocked_id')->toArray();

        $stories = Story::active()
            ->latestFirst()
            ->with('creator')
            ->whereNotIn('creator_id', $blockedIds)
            ->get();

        $grouped = $stories->groupBy('creator_id');

        return $grouped->map(function ($stories, $creatorId) {
            $creator = $stories->first()->creator;

            return [
                'creator' => [
                    'id' => $creator->id,
                    'full_name' => $creator->full_name,
                    'username' => $creator->username,
                    'avatar_url' => ImageManager::fileUrl($creator->avatar_url),
                ],
                'stories' => $stories->map(fn (Story $story) => $this->format($story))->values()->all(),
            ];
        })->values()->all();
    }

    public function show(Story $story): array
    {
        return $this->format($story->load('creator'));
    }

    public function mine(array $params = [], int $perPage = 20): array
    {
        $profileId = (int) $this->profileId();

        return Story::byCreator($profileId)
            ->active()
            ->latestFirst()
            ->with('creator')
            ->get()
            ->map(fn (Story $story) => $this->format($story))
            ->all();
    }

    public function store(array $data): Story
    {
        $profileId = (int) $this->profileId();

        $story = Story::create([
            'creator_id' => $profileId,
            'media_type' => $data['media_type'],
            'media_url' => '', // temporary placeholder, updated below
            'caption' => $data['caption'] ?? null,
            'duration_seconds' => $data['duration_seconds'] ?? 15,
            'expires_at' => now()->addHours(24),
            'view_count' => 0,
        ]);

        if (isset($data['media'])) {
            if ($data['media_type'] === 'video') {
                $story->media_url = $data['media']->store('story-videos', 'public');
            } else {
                $story->media_url = app(ImageManager::class)->upload($data['media'], 'story-images');
            }
            $story->save();
        }

        return $story->load('creator');
    }

    public function destroy(Story $story): void
    {
        $this->ensureOwned($story);

        app(ImageManager::class)->delete($story->media_url);

        $story->delete();
    }

    public function toggleReaction(Story $story, bool $reaction): array
    {
        $profileId = (int) $this->profileId();

        $existing = StoryReaction::where('story_id', $story->id)
            ->where('reactor_id', $profileId)
            ->first();

        if ($reaction && ! $existing) {
            // Add reaction
            StoryReaction::create([
                'story_id' => $story->id,
                'reactor_id' => $profileId,
                'reaction_type' => 'like',
            ]);
            $isReacted = true;

            if ((int) $story->creator_id !== $profileId) {
                $name = Auth::guard('jwt')->user()?->profile?->full_name ?? 'Someone';
                $this->notificationManager->dispatch(
                    (int) $story->creator_id,
                    'story.reacted',
                    'Story Reaction',
                    "{$name} reacted to your story.",
                    ['story_id' => $story->id, 'reactor_id' => $profileId]
                );
            }
        } elseif (! $reaction && $existing) {
            // Remove reaction
            $existing->delete();
            $isReacted = false;
        } else {
            // No change needed
            $isReacted = $existing ? true : false;
        }

        return [
            'is_reacted' => $isReacted,
            'reaction_count' => $story->reactions()->count(),
        ];
    }

    public function reactions(Story $story, int $perPage = 20): LengthAwarePaginator
    {
        return StoryReaction::where('story_id', $story->id)
            ->with('reactor')
            ->latest()
            ->paginate($perPage)
            ->through(fn (StoryReaction $reaction) => [
                'id' => $reaction->id,
                'reactor' => [
                    'id' => $reaction->reactor?->id,
                    'full_name' => $reaction->reactor?->full_name,
                    'username' => $reaction->reactor?->username,
                    'avatar_url' => ImageManager::fileUrl($reaction->reactor?->avatar_url),
                ],
                'reaction_type' => $reaction->reaction_type,
                'created_at' => $reaction->created_at?->toIso8601String(),
            ]);
    }

    public function views(Story $story, int $perPage = 20): LengthAwarePaginator
    {
        return StoryView::where('story_id', $story->id)
            ->with('viewer')
            ->latest()
            ->paginate($perPage)
            ->through(fn (StoryView $view) => [
                'id' => $view->id,
                'viewer' => [
                    'id' => $view->viewer?->id,
                    'full_name' => $view->viewer?->full_name,
                    'username' => $view->viewer?->username,
                    'avatar_url' => ImageManager::fileUrl($view->viewer?->avatar_url),
                ],
                'viewed_at' => $view->viewed_at?->toIso8601String(),
            ]);
    }

    public function byUser(Profile $profile, int $perPage = 20): array
    {
        $stories = Story::byCreator((int) $profile->id)
            ->latestFirst()
            ->with('creator')
            ->paginate($perPage);

        return [
            'user' => [
                'id' => $profile->id,
                'full_name' => $profile->full_name,
                'username' => $profile->username,
                'avatar_url' => ImageManager::fileUrl($profile->avatar_url),
            ],
            'stories_count' => $stories->total(),
            'stories' => $stories->through(fn (Story $story) => $this->format($story)),
        ];
    }

    public function markViewed(Story $story): void
    {
        $profileId = (int) $this->profileId();

        $existingView = StoryView::where('story_id', $story->id)
            ->where('viewer_id', $profileId)
            ->first();

        if (! $existingView) {
            StoryView::create([
                'story_id' => $story->id,
                'viewer_id' => $profileId,
                'viewed_at' => now(),
            ]);

            $story->increment('view_count');
        }
    }

    protected function format(Story $story): array
    {
        $profileId = (int) $this->profileId();

        $hasReacted = StoryReaction::where('story_id', $story->id)
            ->where('reactor_id', $profileId)
            ->exists();

        return [
            'id' => $story->id,
            'media_url' => ImageManager::fileUrl($story->media_url),
            'media_type' => $story->media_type,
            'caption' => $story->caption,
            'duration_seconds' => $story->duration_seconds,
            'view_count' => $story->view_count,
            'reaction_count' => $story->reactions()->count(),
            'is_reacted' => $hasReacted,
            'expires_at' => $story->expires_at?->toIso8601String(),
            'created_at' => $story->created_at?->toIso8601String(),
        ];
    }

    protected function ensureOwned(Story $story): void
    {
        if ((int) $story->creator_id !== (int) $this->profileId()) {
            abort(404);
        }
    }

    protected function profileId(): ?int
    {
        $user = Auth::guard('jwt')->user();

        return $user?->profile?->id;
    }
}
