<?php

namespace App\Manager;

use App\Models\CommentReaction;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Models\PostReport;
use App\Models\PostSave;
use App\Models\PostShare;
use App\Models\UserBlock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class PostManager
{
    protected NotificationManager $notificationManager;

    public function __construct(NotificationManager $notificationManager)
    {
        $this->notificationManager = $notificationManager;
    }

    public function feed(array $params = [], int $perPage = 20)
    {
        $user = Auth::guard('jwt')->user();
        $profileId = (int) $user->profile?->id;

        $blockedUserIds = UserBlock::byBlocker($profileId)->pluck('blocked_id')->toArray();

        $query = Post::active()->with(['author', 'likes', 'saves', 'shares', 'originalPost.author']);

        // Apply filters
        if (! empty($params['author_id'])) {
            $query->byAuthor((int) $params['author_id']);
        }

        // Apply sorting
        $sort = $params['sort'] ?? 'latest';
        if ($sort === 'popular') {
            $query->popular();
        } else {
            $query->latestFirst();
        }

        // Exclude blocked users
        $query->whereNotIn('author_id', $blockedUserIds);

        return $query->paginate($perPage);
    }

    public function store(array $data): Post
    {
        $postData = [
            'author_id' => $this->profileId(),
            'content' => $data['content'] ?? '',
        ];

        // Handle single image upload
        if (isset($data['image'])) {
            $postData['image_url'] = app(ImageManager::class)->upload($data['image'], 'posts');
        }

        // Handle video upload
        if (isset($data['video'])) {
            $postData['video_url'] = $data['video']->store('posts/videos', 'public');
        }

        // Handle attached item (product/service/event/job)
        if (! empty($data['attached_item_type']) && ! empty($data['attached_item_id'])) {
            $postData['attached_item_type'] = $data['attached_item_type'];
            $postData['attached_item_id'] = $data['attached_item_id'];
        }

        // Handle multiple images (media array)
        if (isset($data['media']) && is_array($data['media'])) {
            $mediaUrls = [];
            foreach ($data['media'] as $mediaFile) {
                $mediaUrls[] = app(ImageManager::class)->upload($mediaFile, 'posts/media');
            }
            $postData['media'] = $mediaUrls;
        }

        // Handle GIF URL
        if (! empty($data['gif_url'])) {
            $postData['gif_url'] = $data['gif_url'];
        }

        // Handle emoji
        if (! empty($data['emoji'])) {
            $postData['emoji'] = $data['emoji'];
        }

        $post = Post::create($postData);

        return $post->load(['author', 'likes', 'saves']);
    }

    public const int DEFAULT_COMMENTS_PER_PAGE = 5;

    public function show(Post $post): Post
    {
        if (! $post->is_active) {
            abort(404);
        }

        $post->load(['author', 'likes', 'saves', 'shares', 'originalPost.author']);

        $user = Auth::guard('jwt')->user();
        $profileId = (int) ($user?->profile?->id);
        $perPage = self::DEFAULT_COMMENTS_PER_PAGE;

        // Load first page of parent comments with their replies nested
        $commentsQuery = $post->comments()->parents()->with('author')->latestFirst();
        $paginator = $commentsQuery->paginate($perPage);
        $parentComments = $paginator->getCollection();

        $parentComments = PostComment::loadReplies($parentComments);
        PostComment::loadReactions($parentComments, $profileId);
        $post->setRelation('comments', $parentComments);

        return $post;
    }

    public function getComments(Post $post, array $params = []): LengthAwarePaginator
    {
        $perPage = (int) ($params['per_page'] ?? self::DEFAULT_COMMENTS_PER_PAGE);
        $user = Auth::guard('jwt')->user();
        $profileId = (int) ($user?->profile?->id);

        $commentsQuery = $post->comments()->parents()->with('author')->latestFirst();
        $paginator = $commentsQuery->paginate($perPage);

        $parentComments = $paginator->getCollection();
        $parentComments = PostComment::loadReplies($parentComments);
        PostComment::loadReactions($parentComments, $profileId);

        // Replace the collection on the paginator with the enriched one
        $paginator->setCollection($parentComments);

        return $paginator;
    }

    public function update(Post $post, array $data): Post
    {
        $this->ensureOwned($post);

        if (isset($data['content'])) {
            $post->content = $data['content'];
        }

        // Handle image: remove or upload
        if (! empty($data['remove_image'])) {
            app(ImageManager::class)->delete($post->image_url);
            $post->image_url = null;
        } elseif (isset($data['image'])) {
            app(ImageManager::class)->delete($post->image_url);
            $post->image_url = app(ImageManager::class)->upload($data['image'], 'posts');
        }

        // Handle video replacement
        if (isset($data['video'])) {
            if ($post->video_url && Storage::disk('public')->exists($post->video_url)) {
                Storage::disk('public')->delete($post->video_url);
            }
            $post->video_url = $data['video']->store('posts/videos', 'public');
        }

        // Handle attached item
        if (isset($data['attached_item_type'])) {
            $post->attached_item_type = $data['attached_item_type'];
        }
        if (isset($data['attached_item_id'])) {
            $post->attached_item_id = $data['attached_item_id'];
        }

        // Handle media array
        if (isset($data['media']) && is_array($data['media'])) {
            $mediaUrls = [];
            foreach ($data['media'] as $mediaFile) {
                $mediaUrls[] = app(ImageManager::class)->upload($mediaFile, 'posts/media');
            }
            $post->media = $mediaUrls;
        }

        if (isset($data['gif_url'])) {
            $post->gif_url = $data['gif_url'];
        }

        if (isset($data['emoji'])) {
            $post->emoji = $data['emoji'];
        }

        $post->save();

        return $post->load(['author', 'likes', 'saves']);
    }

    public function destroy(Post $post): void
    {
        $this->ensureOwned($post);

        // Clean up files
        app(ImageManager::class)->delete($post->image_url);
        app(ImageManager::class)->delete($post->video_url);

        if ($post->media) {
            foreach ($post->media as $file) {
                app(ImageManager::class)->delete($file);
            }
        }

        $post->delete();
    }

    public function toggleLike(Post $post): array
    {
        $profileId = (int) $this->profileId();

        $like = PostLike::forPost((int) $post->id)->byUser($profileId)->first();

        if ($like) {
            $like->delete();
            $post->decrement('like_count');
            $isLiked = false;
        } else {
            PostLike::create([
                'post_id' => $post->id,
                'user_id' => $profileId,
            ]);
            $post->increment('like_count');
            $isLiked = true;
        }

        if ($isLiked && (int) $post->author_id !== $profileId) {
            $name = Auth::guard('jwt')->user()?->profile?->full_name ?? 'Someone';
            $this->notificationManager->dispatch(
                (int) $post->author_id,
                'post.liked',
                'Post Liked',
                "{$name} liked your post.",
                ['post_id' => $post->id, 'liker_id' => $profileId]
            );
        }

        return [
            'is_liked' => $isLiked,
            'like_count' => $post->fresh()->like_count,
        ];
    }

    public function comment(Post $post, array $data): PostComment
    {
        $comment = PostComment::create([
            'post_id' => $post->id,
            'author_id' => (int) $this->profileId(),
            'content' => $data['content'],
        ]);

        $post->increment('comment_count');

        if ((int) $post->author_id !== (int) $comment->author_id) {
            $name = Auth::guard('jwt')->user()?->profile?->full_name ?? 'Someone';
            $this->notificationManager->dispatch(
                (int) $post->author_id,
                'post.commented',
                'New Comment',
                "{$name} commented on your post.",
                ['post_id' => $post->id, 'comment_id' => $comment->id]
            );
        }

        return $comment->load('author');
    }

    public function reply(Post $post, PostComment $parentComment, array $data): PostComment
    {
        if ((int) $parentComment->post_id !== (int) $post->id) {
            abort(404, 'Comment does not belong to this post.');
        }

        // Facebook-style: always attach replies to the root parent comment
        $rootParentId = $parentComment->parent_id ?? $parentComment->id;

        $reply = PostComment::create([
            'post_id' => $post->id,
            'author_id' => (int) $this->profileId(),
            'content' => $data['content'],
            'parent_id' => $rootParentId,
        ]);

        $post->increment('comment_count');

        if ((int) $parentComment->author_id !== (int) $reply->author_id) {
            $name = Auth::guard('jwt')->user()?->profile?->full_name ?? 'Someone';
            $this->notificationManager->dispatch(
                (int) $parentComment->author_id,
                'post.comment_replied',
                'New Reply',
                "{$name} replied to your comment.",
                ['post_id' => $post->id, 'comment_id' => $rootParentId, 'reply_id' => $reply->id]
            );
        }

        return $reply->load('author');
    }

    public function updateComment(PostComment $comment, array $data): PostComment
    {
        $this->ensureCommentOwned($comment);

        $comment->update([
            'content' => $data['content'],
        ]);

        return $comment->fresh()->load('author');
    }

    public function deleteComment(Post $post, PostComment $comment): void
    {
        $this->ensureCommentOwned($comment);

        // If deleting a parent comment, cascade-delete all replies first
        if (! $comment->parent_id) {
            $replyCount = PostComment::where('parent_id', $comment->id)->count();
            PostComment::where('parent_id', $comment->id)->delete();
            $post->decrement('comment_count', $replyCount);
        }

        $comment->delete();
        $post->decrement('comment_count');
    }

    public function toggleCommentReaction(PostComment $comment): array
    {
        $profileId = (int) $this->profileId();

        $existing = CommentReaction::forComment((int) $comment->id)
            ->byUser($profileId)
            ->first();

        if ($existing) {
            // Already reacted -> remove (toggle off)
            $existing->delete();
            $isReacted = false;
            $activeReaction = null;
        } else {
            // No existing reaction -> add a "like"
            CommentReaction::create([
                'comment_id' => $comment->id,
                'user_id' => $profileId,
                'reaction' => 'like',
            ]);
            $isReacted = true;
            $activeReaction = 'like';
        }

        if ($isReacted && (int) $comment->author_id !== $profileId) {
            $name = Auth::guard('jwt')->user()?->profile?->full_name ?? 'Someone';
            $this->notificationManager->dispatch(
                (int) $comment->author_id,
                'post.comment_reaction',
                'Comment Liked',
                "{$name} liked your comment.",
                ['comment_id' => $comment->id, 'reactor_id' => $profileId]
            );
        }

        // Get total reaction count
        $allReactions = CommentReaction::forComment((int) $comment->id)->get();

        return [
            'is_reacted' => $isReacted,
            'reaction' => $activeReaction,
            'reactions' => [
                'total' => $allReactions->count(),
            ],
        ];
    }

    public function toggleSave(Post $post): array
    {
        $profileId = (int) $this->profileId();

        $save = PostSave::forPost((int) $post->id)->byUser($profileId)->first();

        if ($save) {
            $save->delete();
            $isSaved = false;
        } else {
            PostSave::create([
                'post_id' => $post->id,
                'user_id' => $profileId,
            ]);
            $isSaved = true;
        }

        return [
            'is_saved' => $isSaved,
        ];
    }

    public function share(Post $post): array
    {
        $profileId = (int) $this->profileId();

        $existingShare = PostShare::forPost((int) $post->id)->byUser($profileId)->first();

        if ($existingShare) {
            $existingShare->delete();
            $isShared = false;
        } else {
            PostShare::create([
                'post_id' => $post->id,
                'user_id' => $profileId,
            ]);
            $post->increment('share_count');
            $isShared = true;
        }

        return [
            'is_shared' => $isShared,
            'share_count' => $post->fresh()->share_count,
        ];
    }

    public function reshare(Post $post, array $data): Post
    {
        $existingReshare = Post::where('original_post_id', $post->id)
            ->where('author_id', (int) $this->profileId())
            ->first();

        if ($existingReshare) {
            throw new \Exception('You have already reshared this post.', 409);
        }

        $resharePost = Post::create([
            'author_id' => (int) $this->profileId(),
            'content' => $data['content'] ?? '',
            'original_post_id' => $post->id,
        ]);

        $post->increment('share_count');

        return $resharePost->load(['author', 'originalPost.author']);
    }

    public function remix(Post $post, array $data): Post
    {
        if (! $post->is_active) {
            throw new \Exception('The original reel is no longer active.', 404);
        }

        if (empty($post->video_url)) {
            throw new \Exception('Only video posts or reels can be remixed.', 422);
        }

        $profileId = (int) $this->profileId();

        $postData = [
            'author_id' => $profileId,
            'content' => $data['content'] ?? '',
            'original_post_id' => $post->id,
            'video_url' => $data['video']->store('posts/videos', 'public'),
        ];

        if (isset($data['image'])) {
            $postData['image_url'] = app(ImageManager::class)->upload($data['image'], 'posts');
        }

        $remixPost = Post::create($postData);

        $post->increment('share_count');

        if ((int) $post->author_id !== $profileId) {
            $name = Auth::guard('jwt')->user()?->profile?->full_name ?? 'Someone';
            $this->notificationManager->dispatch(
                (int) $post->author_id,
                'post.remixed',
                'Reel Remixed',
                "{$name} remixed your reel.",
                [
                    'post_id' => $remixPost->id,
                    'original_post_id' => $post->id,
                    'remixer_id' => $profileId,
                ]
            );
        }

        return $remixPost->load(['author', 'originalPost.author', 'likes', 'saves']);
    }

    public function getRemixes(Post $post, int $perPage = 20)
    {
        return Post::active()
            ->where('original_post_id', $post->id)
            ->whereNotNull('video_url')
            ->with(['author', 'likes', 'saves', 'shares', 'originalPost.author'])
            ->latestFirst()
            ->paginate($perPage);
    }

    public function report(Post $post, array $data): array
    {
        $reporterId = (int) $this->profileId();

        // Check if the user already reported this post
        $existing = PostReport::where('post_id', $post->id)
            ->where('reporter_id', $reporterId)
            ->first();

        if ($existing) {
            throw new \Exception('You have already reported this post.', 409);
        }

        // Store the report with reason
        PostReport::create([
            'post_id' => $post->id,
            'reporter_id' => $reporterId,
            'reason' => $data['reason'],
        ]);

        // Mark post as reported (but don't auto-deactivate — admin decides)
        $post->update(['is_reported' => true]);

        return [
            'message' => 'Post reported successfully. An admin will review it shortly.',
        ];
    }

    protected function ensureOwned(Post $post): void
    {
        if ((int) $post->author_id !== (int) $this->profileId()) {
            abort(404);
        }
    }

    protected function ensureCommentOwned(PostComment $comment): void
    {
        if ((int) $comment->author_id !== (int) $this->profileId()) {
            abort(404);
        }
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
