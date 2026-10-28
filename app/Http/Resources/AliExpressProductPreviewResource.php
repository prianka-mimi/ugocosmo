<?php

namespace App\Http\Resources;

use App\DTOs\AliExpress\AliExpressProductDTO;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a fetched (not yet imported) AliExpress product for the
 * seller's Import screen.
 */
class AliExpressProductPreviewResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var AliExpressProductDTO $dto */
        $dto = $this->resource;

        return [
            'product_id' => $dto->productId,
            'subject' => $dto->subject,
            'description' => $dto->description,
            'main_image' => $dto->mainImage,
            'images' => $dto->images,
            'price_min' => $dto->priceMin,
            'price_max' => $dto->priceMax,
            'currency' => $dto->currency,
            'cost_price' => $dto->costPrice(),
            'category_id' => $dto->categoryId,
            'category_name' => $dto->categoryName,
            'sku_count' => count($dto->skus),
            'skus' => array_map(fn ($sku) => $sku->toLocalVariant(), $dto->skus),
            'attributes' => $dto->attributes,
            'brand' => $dto->brand,
            'product_status_type' => $dto->productStatusType,
            'product_url' => $dto->productUrl,
            'owner_member_id' => $dto->ownerMemberId,
            'gmt_modified' => $dto->gmtModified,
        ];
    }
}
