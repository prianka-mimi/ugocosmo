<?php

namespace App\Services;

use App\DTOs\Etsy\EtsyListingDTO;
use App\Exceptions\Etsy\EtsyApiException;
use App\Models\EtsyAccount;
use Illuminate\Support\Facades\Log;

/**
 * Fetches listings from the Etsy Open API v3.
 *
 * Endpoints used (all reads are idempotent and retryable):
 *   GET /listings/active              — keyword search + pagination
 *   GET /listings/{listing_id}        — single listing detail
 *   GET /listings/{listing_id}/images — listing images
 *
 * When an EtsyAccount is supplied the request is made as the connected
 * seller (Bearer token, auto-refreshed) — the same-to-same behavior as the
 * AliExpress import, which always operates through a connected account.
 */
class EtsyListingService
{
    public function __construct(
        protected EtsyApiClient $client,
    ) {}

    /**
     * Search active listings by keyword.
     *
     * @return array{listings: array<int, EtsyListingDTO>, total: int, offset: int}
     */
    public function search(string $keyword, int $limit = 20, int $offset = 0, ?EtsyAccount $account = null): array
    {
        $limit = max(1, min($limit, (int) config('services.etsy.page_limit', 100)));

        $data = $this->client->get('/listings/active', [
            'keywords' => $keyword !== '' ? $keyword : null,
            'limit' => $limit,
            'offset' => max(0, $offset),
        ], $account);

        $listings = [];

        foreach ((array) ($data['results'] ?? []) as $listing) {
            try {
                $listings[] = EtsyListingDTO::fromApiResponse($listing);
            } catch (EtsyApiException $e) {
                // Skip malformed entries without failing the whole page.
                Log::warning('EtsyListingService: skipping malformed listing', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'listings' => $listings,
            'total' => (int) ($data['count'] ?? count($listings)),
            'offset' => max(0, $offset),
        ];
    }

    /**
     * Fetch a single listing (with images attached).
     */
    public function getListing(int $listingId, ?EtsyAccount $account = null): EtsyListingDTO
    {
        $data = $this->client->get("/listings/{$listingId}", [], $account);

        $dto = EtsyListingDTO::fromApiResponse((array) $data);

        return $this->withImages($dto, $account);
    }

    /**
     * Fetch listing images and return the DTO with images attached.
     */
    public function withImages(EtsyListingDTO $dto, ?EtsyAccount $account = null): EtsyListingDTO
    {
        $images = $this->getImages($dto->listingId, $account);

        return $images === []
            ? $dto
            : new EtsyListingDTO(...array_merge($this->toArray($dto), ['images' => $images]));
    }

    /**
     * Fetch listing images.
     *
     * @return array<int, array{url: string, width: int|null, height: int|null, rank: int}>
     */
    public function getImages(int $listingId, ?EtsyAccount $account = null): array
    {
        try {
            $data = $this->client->get("/listings/{$listingId}/images", [], $account);
            $results = (array) ($data['results'] ?? []);
        } catch (EtsyApiException $e) {
            // Images are non-critical: a listing without pictures can still
            // be imported (the reference implementation behaves the same).
            Log::warning('EtsyListingService: image fetch failed', [
                'listing_id' => $listingId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $normalized = [];

        foreach ($results as $index => $image) {
            $url = is_array($image) ? ($image['url_fullxfull'] ?? $image['url_570xN'] ?? null) : null;

            if (! is_string($url) || $url === '') {
                continue;
            }

            $normalized[] = [
                'url' => $url,
                'width' => isset($image['full_width']) ? (int) $image['full_width'] : null,
                'height' => isset($image['full_height']) ? (int) $image['full_height'] : null,
                'rank' => $index + 1,
            ];
        }

        return $normalized;
    }

    /**
     * Property bag for rebuilding an immutable DTO with new images.
     *
     * @return array<string, mixed>
     */
    protected function toArray(EtsyListingDTO $dto): array
    {
        return [
            'listingId' => $dto->listingId,
            'userId' => $dto->userId,
            'shopId' => $dto->shopId,
            'shopName' => $dto->shopName,
            'title' => $dto->title,
            'description' => $dto->description,
            'state' => $dto->state,
            'quantity' => $dto->quantity,
            'price' => $dto->price,
            'currency' => $dto->currency,
            'url' => $dto->url,
            'tags' => $dto->tags,
            'materials' => $dto->materials,
            'taxonomyPath' => $dto->taxonomyPath,
            'isPersonalizable' => $dto->isPersonalizable,
            'personalizationIsRequired' => $dto->personalizationIsRequired,
            'personalizationCharCountMax' => $dto->personalizationCharCountMax,
            'personalizationInstructions' => $dto->personalizationInstructions,
            'hasVariations' => $dto->hasVariations,
            'whoMade' => $dto->whoMade,
            'whenMade' => $dto->whenMade,
            'processingMin' => $dto->processingMin,
            'processingMax' => $dto->processingMax,
            'updatedAt' => $dto->updatedAt,
            'images' => $dto->images,
        ];
    }
}
