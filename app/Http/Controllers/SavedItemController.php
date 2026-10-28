<?php

namespace App\Http\Controllers;

use App\Http\Resources\EventResource;
use App\Http\Resources\JobResource;
use App\Http\Resources\PostResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ServiceResource;
use App\Models\Event;
use App\Models\EventSave;
use App\Models\JobListing;
use App\Models\JobSave;
use App\Models\Post;
use App\Models\PostSave;
use App\Models\Product;
use App\Models\ProductSave;
use App\Models\Service;
use App\Models\ServiceSave;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class SavedItemController extends Controller
{
    /**
     * Get the authenticated profile ID.
     */
    protected function profileId(): int
    {
        $user = Auth::guard('jwt')->user();
        $profile = $user?->profile;

        if (! $profile) {
            throw new \Exception('Profile not found. Complete your profile first.', 400);
        }

        return (int) $profile->id;
    }

    /**
     * Toggle save status of a product, service, job, or event.
     */
    public function toggle(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'type' => 'required|string|in:product,service,job,event,post',
                'id' => 'required|integer',
            ]);

            $type = $request->input('type');
            $id = (int) $request->input('id');
            $profileId = $this->profileId();
            $isSaved = false;

            switch ($type) {
                case 'product':
                    $item = Product::findOrFail($id);
                    $existing = ProductSave::where('user_id', $profileId)->where('product_id', $id)->first();
                    if ($existing) {
                        $existing->delete();
                    } else {
                        ProductSave::create(['user_id' => $profileId, 'product_id' => $id]);
                        $isSaved = true;
                    }
                    break;

                case 'service':
                    $item = Service::findOrFail($id);
                    $existing = ServiceSave::where('user_id', $profileId)->where('service_id', $id)->first();
                    if ($existing) {
                        $existing->delete();
                    } else {
                        ServiceSave::create(['user_id' => $profileId, 'service_id' => $id]);
                        $isSaved = true;
                    }
                    break;

                case 'job':
                    $item = JobListing::findOrFail($id);
                    $existing = JobSave::where('user_id', $profileId)->where('job_id', $id)->first();
                    if ($existing) {
                        $existing->delete();
                    } else {
                        JobSave::create(['user_id' => $profileId, 'job_id' => $id]);
                        $isSaved = true;
                    }
                    break;

                case 'event':
                    $item = Event::findOrFail($id);
                    $existing = EventSave::where('user_id', $profileId)->where('event_id', $id)->first();
                    if ($existing) {
                        $existing->delete();
                    } else {
                        EventSave::create(['user_id' => $profileId, 'event_id' => $id]);
                        $isSaved = true;
                    }
                    break;

                case 'post':
                    $item = Post::findOrFail($id);
                    $existing = PostSave::where('user_id', $profileId)->where('post_id', $id)->first();
                    if ($existing) {
                        $existing->delete();
                    } else {
                        PostSave::create(['user_id' => $profileId, 'post_id' => $id]);
                        $isSaved = true;
                    }
                    break;
            }

            $this->data = [
                'is_saved' => $isSaved,
            ];
            $this->status_message = ucfirst($type).($isSaved ? ' saved successfully' : ' removed from saved items');
            $this->status_code = 200;

        } catch (Throwable $e) {
            Log::error('SavedItem toggle error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Get list of saved items. Can filter by type using ?type=product|service|job|event.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $type = $request->query('type');
            $profileId = $this->profileId();

            $products = [];
            $services = [];
            $jobs = [];
            $events = [];
            $posts = [];

            if (! $type || $type === 'product') {
                $savedProducts = ProductSave::where('user_id', $profileId)->with('product.seller.user')->get();
                $products = ProductResource::collection($savedProducts->map(fn ($ps) => $ps->product)->filter());
            }

            if (! $type || $type === 'service') {
                $savedServices = ServiceSave::where('user_id', $profileId)->with('service.provider.user')->get();
                $services = ServiceResource::collection($savedServices->map(fn ($ss) => $ss->service)->filter());
            }

            if (! $type || $type === 'job') {
                $savedJobs = JobSave::where('user_id', $profileId)->with('job.poster.user')->get();
                $jobs = JobResource::collection($savedJobs->map(fn ($js) => $js->job)->filter());
            }

            if (! $type || $type === 'event') {
                $savedEvents = EventSave::where('user_id', $profileId)->with('event.organizer.user')->get();
                $events = EventResource::collection($savedEvents->map(fn ($es) => $es->event)->filter());
            }

            if (! $type || $type === 'post') {
                $savedPosts = PostSave::where('user_id', $profileId)->with('post.author')->get();
                $posts = PostResource::collection($savedPosts->map(fn ($ps) => $ps->post)->filter());
            }

            if ($type) {
                // If specific type requested, return only that list under data key
                switch ($type) {
                    case 'product': $this->data = $products;
                        break;
                    case 'service': $this->data = $services;
                        break;
                    case 'job': $this->data = $jobs;
                        break;
                    case 'event': $this->data = $events;
                        break;
                    case 'post': $this->data = $posts;
                        break;
                }
            } else {
                // Return grouped list
                $this->data = [
                    'products' => $products,
                    'services' => $services,
                    'jobs' => $jobs,
                    'events' => $events,
                    'posts' => $posts,
                ];
            }

            $this->status_message = 'Saved items retrieved successfully';
            $this->status_code = 200;

        } catch (Throwable $e) {
            Log::error('SavedItem index error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
