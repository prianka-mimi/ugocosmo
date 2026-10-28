<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewIndexRequest;
use App\Http\Requests\StoreReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Manager\ReviewManager;
use App\Models\Event;
use App\Models\EventReview;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Service;
use App\Models\ServiceReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReviewController extends Controller
{
    protected ReviewManager $reviewManager;

    public function __construct(ReviewManager $reviewManager)
    {
        $this->reviewManager = $reviewManager;
    }

    // ─── Product Reviews ──────────────────────────────────────────

    public function productIndex(ReviewIndexRequest $request, Product $product): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            $reviews = $this->reviewManager->productReviews((int) $product->id, $perPage);
            $average = $this->reviewManager->productAverage((int) $product->id);

            $this->data = [
                'average' => $average,
                'reviews' => ReviewResource::collection($reviews)->response()->getData(),
            ];
            $this->status_message = 'Product reviews retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Product reviews retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function productStore(StoreReviewRequest $request, Product $product): JsonResponse
    {
        try {
            DB::beginTransaction();
            $review = $this->reviewManager->createProductReview((int) $product->id, $request->validated());
            DB::commit();

            $this->data = ReviewResource::make($review);
            $this->status_message = 'Product review created';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Product review create error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function productUpdate(StoreReviewRequest $request, ProductReview $review): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = ReviewResource::make($this->reviewManager->updateProductReview($review, $request->validated()));
            DB::commit();

            $this->status_message = 'Product review updated';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Product review update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function productDestroy(ProductReview $review): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->reviewManager->deleteProductReview($review);
            DB::commit();

            $this->data = [];
            $this->status_message = 'Product review deleted';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Product review delete error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    // ─── Service Reviews ──────────────────────────────────────────

    public function serviceIndex(ReviewIndexRequest $request, Service $service): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            $reviews = $this->reviewManager->serviceReviews((int) $service->id, $perPage);
            $average = $this->reviewManager->serviceAverage((int) $service->id);

            $this->data = [
                'average' => $average,
                'reviews' => ReviewResource::collection($reviews)->response()->getData(),
            ];
            $this->status_message = 'Service reviews retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Service reviews retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function serviceStore(StoreReviewRequest $request, Service $service): JsonResponse
    {
        try {
            DB::beginTransaction();
            $review = $this->reviewManager->createServiceReview((int) $service->id, $request->validated());
            DB::commit();

            $this->data = ReviewResource::make($review);
            $this->status_message = 'Service review created';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Service review create error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function serviceUpdate(StoreReviewRequest $request, ServiceReview $review): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = ReviewResource::make($this->reviewManager->updateServiceReview($review, $request->validated()));
            DB::commit();

            $this->status_message = 'Service review updated';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Service review update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function serviceDestroy(ServiceReview $review): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->reviewManager->deleteServiceReview($review);
            DB::commit();

            $this->data = [];
            $this->status_message = 'Service review deleted';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Service review delete error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    // ─── Event Reviews ────────────────────────────────────────────

    public function eventIndex(ReviewIndexRequest $request, Event $event): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            $reviews = $this->reviewManager->eventReviews((int) $event->id, $perPage);
            $average = $this->reviewManager->eventAverage((int) $event->id);

            $this->data = [
                'average' => $average,
                'reviews' => ReviewResource::collection($reviews)->response()->getData(),
            ];
            $this->status_message = 'Event reviews retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Event reviews retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function eventStore(StoreReviewRequest $request, Event $event): JsonResponse
    {
        try {
            DB::beginTransaction();
            $review = $this->reviewManager->createEventReview((int) $event->id, $request->validated());
            DB::commit();

            $this->data = ReviewResource::make($review);
            $this->status_message = 'Event review created';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Event review create error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function eventUpdate(StoreReviewRequest $request, EventReview $review): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = ReviewResource::make($this->reviewManager->updateEventReview($review, $request->validated()));
            DB::commit();

            $this->status_message = 'Event review updated';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Event review update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function eventDestroy(EventReview $review): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->reviewManager->deleteEventReview($review);
            DB::commit();

            $this->data = [];
            $this->status_message = 'Event review deleted';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Event review delete error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
