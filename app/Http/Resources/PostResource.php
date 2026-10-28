<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use App\Models\Event;
use App\Models\JobListing;
use App\Models\PostComment;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PostResource extends JsonResource
{
    public function toArray($request): array
    {
        $user = auth()->guard('jwt')->user();
        $profileId = $user?->profile?->id;

        $attachedItem = null;
        if ($this->attached_item_type && $this->attached_item_id) {
            $modelMap = [
                'product' => Product::class,
                'service' => Service::class,
                'event' => Event::class,
                'job_listing' => JobListing::class,
            ];
            $itemClass = $modelMap[$this->attached_item_type] ?? null;
            if ($itemClass && class_exists($itemClass)) {
                $item = $itemClass::find($this->attached_item_id);
                if ($item) {
                    $attachedItem = [
                        'id' => $item->id,
                        'type' => $this->attached_item_type,
                        'title' => $item->title ?? $item->name ?? null,
                        'description' => $item->description ?? null,
                        'price' => $item->price ?? null,
                        'image_url' => $item->image_url ? ImageManager::fileUrl($item->image_url) : null,
                    ];
                }
            }
        }

        return [
            'id' => $this->id,
            'author' => [
                'id' => $this->author?->id,
                'full_name' => $this->author?->full_name,
                'username' => $this->author?->username,
                'avatar_url' => $this->fileUrl($this->author?->avatar_url),
            ],
            'content' => $this->content,
            'image_url' => $this->fileUrl($this->image_url),
            'video_url' => $this->fileUrl($this->video_url),
            'media' => $this->media ? collect($this->media)->map(fn ($path) => $this->fileUrl($path))->all() : [],
            'gif_url' => $this->gif_url,
            'emoji' => $this->emoji,
            'attached_item' => $attachedItem,
            'like_count' => $this->like_count,
            'comment_count' => $this->comment_count,
            'share_count' => $this->share_count,
            'is_liked' => $profileId ? ($this->relationLoaded('likes') ? $this->likes->contains('user_id', $profileId) : $this->likes()->where('user_id', $profileId)->exists()) : false,
            'is_saved' => $profileId ? ($this->relationLoaded('saves') ? $this->saves->contains('user_id', $profileId) : $this->saves()->where('user_id', $profileId)->exists()) : false,
            'is_shared' => $profileId ? ($this->relationLoaded('shares') ? $this->shares->contains('user_id', $profileId) : $this->shares()->where('user_id', $profileId)->exists()) : false,
            'original_post' => $this->when($this->original_post_id, function () {
                return $this->originalPost ? PostResource::make($this->originalPost) : null;
            }),
            'comments' => $this->when($this->relationLoaded('comments'), function () {
                return $this->comments->map(fn (PostComment $comment) => PostResource::formatComment($comment))->values();
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    public static function formatComment(PostComment $comment): array
    {
        return [
            'id' => $comment->id,
            'parent_id' => $comment->parent_id,
            'author' => [
                'id' => $comment->author?->id,
                'full_name' => $comment->author?->full_name,
                'username' => $comment->author?->username,
                'avatar_url' => self::staticFileUrl($comment->author?->avatar_url),
            ],
            'content' => $comment->content,
            'created_at' => $comment->created_at?->toIso8601String(),
            'reactions' => $comment->reactions_data ?? [
                'total' => 0,
                'user_reaction' => false,
            ],
            'replies' => $comment->relationLoaded('replies')
                ? $comment->replies->map(fn (PostComment $reply) => [
                    'id' => $reply->id,
                    'parent_id' => $reply->parent_id,
                    'author' => [
                        'id' => $reply->author?->id,
                        'full_name' => $reply->author?->full_name,
                        'username' => $reply->author?->username,
                        'avatar_url' => self::staticFileUrl($reply->author?->avatar_url),
                    ],
                    'content' => $reply->content,
                    'created_at' => $reply->created_at?->toIso8601String(),
                    'reactions' => $reply->reactions_data ?? [
                        'total' => 0,
                        'user_reaction' => false,
                    ],
                ])->values()
                : [],
        ];
    }

    protected function fileUrl(?string $path): ?string
    {
        return self::staticFileUrl($path);
    }

    protected static function staticFileUrl(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }
        $url = Storage::url($path);

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://') ? $url : url($url);
    }
}
