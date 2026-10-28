<?php

namespace App\Manager;

use App\Models\EventReview;
use App\Models\ProductReview;
use App\Models\ServiceReview;
use Illuminate\Support\Facades\Auth;

class ReviewManager
{
    // ─── Product Reviews ───────────────────────────────────────────

    public function productReviews(int $productId, int $perPage = 20)
    {
        return ProductReview::forProduct($productId)
            ->latestFirst()
            ->with('reviewer')
            ->paginate($perPage);
    }

    public function productAverage(int $productId): array
    {
        $stats = ProductReview::forProduct($productId)
            ->selectRaw('COUNT(*) as total_reviews, ROUND(AVG(rating), 1) as average_rating')
            ->first();

        return [
            'average_rating' => (float) ($stats->average_rating ?? 0),
            'total_reviews' => (int) ($stats->total_reviews ?? 0),
        ];
    }

    public function createProductReview(int $productId, array $data): ProductReview
    {
        $profileId = (int) $this->profileId();

        $existing = ProductReview::forProduct($productId)->byReviewer($profileId)->first();
        if ($existing) {
            throw new \Exception('You have already reviewed this product. Update your existing review instead.', 422);
        }

        return ProductReview::create([
            'product_id' => $productId,
            'reviewer_id' => $profileId,
            'rating' => $data['rating'],
            'review_text' => $data['review_text'] ?? null,
        ])->load('reviewer');
    }

    public function updateProductReview(ProductReview $review, array $data): ProductReview
    {
        $this->ensureOwned($review);
        $review->update($data);

        return $review->fresh()->load('reviewer');
    }

    public function deleteProductReview(ProductReview $review): void
    {
        $this->ensureOwned($review);
        $review->delete();
    }

    // ─── Service Reviews ───────────────────────────────────────────

    public function serviceReviews(int $serviceId, int $perPage = 20)
    {
        return ServiceReview::forService($serviceId)
            ->latestFirst()
            ->with('reviewer')
            ->paginate($perPage);
    }

    public function serviceAverage(int $serviceId): array
    {
        $stats = ServiceReview::forService($serviceId)
            ->selectRaw('COUNT(*) as total_reviews, ROUND(AVG(rating), 1) as average_rating')
            ->first();

        return [
            'average_rating' => (float) ($stats->average_rating ?? 0),
            'total_reviews' => (int) ($stats->total_reviews ?? 0),
        ];
    }

    public function createServiceReview(int $serviceId, array $data): ServiceReview
    {
        $profileId = (int) $this->profileId();

        $existing = ServiceReview::forService($serviceId)->byReviewer($profileId)->first();
        if ($existing) {
            throw new \Exception('You have already reviewed this service. Update your existing review instead.', 422);
        }

        return ServiceReview::create([
            'service_id' => $serviceId,
            'reviewer_id' => $profileId,
            'rating' => $data['rating'],
            'review_text' => $data['review_text'] ?? null,
        ])->load('reviewer');
    }

    public function updateServiceReview(ServiceReview $review, array $data): ServiceReview
    {
        $this->ensureOwned($review);
        $review->update($data);

        return $review->fresh()->load('reviewer');
    }

    public function deleteServiceReview(ServiceReview $review): void
    {
        $this->ensureOwned($review);
        $review->delete();
    }

    // ─── Event Reviews ─────────────────────────────────────────────

    public function eventReviews(int $eventId, int $perPage = 20)
    {
        return EventReview::forEvent($eventId)
            ->latestFirst()
            ->with('reviewer')
            ->paginate($perPage);
    }

    public function eventAverage(int $eventId): array
    {
        $stats = EventReview::forEvent($eventId)
            ->selectRaw('COUNT(*) as total_reviews, ROUND(AVG(rating), 1) as average_rating')
            ->first();

        return [
            'average_rating' => (float) ($stats->average_rating ?? 0),
            'total_reviews' => (int) ($stats->total_reviews ?? 0),
        ];
    }

    public function createEventReview(int $eventId, array $data): EventReview
    {
        $profileId = (int) $this->profileId();

        $existing = EventReview::forEvent($eventId)->byReviewer($profileId)->first();
        if ($existing) {
            throw new \Exception('You have already reviewed this event. Update your existing review instead.', 422);
        }

        return EventReview::create([
            'event_id' => $eventId,
            'reviewer_id' => $profileId,
            'rating' => $data['rating'],
            'review_text' => $data['review_text'] ?? null,
        ])->load('reviewer');
    }

    public function updateEventReview(EventReview $review, array $data): EventReview
    {
        $this->ensureOwned($review);
        $review->update($data);

        return $review->fresh()->load('reviewer');
    }

    public function deleteEventReview(EventReview $review): void
    {
        $this->ensureOwned($review);
        $review->delete();
    }

    // ─── Shared Helpers ────────────────────────────────────────────

    protected function ensureOwned($review): void
    {
        if ((int) $review->reviewer_id !== (int) $this->profileId()) {
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
