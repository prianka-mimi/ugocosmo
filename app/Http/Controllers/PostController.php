<?php

namespace App\Http\Controllers;

use App\Http\Requests\PostCommentRequest;
use App\Http\Requests\PostIndexRequest;
use App\Http\Requests\PostRemixRequest;
use App\Http\Requests\PostReportRequest;
use App\Http\Requests\PostStoreRequest;
use App\Http\Requests\PostUpdateRequest;
use App\Http\Resources\PostResource;
use App\Manager\PostManager;
use App\Models\Post;
use App\Models\PostComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostController extends Controller
{
    protected PostManager $postManager;

    public function __construct(PostManager $postManager)
    {
        $this->postManager = $postManager;
    }

    public function index(PostIndexRequest $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            $this->data = PostResource::collection($this->postManager->feed($request->validated(), $perPage))->response()->getData();
            $this->status_message = 'Posts retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Posts retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function show(Post $post): JsonResponse
    {
        try {
            $this->data = PostResource::make($this->postManager->show($post));
            $this->status_message = 'Post retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Post retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function comments(Post $post): JsonResponse
    {
        try {
            $perPage = (int) request()->input('per_page', PostManager::DEFAULT_COMMENTS_PER_PAGE);
            $params = ['per_page' => max(1, min(50, $perPage))];

            $paginator = $this->postManager->getComments($post, $params);

            $this->data = collect($paginator->items())->map(fn (PostComment $comment) => PostResource::formatComment($comment))->values();

            $this->pagination = [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ];

            $this->status_message = 'Comments retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Comments retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function reply(PostCommentRequest $request, Post $post, PostComment $comment): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->postManager->reply($post, $comment, $request->validated());
            DB::commit();

            $this->status_message = 'Reply added successfully';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Post reply error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function store(PostStoreRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = PostResource::make($this->postManager->store($request->validated()));
            DB::commit();

            $this->status_message = 'Post created successfully';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Post creation error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function update(PostUpdateRequest $request, Post $post): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = PostResource::make($this->postManager->update($post, $request->validated()));
            DB::commit();

            $this->status_message = 'Post updated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Post update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function destroy(Post $post): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->postManager->destroy($post);
            DB::commit();

            $this->data = [];
            $this->status_message = 'Post deleted successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Post deletion error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function like(Post $post): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->postManager->toggleLike($post);
            DB::commit();

            $this->status_message = $this->data['is_liked'] ? 'Post liked' : 'Post unliked';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Post like error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function comment(PostCommentRequest $request, Post $post): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->postManager->comment($post, $request->validated());
            DB::commit();

            $this->status_message = 'Comment added successfully';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Post comment error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function deleteComment(Post $post, PostComment $comment): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->postManager->deleteComment($post, $comment);
            DB::commit();

            $this->data = [];
            $this->status_message = 'Comment deleted successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Comment deletion error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function updateComment(PostCommentRequest $request, Post $post, PostComment $comment): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->postManager->updateComment($comment, $request->validated());
            DB::commit();

            $this->status_message = 'Comment updated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Comment update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function react(Post $post, PostComment $comment): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->postManager->toggleCommentReaction($comment);
            DB::commit();

            $this->status_message = $this->data['is_reacted']
                ? 'Comment liked'
                : 'Comment unliked';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Comment reaction error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function save(Post $post): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->postManager->toggleSave($post);
            DB::commit();

            $this->status_message = $this->data['is_saved'] ? 'Post saved' : 'Post unsaved';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Post save error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function share(Post $post): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->postManager->share($post);
            DB::commit();

            $this->status_message = $this->data['is_shared'] ? 'Post shared' : 'Post unshared';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Post share error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function reshare(Post $post): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = PostResource::make($this->postManager->reshare($post, request()->all()));
            DB::commit();

            $this->status_message = 'Post reshared successfully';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Post reshare error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function remix(PostRemixRequest $request, Post $post): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = PostResource::make($this->postManager->remix($post, $request->validated()));
            DB::commit();

            $this->status_message = 'Reel remixed successfully';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Reel remix error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function remixes(Post $post): JsonResponse
    {
        try {
            $perPage = (int) request()->input('per_page', 20);
            $paginator = $this->postManager->getRemixes($post, $perPage);
            $this->data = PostResource::collection($paginator)->response()->getData();
            $this->status_message = 'Remixes retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Remixes retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function report(PostReportRequest $request, Post $post): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->postManager->report($post, $request->validated());
            DB::commit();

            $this->status_message = 'Post reported successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Post report error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
