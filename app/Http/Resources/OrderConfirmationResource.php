<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use App\Models\Product;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderConfirmationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request): array
    {
        // Ensure items and their relations are loaded to avoid N+1 queries
        $this->relationLoaded('items') ?: $this->load('items.product', 'items.service', 'items.event');

        $items = $this->items->map(function ($item) {
            $imageUrl = null;
            
            if ($item->product) {
                $imageUrl = ImageManager::fileUrl($item->product->image_url);
            } elseif ($item->service) {
                $imageUrl = ImageManager::fileUrl($item->service->image_url);
            } elseif ($item->event) {
                $imageUrl = ImageManager::fileUrl($item->event->image_url);
            }

            // If the image URL is returned as an array, extract the first one
            if (is_array($imageUrl)) {
                $imageUrl = $imageUrl[0] ?? null;
            }

            return [
                'id' => $item->id,
                'title' => $item->title,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total_price' => (float) $item->total_price,
                'image_url' => $imageUrl,
            ];
        });

        // Resolve a user-friendly Payment Method
        $paymentMethod = 'Card'; // Default fallback
        if ($this->transaction) {
            $paymentMethod = $this->transaction->stripe_payment_id ? 'Card' : 'Stripe';
        }

        $purchasedProductIds = $this->items->pluck('product_id')->filter()->unique()->toArray();
        $categoryIds = $this->items->pluck('product.category_id')->filter()->unique()->toArray();

        // Similar Products: items from the same category as purchased
        $similarProducts = ! empty($categoryIds)
            ? Product::where('is_active', true)
                ->whereIn('category_id', $categoryIds)
                ->whereNotIn('id', $purchasedProductIds)
                ->latest()
                ->take(4)
                ->get()
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'title' => $p->title,
                    'price' => (float) $p->price,
                    'image_url' => ImageManager::fileUrl($p->image_url),
                    'is_aliexpress' => $p->source === 'aliexpress' || ! empty($p->aliexpress_product_id),
                ])
                ->values()
            : [];

        // Trending Products: active popular items to continue shopping
        $trendingProducts = Product::where('is_active', true)
            ->whereNotIn('id', $purchasedProductIds)
            ->orderBy('id', 'desc')
            ->take(6)
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'title' => $p->title,
                'price' => (float) $p->price,
                'image_url' => ImageManager::fileUrl($p->image_url),
                'is_aliexpress' => $p->source === 'aliexpress' || ! empty($p->aliexpress_product_id),
            ])
            ->values();

        return [
            'id' => $this->id,
            'order_id' => $this->tracking_number ?: 'ORD-' . $this->id,
            'status' => $this->status,
            'items' => $items,
            'total' => (float) $this->total,
            'payment_method' => $paymentMethod,
            'order_date' => $this->created_at ? $this->created_at->format('n/j/Y') : null, // e.g. "8/24/2026"
            'shipping_address' => $this->shipping_address,
            'continue_shopping' => [
                'redirect_url' => '/api/products',
                'trending_products' => $trendingProducts,
                'similar_products' => $similarProducts,
            ],
        ];
    }
}
