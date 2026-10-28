<?php

namespace App\DTOs\AliExpress;

/**
 * Real-time supplier stock for one AliExpress product, resolved from the
 * SKU / Inventory API.
 *
 * API: aliexpress.offer.ds.product.simplequery (AE-Dropshipper)
 *
 * Documented response:
 *   total_available_stock            — product-level stock
 *   aeop_ae_product_s_k_us.aeop_ae_product_sku[]
 *     └ id                          — SKU attribute id string
 *     └ s_k_u_available_stock        — sellable stock
 *     └ ipm_sku_stock               — sellable stock (authoritative when present)
 *     └ sku_stock                   — boolean "any stock" flag
 *     └ sku_price                   — unit price
 *     └ offer_sale_price            — discounted price
 *     └ currency_code               — SKU currency
 *     └ aeop_s_k_u_property_list.aeop_sku_property[]
 *          └ sku_property_id / property_value_id / property_value_definition_name
 *
 * Stock key precedence follows the platform's own documented rule: `ipm_sku_stock`
 * wins, then `s_k_u_available_stock`, then the `sku_stock` boolean.
 */
final readonly class AliExpressSkuInventoryDTO
{
    /**
     * @param  array<int, AliExpressSkuDTO>  $skus
     */
    public function __construct(
        public string $productId,
        public int $totalAvailableStock,
        public array $skus,
        public ?string $productStatusType = null,
    ) {}

    /**
     * Build from the simplequery response payload.
     *
     * @param  array<string, mixed>  $response  the decoded
     *                                          `<method>_response` payload
     */
    public static function fromResponse(array $response, ?string $productId = null): self
    {
        $result = $response['result'] ?? $response;

        $skus = [];
        foreach (self::extractSkuNodes($result) as $node) {
            $sku = AliExpressSkuDTO::fromInventoryResponse($node);

            if ($sku->skuId !== '') {
                $skus[] = $sku;
            }
        }

        // Fall back to the sum of per-SKU stock when the product-level total
        // is missing or zero but individual SKUs report stock.
        $reportedTotal = (int) ($result['total_available_stock'] ?? 0);
        $total = $reportedTotal > 0
            ? $reportedTotal
            : array_sum(array_map(fn (AliExpressSkuDTO $s) => $s->stock, $skus));

        return new self(
            productId: (string) ($productId ?? $result['product_id'] ?? ''),
            totalAvailableStock: $total,
            skus: $skus,
            productStatusType: isset($result['product_status_type'])
                ? (string) $result['product_status_type']
                : null,
        );
    }

    /**
     * Stock for a single SKU, or null when the SKU is not in the payload.
     */
    public function stockFor(string $skuId): ?int
    {
        foreach ($this->skus as $sku) {
            if ($sku->skuId === $skuId) {
                return $sku->stock;
            }
        }

        return null;
    }

    /**
     * Overlay this live inventory onto product-detail SKUs.
     *
     * The detail API (`ds.product.get`) and the inventory API
     * (`offer.ds.product.simplequery`) can expose different SKU id formats,
     * so a fallback positional match is used when no id matches. Detail SKUs
     * absent from the inventory payload keep their detail value rather than
     * being zeroed — the inventory API is not authoritative for SKUs it does
     * not know about.
     *
     * @param  array<int, AliExpressSkuDTO>  $detailSkus
     * @return array<int, AliExpressSkuDTO>
     */
    public function mergeInto(array $detailSkus): array
    {
        $byId = [];
        foreach ($this->skus as $sku) {
            $byId[$sku->skuId] = $sku;
        }

        $merged = [];
        $usedIds = [];

        foreach ($detailSkus as $index => $detailSku) {
            $live = $byId[$detailSku->skuId] ?? ($this->skus[$index] ?? null);

            $merged[] = $live !== null
                ? $detailSku->withStock($live->stock)
                : $detailSku;

            if ($live !== null) {
                $usedIds[$live->skuId] = true;
            }
        }

        // Any inventory SKUs the detail response omitted are appended so a
        // seller never loses a sellable variant.
        foreach ($this->skus as $sku) {
            if (! isset($usedIds[$sku->skuId])) {
                $merged[] = $sku;
            }
        }

        return $merged;
    }

    /**
     * The API nests SKUs under aeop_ae_product_s_k_us.aeop_ae_product_sku;
     * tiers differ, so the common alternatives are handled too.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function extractSkuNodes(array $result): array
    {
        $container = $result['aeop_ae_product_s_k_us']
            ?? $result['aeop_ae_product_skus']
            ?? $result['ae_item_sku_info_dtos']
            ?? [];

        if (is_array($container) && isset($container['aeop_ae_product_sku'])) {
            $container = $container['aeop_ae_product_sku'];
        }

        if (! is_array($container) || $container === []) {
            return [];
        }

        return array_values(array_filter($container, 'is_array'));
    }
}
