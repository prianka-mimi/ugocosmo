<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConnectionIndexRequest;
use App\Http\Requests\SendConnectionRequest;
use App\Http\Resources\EventResource;
use App\Http\Resources\JobResource;
use App\Http\Resources\PostResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ServiceResource;
use App\Manager\ConnectionManager;
use App\Manager\ImageManager;
use App\Models\Event;
use App\Models\JobListing;
use App\Models\Post;
use App\Models\Product;
use App\Models\Profile;
use App\Models\Qualification;
use App\Models\Service;
use App\Models\UserConnection;
use App\Models\WorkHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConnectionController extends Controller
{
    protected ConnectionManager $connectionManager;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connectionManager = $connectionManager;
    }

    public function index(ConnectionIndexRequest $request): JsonResponse
    {
        try {
            // $perPage = (int) $request->input('per_page', 20);
            $this->data = $this->connectionManager->connections($request->validated());
            $this->status_message = 'Connections retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Connections retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function pendingRequests(ConnectionIndexRequest $request): JsonResponse
    {
        try {
            $this->data = $this->connectionManager->pendingRequests($request->validated());
            $this->status_message = 'Pending requests retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Pending requests retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function sentRequests(ConnectionIndexRequest $request): JsonResponse
    {
        try {
            $this->data = $this->connectionManager->sentRequests($request->validated());
            $this->status_message = 'Sent requests retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Sent requests retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function sendRequest(SendConnectionRequest $request, Profile $profile): JsonResponse
    {
        try {
            DB::beginTransaction();
            $connection = $this->connectionManager->sendRequest($profile, $request->input('connection_type'));
            DB::commit();

            $this->data = [
                'id' => $connection->id,
                'status' => $connection->status,
                'connection_type' => $connection->connection_type,
            ];
            $this->status_message = 'Connection request sent';
            $this->status_code = $connection->wasRecentlyCreated ? 201 : 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Connection request error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function acceptRequest(UserConnection $connection): JsonResponse
    {
        try {
            DB::beginTransaction();
            $updated = $this->connectionManager->acceptRequest($connection);
            DB::commit();

            $this->data = [
                'id' => $updated->id,
                'status' => $updated->status,
            ];
            $this->status_message = 'Connection request accepted';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Accept request error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function declineRequest(UserConnection $connection): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->connectionManager->declineRequest($connection);
            DB::commit();

            $this->data = [];
            $this->status_message = 'Connection request declined';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Decline request error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function removeConnection(UserConnection $connection): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->connectionManager->removeConnection($connection);
            DB::commit();

            $this->data = [];
            $this->status_message = 'Connection removed';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Remove connection error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function follow(Profile $profile): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->connectionManager->follow($profile);
            DB::commit();

            $this->status_message = 'Now following user';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Follow user error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function unfollow(Profile $profile): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->connectionManager->unfollow($profile);
            DB::commit();

            $this->status_message = 'Unfollowed user';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Unfollow user error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function block(Profile $profile): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->connectionManager->block($profile);
            DB::commit();

            $this->status_message = 'User blocked';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Block user error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function unblock(Profile $profile): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->connectionManager->unblock($profile);
            DB::commit();

            $this->status_message = 'User unblocked';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Unblock user error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function blockedUsers(ConnectionIndexRequest $request): JsonResponse
    {
        try {
            $this->data = $this->connectionManager->blockedUsers($request->validated());
            $this->status_message = 'Blocked users retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Blocked users retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function showProfile(Profile $profile): JsonResponse
    {
        try {
            $currentProfileId = (int) auth()->guard('jwt')->user()?->profile?->id;

            // Fetch products, events, jobs, services
            $products = Product::where('seller_id', $profile->id)->get();
            $events = Event::where('organizer_id', $profile->id)->get();
            $jobs = JobListing::where('poster_id', $profile->id)->get();
            $services = Service::where('provider_id', $profile->id)->get();
            $posts = Post::where('author_id', $profile->id)->get();

            // Fetch counts
            $totalPosts = Post::where('author_id', $profile->id)->count();
            $totalFollowers = UserConnection::where('recipient_id', $profile->id)
                ->where('connection_type', 'follow')
                ->where('status', 'accepted')
                ->count();
            $totalFollowing = UserConnection::where('requester_id', $profile->id)
                ->where('connection_type', 'follow')
                ->where('status', 'accepted')
                ->count();

            $this->data = [
                'id' => $profile->id,
                'full_name' => $profile->full_name,
                'username' => $profile->username,
                'avatar_url' => ImageManager::getImageUrl($profile->avatar_url),
                'cover_photo' => ImageManager::getImageUrl($profile->user?->cover_photo),
                'bio' => $profile->bio,
                'location' => $profile->location,
                'website' => $profile->website,
                'is_verified' => $profile->user ? (bool) $profile->user->is_verified : false,
                'is_blocked' => $currentProfileId
                    ? $this->connectionManager->isBlocked($currentProfileId, (int) $profile->id)
                    : false,
                'is_connected' => $currentProfileId
                    ? $this->connectionManager->isConnected($currentProfileId, (int) $profile->id)
                    : false,
                'total_posts' => $totalPosts,
                'total_followers' => $totalFollowers,
                'total_following' => $totalFollowing,
                'posts' => PostResource::collection($posts),
                'shop' => ['products' => ProductResource::collection($products),
                    'services' => ServiceResource::collection($services),
                ],

                'events' => EventResource::collection($events),
                'jobs' => JobResource::collection($jobs),

            ];
            $this->status_message = 'Profile retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Profile retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function aboutProfile(Profile $profile): JsonResponse
    {
        try {
            $user = $profile->user;
            if (! $user) {
                throw new \Exception('Associated user not found', 404);
            }

            $employmentHistory = WorkHistory::forUser($user->id)
                ->ordered()
                ->get()
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'company_name' => $item->company_name,
                    'position_title' => $item->position_title,
                    'start_date' => $item->start_date?->toDateString(),
                    'end_date' => $item->end_date?->toDateString(),
                    'job_description' => $item->job_description,
                    'is_currently_working' => (bool) $item->is_currently_working,
                ]);

            $educationHistory = Qualification::forUser($user->id)
                ->ordered()
                ->get()
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'institute_name' => $item->institute_name,
                    'degree' => $item->degree,
                    'field_of_study' => $item->field_of_study,
                    'start_date' => $item->start_date?->toDateString(),
                    'end_date' => $item->end_date?->toDateString(),
                ]);

            $this->data = [
                'id' => $profile->id,
                'full_name' => $profile->full_name,
                'username' => $profile->username,
                'avatar_url' => ImageManager::getImageUrl($profile->avatar_url),
                'bio' => $profile->bio ?? ($user->bio ?? null),
                'location' => $profile->location,
                'age' => $user->dob ? (int) $user->dob->age : null,
                'dob' => $user->dob ? $user->dob->toDateString() : null,
                'email' => $user->email,
                'mobile_number' => $user->mobile_number,
                'website' => $profile->website,
                'is_verified' => (bool) $user->is_verified,
                'cv_resume' => ImageManager::fileUrl($user->cv_resume),
                'skills' => $user->skills ?? [],
                'interests' => $user->interests ?? [],
                'employment_history' => $employmentHistory,
                'education_history' => $educationHistory,
            ];
            $this->status_message = 'About profile retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('About profile retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function myFollowers(ConnectionIndexRequest $request): JsonResponse
    {
        try {
            $currentProfileId = (int) auth()->guard('jwt')->user()?->profile?->id;

            $this->data = $this->connectionManager->followers($currentProfileId);
            $this->status_message = 'Followers retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('My followers retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function myFollowing(ConnectionIndexRequest $request): JsonResponse
    {
        try {
            $currentProfileId = (int) auth()->guard('jwt')->user()?->profile?->id;

            $this->data = $this->connectionManager->following($currentProfileId);
            $this->status_message = 'Following retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('My following retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function userFollowers(ConnectionIndexRequest $request, Profile $profile): JsonResponse
    {
        try {
            $this->data = $this->connectionManager->followers($profile->id);
            $this->status_message = 'User followers retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('User followers retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function userFollowing(ConnectionIndexRequest $request, Profile $profile): JsonResponse
    {
        try {
            $this->data = $this->connectionManager->following($profile->id);
            $this->status_message = 'User following retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('User following retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
