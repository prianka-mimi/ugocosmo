<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoryIndexRequest;
use App\Http\Requests\StoryStoreRequest;
use App\Http\Resources\StoryResource;
use App\Manager\StoryManager;
use App\Models\Profile;
use App\Models\Story;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class StoryController extends Controller
{
    protected StoryManager $storyManager;

    public function __construct(StoryManager $storyManager)
    {
        $this->storyManager = $storyManager;
    }

    public function index(StoryIndexRequest $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            $this->data = $this->storyManager->active($request->validated(), $perPage);
            $this->status_message = 'Stories retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Stories retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function mine(StoryIndexRequest $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            $this->data = $this->storyManager->mine($request->validated(), $perPage);
            $this->status_message = 'Your stories retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('My stories retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function show(Story $story): JsonResponse
    {
        try {
            $this->data = $this->storyManager->show($story);
            $this->status_message = 'Story retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Story retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function store(StoryStoreRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = StoryResource::make($this->storyManager->store($request->validated()));
            DB::commit();

            $this->status_message = 'Story created successfully';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Story creation error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function destroy(Story $story): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->storyManager->destroy($story);
            DB::commit();

            $this->data = [];
            $this->status_message = 'Story deleted successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Story deletion error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function views(Story $story): JsonResponse
    {
        try {
            $perPage = (int) request()->input('per_page', 20);
            $this->data = $this->storyManager->views($story, $perPage);
            $this->status_message = 'Story viewers retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Story viewers retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function reactions(Story $story): JsonResponse
    {
        try {
            $perPage = (int) request()->input('per_page', 20);
            $this->data = $this->storyManager->reactions($story, $perPage);
            $this->status_message = 'Story reactions retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Story reactions retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function userStories(Profile $profile): JsonResponse
    {
        try {
            $perPage = (int) request()->input('per_page', 20);
            $this->data = $this->storyManager->byUser($profile, $perPage);
            $this->status_message = 'User stories retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('User stories retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function react(Story $story): JsonResponse
    {
        try {
            $isReacting = request()->method() === 'POST';

            DB::beginTransaction();
            $this->data = $this->storyManager->toggleReaction($story, $isReacting);
            DB::commit();

            $this->status_message = $isReacting ? 'Story reacted' : 'Story reaction removed';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Story reaction error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function markViewed(Story $story): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->storyManager->markViewed($story);
            DB::commit();

            $this->data = [];
            $this->status_message = 'Story marked as viewed';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Story view error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
