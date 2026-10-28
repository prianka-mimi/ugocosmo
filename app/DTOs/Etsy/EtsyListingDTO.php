<?php

namespace App\DTOs\Etsy;

use App\Exceptions\Etsy\EtsyApiException;

/**
 * Normalized Etsy listing (Open API v3 `getListing` / `findAllActiveListings` shape).
 *
 * Carries only the fields the reference implementation persists:
 * listing identity, title/description, price (divisor-normalized), state,
 * quantity, taxonomy path, tags/materials and processing/personalization
 * flags. Images are fetched separately (getListingImages) and attached
 * by the import service.
 */
final readonly class EtsyListingDTO
{
    /**
     * Etsy renders money as amount/divisor (e.g. 2600/100 = 26.00).
     */
    public const PRICE_DIVISOR = 100;

    public function __construct(
        public int $listingId,
        public int $userId,
        public int $shopId,
        public ?string $shopName,
        public string $title,
        public ?string $description,
        public string $state,
        public int $quantity,
        public ?float $price,
        public ?string $currency,
        public ?string $url,
        public array $tags,
        public array $materials,
        public ?string $taxonomyPath,
        public bool $isPersonalizable,
        public bool $personalizationIsRequired,
        public ?int $personalizationCharCountMax,
        public ?string $personalizationInstructions,
        public bool $hasVariations,
        public ?string $whoMade,
        public ?string $whenMade,
        public ?int $processingMin,
        public ?int $processingMax,
        public ?\DateTimeImmutable $updatedAt,
        public array $images,
    ) {}

    public static function fromApiResponse(array $listing): self
    {
        $listingId = (int) ($listing['listing_id'] ?? 0);

        if ($listingId <= 0) {
            throw new EtsyApiException('Etsy listing response is missing listing_id.', 502, null, $listing);
        }

        $price = null;

        if (isset($listing['price']['amount'], $listing['price']['divisor']) && (int) $listing['price']['divisor'] > 0) {
            $price = round((int) $listing['price']['amount'] / (int) $listing['price']['divisor'], 2);
        }

        return new self(
            listingId: $listingId,
            userId: (int) ($listing['user_id'] ?? 0),
            shopId: (int) ($listing['shop_id'] ?? 0),
            shopName: isset($listing['shop']['shop_name']) ? (string) $listing['shop']['shop_name'] : null,
            title: (string) ($listing['title'] ?? 'No Title'),
            description: self::cleanDescription($listing['description'] ?? null),
            state: (string) ($listing['state'] ?? 'active'),
            quantity: (int) ($listing['quantity'] ?? 0),
            price: $price,
            currency: isset($listing['price']['currency_code']) ? (string) $listing['price']['currency_code'] : null,
            url: isset($listing['url']) ? (string) $listing['url'] : null,
            tags: self::stringList($listing['tags'] ?? []),
            materials: self::stringList($listing['materials'] ?? []),
            taxonomyPath: isset($listing['taxonomy_path'][0]) ? (string) $listing['taxonomy_path'][0] : null,
            isPersonalizable: (bool) ($listing['is_personalizable'] ?? false),
            personalizationIsRequired: (bool) ($listing['personalization_is_required'] ?? false),
            personalizationCharCountMax: isset($listing['personalization_char_count_max'])
                ? (int) $listing['personalization_char_count_max']
                : null,
            personalizationInstructions: isset($listing['personalization_instructions'])
                ? (string) $listing['personalization_instructions']
                : null,
            hasVariations: (bool) ($listing['has_variations'] ?? false),
            whoMade: isset($listing['who_made']) ? (string) $listing['who_made'] : null,
            whenMade: isset($listing['when_made']) ? (string) $listing['when_made'] : null,
            processingMin: isset($listing['processing_min']) ? (int) $listing['processing_min'] : null,
            processingMax: isset($listing['processing_max']) ? (int) $listing['processing_max'] : null,
            updatedAt: isset($listing['updated_timestamp'])
                ? (new \DateTimeImmutable)->setTimestamp((int) $listing['updated_timestamp'])
                : null,
            images: self::images($listing['images'] ?? null),
        );
    }

    /**
     * Raw Etsy image payload attached to a listing by the import service.
     *
     * @return array<int, array{url: string, width: int|null, height: int|null, rank: int}>
     */
    protected static function images(mixed $images): array
    {
        if (! is_array($images)) {
            return [];
        }

        $normalized = [];

        foreach ($images as $index => $image) {
            $url = $image['url_fullxfull'] ?? $image['url_570xN'] ?? null;

            if (! is_array($image) || ! is_string($url) || $url === '') {
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
     * Decode entities, strip tags and collapse whitespace — mirrors
     * Product::getCleanDescriptionAttribute / AliExpress import handling.
     */
    protected static function cleanDescription(?string $description): ?string
    {
        if ($description === null || trim($description) === '') {
            return null;
        }

        $text = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return $text !== '' ? $text : null;
    }

    /**
     * Etsy returns scalar-or-list shapes depending on result count.
     */
    protected static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return $value === null || $value === '' ? [] : [$value];
        }

        if (! array_is_list($value)) {
            $value = [$value];
        }

        return array_values(array_filter(array_map(
            fn ($item) => is_scalar($item) ? (string) $item : null,
            $value
        )));
    }
}
