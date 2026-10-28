<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventReview;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Profile;
use App\Models\Service;
use App\Models\ServiceReview;
use Illuminate\Database\Seeder;

class ReviewSeeder extends Seeder
{
    public function run(): void
    {
        $david = Profile::whereHas('user', fn ($q) => $q->where('email', 'david@example.com'))->first();
        $bob = Profile::whereHas('user', fn ($q) => $q->where('email', 'bob@example.com'))->first();

        if (! $david || ! $bob) {
            return;
        }

        // Product reviews
        Product::all()->each(function ($product) use ($david) {
            ProductReview::firstOrCreate(['product_id' => $product->id, 'reviewer_id' => $david->id], [
                'product_id' => $product->id,
                'reviewer_id' => $david->id,
                'rating' => rand(4, 5),
                'review_text' => 'Great product, highly recommend!',
            ]);
        });

        // Service reviews
        Service::all()->each(function ($service) use ($david) {
            ServiceReview::firstOrCreate(['service_id' => $service->id, 'reviewer_id' => $david->id], [
                'service_id' => $service->id,
                'reviewer_id' => $david->id,
                'rating' => rand(4, 5),
                'review_text' => 'Excellent service, very professional.',
            ]);
        });

        // Event reviews
        Event::all()->each(function ($event) use ($bob) {
            EventReview::firstOrCreate(['event_id' => $event->id, 'reviewer_id' => $bob->id], [
                'event_id' => $event->id,
                'reviewer_id' => $bob->id,
                'rating' => rand(4, 5),
                'review_text' => 'Fantastic event, well organised!',
            ]);
        });
    }
}
