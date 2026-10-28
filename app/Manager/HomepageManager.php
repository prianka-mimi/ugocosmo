<?php

namespace App\Manager;

use App\Http\Resources\PostResource;
use App\Models\AppSetting;
use App\Models\Livestream;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Story;
use Illuminate\Support\Facades\Auth;

class HomepageManager
{
    public function index(): array
    {
        $user = Auth::guard('jwt')->user();
        $profileId = (int) $user->profile?->id;

        return [
            // 'header' => $this->header($user, $profileId),
            'navbar' => $this->navbar($profileId),
            'feed' => $this->feed($profileId),
            // 'bottom_nav' => $this->bottomNav(),
        ];
    }

    public function header($user, int $profileId): array
    {
        $unreadNotifications = Notification::forUser($profileId)->unread()->count();

        return [
            'logo' => $this->getLogoUrl(),
            'site_name' => AppSetting::get('tab_name', config('app.name', 'UgoCosmo')),
            'search_placeholder' => 'Search users, posts, and more...',
            'notifications' => [
                'unread_count' => $unreadNotifications,
                'has_onboarding_notification' => ! (bool) $user->onboarding_completed,
            ],
            'messages' => [
                'unread_count' => 0,
            ],
            'cart' => [
                'item_count' => 0,
            ],
        ];
    }

    public function navbar(int $profileId): array
    {
        $liveStreams = Livestream::where('is_live', true)
            ->with('host')
            ->latest('started_at')
            ->get()
            ->map(fn ($stream) => [
                'id' => $stream->id,
                'title' => $stream->title,
                'host' => [
                    'id' => $stream->host?->id,
                    'full_name' => $stream->host?->full_name,
                    'avatar_url' => ImageManager::fileUrl($stream->host?->avatar_url),
                ],
                'viewer_count' => $stream->viewer_count,
                'thumbnail_url' => ImageManager::fileUrl($stream->thumbnail_url),
            ]);

        $stories = Story::active()
            ->latestFirst()
            ->with('creator')
            ->get()
            ->groupBy('creator_id')
            ->map(function ($stories) {
                $creator = $stories->first()->creator;

                return [
                    'creator' => [
                        'id' => $creator->id,
                        'full_name' => $creator->full_name,
                        'avatar_url' => ImageManager::fileUrl($creator->avatar_url),
                    ],
                    'has_unviewed' => $stories->contains(function ($story) {
                        return $story->view_count === 0;
                    }),
                ];
            })
            ->values();

        return [
            // 'live' => $liveStreams,
            'stories' => $stories,
        ];
    }

    public function feed(int $profileId): array
    {
        $posts = Post::active()
            ->latestFirst()
            ->with(['author', 'likes', 'saves'])
            ->paginate(20);

        $data = PostResource::collection($posts)->response()->getData(true);

        return [
            'data' => $data['data'] ?? [],
            'current_page' => $data['current_page'] ?? 1,
            'last_page' => $data['last_page'] ?? 1,
            'per_page' => $data['per_page'] ?? 20,
            'total' => $data['total'] ?? 0,
        ];
    }

    public function bottomNav(): array
    {
        return [
            'home' => [
                'icon' => 'home',
                'label' => 'Home',
                'route' => url('/home'),
            ],
            'shop' => [
                'icon' => 'shop',
                'label' => 'Shop',
                'route' => url('/shop'),
            ],
            'plus_menu' => [
                'icon' => 'plus',
                'label' => 'Create',
                'route' => null,
                'actions' => [
                    ['label' => 'Go Live', 'route' => url('/live/create')],
                    ['label' => 'List a Product', 'route' => url('/products/create')],
                    ['label' => 'List a Service', 'route' => url('/services/create')],
                    ['label' => 'Post a Job', 'route' => url('/jobs/create')],
                    ['label' => 'Create an Event', 'route' => url('/events/create')],
                ],
            ],
            'connect' => [
                'icon' => 'connect',
                'label' => 'Connect',
                'route' => url('/connect'),
            ],
            'account' => [
                'icon' => 'account',
                'label' => 'Account',
                'route' => url('/profile'),
            ],
        ];
    }

    protected function getLogoUrl(): ?string
    {
        $logoPath = AppSetting::get('logo');

        return ImageManager::fileUrl($logoPath);
    }
}
