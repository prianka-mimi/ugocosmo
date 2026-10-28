<?php

namespace App\Services;

use App\DTOs\Csv\CsvImportOptions;
use App\DTOs\Csv\CsvRowData;
use App\Exceptions\Csv\CsvImportException;
use App\Manager\ProductManager;
use App\Models\CsvImport;
use App\Models\CsvImportLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\CsvReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Business logic for the CSV product import workflow.
 *
 * Imported rows become regular marketplace products (`source = 'csv'`),
 * reusing the exact Product/catalog architecture shared by the AliExpress
 * and Shopify importers. CSV import NEVER touches physical inventory:
 * quantity/stock columns are recognized but ignored, and `stock_quantity`
 * is never written — inventory is managed exclusively through the
 * existing inventory workflow (see docs/csv-product-import.md §Inventory).
 */
class CsvImportService
{
    public function __construct(protected ProductManager $productManager) {}

    /**
     * Persist the uploaded CSV on the private local disk under a safe
     * generated name (never the client-provided filename).
     */
    public function storeUpload(UploadedFile $file): string
    {
        $path = $file->storeAs('imports/csv', 'import-'.Str::uuid().'.csv', 'local');

        if ($path === false) {
            throw new CsvImportException('Could not store the uploaded file. Please try again.');
        }

        return $path;
    }

    /**
     * Validate the CSV header structure before dispatching the job.
     *
     * @return array{field_map: array<string, string>, unmapped: array<int, string>}
     *
     * @throws CsvImportException
     */
    public function validateStructure(string $path): array
    {
        $reader = new CsvReader($this->resolvePath($path));

        return [
            'field_map' => $reader->headers(),
            'unmapped' => $reader->unmappedHeaders(),
        ];
    }

    /**
     * Total non-blank data rows (streaming pass — used for progress).
     */
    public function countRows(string $path): int
    {
        return (new CsvReader($this->resolvePath($path)))->countRows();
    }

    /**
     * Resolve the stored relative path to a real filesystem path readable
     * by the streaming parser (local disk only).
     */
    public function resolvePath(string $path): string
    {
        // Already absolute (e.g. re-resolved in the job) — return as-is.
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        $disk = Storage::disk('local');

        if (method_exists($disk, 'path')) {
            return $disk->path($path);
        }

        throw new CsvImportException('CSV import requires a local storage driver.');
    }

    /**
     * Does the stored import file still exist on disk?
     */
    public function fileExists(string $path): bool
    {
        return Storage::disk('local')->exists($path);
    }

    /**
     * Delete a previously stored import file.
     */
    public function deleteFile(string $path): void
    {
        if (Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }

    /**
     * Process a single CSV row: normalize → validate → resolve category →
     * deduplicate → create or update the product.
     *
     * Runs inside the import job's chunk transaction; `lockForUpdate`
     * prevents two concurrent imports of the same file from creating
     * duplicate products.
     *
     * @return array{imported: bool, updated: bool, skipped: bool, failed: bool, error: string|null, product: Product|null, image_urls?: array<int, string>}
     */
    public function processRow(
        CsvImport $import,
        CsvRowData $row,
        int $rowNumber,
        CsvImportOptions $options,
        int $sellerId,
        string $fileName,
    ): array {
        $result = [
            'imported' => false,
            'updated' => false,
            'skipped' => false,
            'failed' => false,
            'error' => null,
            'product' => null,
        ];

        try {
            $product = Product::query()
                ->where('source', 'csv')
                ->where('seller_id', $sellerId)
                ->whereRaw('LOWER(sku) = ?', [mb_strtolower((string) $row->value('sku'))])
                ->lockForUpdate()
                ->first();

            // Create applies option defaults; update only touches columns
            // present in the CSV so manually edited fields (visibility,
            // category, currency, condition) are never blindly overwritten.
            $attributes = $this->buildAttributes($row, $options, $sellerId, $rowNumber, $product !== null);

            if ($product) {
                $product->update($attributes);
                $this->log($import, $rowNumber, $fileName, $row, CsvImportLog::ACTION_UPDATED, 'Updated via import (existing CSV product)');
                $result['updated'] = true;
            } else {
                $product = Product::create($attributes);
                $this->log($import, $rowNumber, $fileName, $row, CsvImportLog::ACTION_IMPORTED);
                $result['imported'] = true;
            }

            $result['product'] = $product;

            if ($row->has('tags')) {
                $this->productManager->syncTags($product, $this->parseTags((string) $row->value('tags')));
            }

            // Image downloads are dispatched by the job AFTER the chunk
            // transaction commits (never from inside the transaction).
            if ($options->downloadImages) {
                $result['image_urls'] = $this->imageUrls($row);
            }

            return $result;
        } catch (CsvImportException $e) {
            $result['failed'] = true;
            $result['error'] = $e->getMessage();

            $this->log($import, $rowNumber, $fileName, $row, CsvImportLog::ACTION_FAILED, null, $e->getMessage());
            Log::info("CsvImportService: row {$rowNumber} failed", [
                'import_id' => $import->id,
                'row' => $rowNumber,
                'file' => $fileName,
                'error' => $e->getMessage(),
            ]);

            return $result;
        } catch (Throwable $e) {
            $result['failed'] = true;
            $result['error'] = 'Unexpected error while importing this row.';

            $this->log($import, $rowNumber, $fileName, $row, CsvImportLog::ACTION_FAILED, null, $e->getMessage());
            Log::error("CsvImportService: unexpected failure on row {$rowNumber}", [
                'import_id' => $import->id,
                'row' => $rowNumber,
                'file' => $fileName,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            return $result;
        }
    }

    /**
     * SSRF guard for remote image URLs fetched during import: only public
     * HTTP(S) hosts resolving to public IPs are allowed.
     */
    public function isSafeImageUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        if (isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true)) {
            return false;
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host);
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || $records === []) {
            return false;
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? null;
            if ($ip === null || ! $this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    // ─── Protected helpers ─────────────────────────────────────────

    /**
     * Normalize + validate a row and build the exact set of attributes
     * that may be persisted. Only explicitly mapped columns are allowed —
     * never the whole row (mass-assignment protection).
     *
     * On UPDATE only columns actually present in the CSV row are written
     * (plus the source metadata): default options and untouched columns
     * never overwrite manually maintained fields. This mirrors the
     * AliExpress importer, where source-controlled fields are refreshed on
     * re-import while fields outside the source are left alone.
     *
     * @throws CsvImportException
     */
    protected function buildAttributes(
        CsvRowData $row,
        CsvImportOptions $options,
        int $sellerId,
        int $rowNumber,
        bool $isUpdate = false,
    ): array {
        $attributes = [
            'seller_id' => $sellerId,
            'source' => 'csv',
            'sync_status' => 'synced',
            'last_synced_at' => now(),
        ];

        // ── Required identity fields ───────────────────────────────
        $sku = trim((string) $row->value('sku'));
        $title = trim((string) $row->value('title'));

        if ($sku === '') {
            throw new CsvImportException('Missing SKU — every row must have a non-empty SKU.');
        }
        if (mb_strlen($sku) > 100) {
            throw new CsvImportException('SKU exceeds the maximum length of 100 characters.');
        }
        if ($title === '') {
            throw new CsvImportException('Missing title — every row must have a non-empty title.');
        }
        if (mb_strlen($title) > 255) {
            throw new CsvImportException('Title exceeds the maximum length of 255 characters.');
        }

        $attributes['sku'] = $sku;
        $attributes['title'] = $title;

        // ── Description ────────────────────────────────────────────
        if ($row->has('description')) {
            $description = trim((string) $row->value('description'));
            if (mb_strlen($description) > 65535) {
                throw new CsvImportException('Description exceeds the maximum supported length.');
            }
            $attributes['description'] = $description;
        }

        // ── Pricing (decimal, never float arithmetic) ──────────────
        if ($row->has('price')) {
            if (! is_numeric($row->value('price'))) {
                throw new CsvImportException('Price must be a valid number.');
            }
            $price = round((float) $row->value('price'), 2);
            if ($price < 0) {
                throw new CsvImportException('Price must not be negative.');
            }
            if ($price > 99999999.99) {
                throw new CsvImportException('Price exceeds the supported maximum.');
            }
            $attributes['price'] = $price;
        }

        if ($row->has('compare_at_price')) {
            if (! is_numeric($row->value('compare_at_price'))) {
                throw new CsvImportException('Compare-at price must be a valid number.');
            }
            $compareAt = round((float) $row->value('compare_at_price'), 2);
            if ($compareAt < 0) {
                throw new CsvImportException('Compare-at price must not be negative.');
            }
            if (isset($attributes['price']) && $compareAt <= $attributes['price']) {
                throw new CsvImportException('Compare-at price must be greater than the price.');
            }
            $attributes['compare_at_price'] = $compareAt;
        }

        // ── Currency ───────────────────────────────────────────────
        if ($row->has('currency')) {
            $currency = strtoupper(trim((string) $row->value('currency')));
            if (! preg_match('/^[A-Z]{3}$/', $currency)) {
                throw new CsvImportException('Currency must be a 3-letter ISO code.');
            }
            $attributes['currency'] = $currency;
        } elseif (! $isUpdate) {
            $attributes['currency'] = $options->defaultCurrency;
        }

        // ── Category (by existing id or name — never auto-created) ──
        $categoryId = null;
        if ($row->has('category_id')) {
            if (! ctype_digit((string) $row->value('category_id'))) {
                throw new CsvImportException('Category id must be a positive integer.');
            }
            $categoryId = (int) $row->value('category_id');
            if (! $this->categoryExists($categoryId)) {
                throw new CsvImportException("Category id {$categoryId} does not exist.");
            }
        } elseif ($row->has('category')) {
            $categoryId = $this->resolveCategoryByName(trim((string) $row->value('category')));
        }

        if ($categoryId !== null) {
            $attributes['category_id'] = $categoryId;
        } elseif (! $isUpdate && $options->categoryId !== null) {
            $attributes['category_id'] = $options->categoryId;
        }

        // ── Details ────────────────────────────────────────────────
        if ($row->has('brand')) {
            $attributes['brand'] = mb_substr(trim((string) $row->value('brand')), 0, 255);
        }
        if ($row->has('ean_upc')) {
            $attributes['ean_upc'] = mb_substr(trim((string) $row->value('ean_upc')), 0, 100);
        }
        if ($row->has('weight')) {
            if (! is_numeric($row->value('weight')) || (float) $row->value('weight') < 0) {
                throw new CsvImportException('Weight must be a non-negative number.');
            }
            $attributes['weight'] = round((float) $row->value('weight'), 2);
        }
        if ($row->has('product_type')) {
            $attributes['product_type'] = mb_substr(trim((string) $row->value('product_type')), 0, 255);
        }

        // ── Variants (product_variants JSON column — same shape the
        //    ProductStoreRequest/ProductResource use) ──────────────
        if ($row->has('variants')) {
            $attributes['product_variants'] = $this->parseVariants((string) $row->value('variants'));
        }

        // ── Details / wholesale ────────────────────────────────────
        if ($row->has('specifications')) {
            $attributes['specifications'] = mb_substr(trim((string) $row->value('specifications')), 0, 10000);
        }
        if ($row->has('features_benefits')) {
            $attributes['features_benefits'] = mb_substr(trim((string) $row->value('features_benefits')), 0, 10000);
        }
        if ($row->has('wholesale_product')) {
            $attributes['wholesale_product'] = $this->parseBoolean((string) $row->value('wholesale_product'));
        }
        if ($row->has('moq')) {
            if (! ctype_digit((string) $row->value('moq')) || (int) $row->value('moq') < 1) {
                throw new CsvImportException('MOQ must be a positive integer.');
            }
            $attributes['moq'] = (int) $row->value('moq');
        }
        if ($row->has('bulk_pricing')) {
            $attributes['bulk_pricing'] = $this->parseJsonArray((string) $row->value('bulk_pricing'), 'bulk pricing');
        }

        // ── Shipping ───────────────────────────────────────────────
        if ($row->has('ship_from_address')) {
            $attributes['ship_from_address'] = mb_substr(trim((string) $row->value('ship_from_address')), 0, 500);
        }
        if ($row->has('shipping_options')) {
            $attributes['shipping_options'] = $this->parseList((string) $row->value('shipping_options'));
        }
        if ($row->has('shipping_cost')) {
            if (! is_numeric($row->value('shipping_cost')) || (float) $row->value('shipping_cost') < 0) {
                throw new CsvImportException('Shipping cost must be a non-negative number.');
            }
            $attributes['shipping_cost'] = round((float) $row->value('shipping_cost'), 2);
        }
        if ($row->has('package_dimensions')) {
            $attributes['package_dimensions'] = $this->parseJsonArray((string) $row->value('package_dimensions'), 'package dimensions');
        }
        if ($row->has('handling_time')) {
            $attributes['handling_time'] = mb_substr(trim((string) $row->value('handling_time')), 0, 100);
        }
        if ($row->has('estimated_delivery')) {
            $attributes['estimated_delivery'] = mb_substr(trim((string) $row->value('estimated_delivery')), 0, 100);
        }
        if ($row->has('shipping_info')) {
            $attributes['shipping_info'] = mb_substr(trim((string) $row->value('shipping_info')), 0, 500);
        }
        if ($row->has('location')) {
            $attributes['location'] = mb_substr(trim((string) $row->value('location')), 0, 255);
        }

        // ── Media / status ─────────────────────────────────────────
        if ($row->has('video_url')) {
            if (! filter_var(trim((string) $row->value('video_url')), FILTER_VALIDATE_URL)) {
                throw new CsvImportException('Video URL must be a valid URL.');
            }
            $attributes['video_url'] = trim((string) $row->value('video_url'));
        }
        if ($row->has('digital_download_url')) {
            if (! filter_var(trim((string) $row->value('digital_download_url')), FILTER_VALIDATE_URL)) {
                throw new CsvImportException('Digital download URL must be a valid URL.');
            }
            $attributes['digital_download_url'] = trim((string) $row->value('digital_download_url'));
        }

        // ── Condition ──────────────────────────────────────────────
        if ($row->has('condition')) {
            $condition = strtolower(trim((string) $row->value('condition')));
            if (! in_array($condition, ['new', 'used', 'refurbished'], true)) {
                throw new CsvImportException('Condition must be one of: new, used, refurbished.');
            }
            $attributes['condition'] = $condition;
        } elseif (! $isUpdate) {
            $attributes['condition'] = $options->defaultCondition;
        }

        // ── Visibility ─────────────────────────────────────────────
        if ($row->has('is_active')) {
            $attributes['is_active'] = $this->parseBoolean((string) $row->value('is_active'));
        } elseif (! $isUpdate) {
            $attributes['is_active'] = $options->isActive;
        }

        // ── Source metadata ────────────────────────────────────────
        $attributes['csv_source_data'] = [
            'sku' => $sku,
            'row_number' => $rowNumber,
            'image_urls' => $this->imageUrls($row),
            'tags' => $row->has('tags') ? $this->parseTags((string) $row->value('tags')) : [],
            'imported_at' => now()->toIso8601String(),
        ];

        return $attributes;
    }

    protected function resolveCategoryByName(string $name): int
    {
        $category = ProductCategory::query()
            ->where('is_active', true)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if (! $category) {
            throw new CsvImportException("Category \"{$name}\" was not found. Use an existing category name or category id.");
        }

        return (int) $category->id;
    }

    protected function categoryExists(int $id): bool
    {
        return ProductCategory::query()->where('id', $id)->where('is_active', true)->exists();
    }

    /**
     * Parse a boolean-ish CSV value using the project's conventions
     * (true/1/yes/active/published ⇒ true; false/0/no/inactive/draft/
     * hidden/archived ⇒ false).
     */
    protected function parseBoolean(string $value): bool
    {
        return match (mb_strtolower(trim($value))) {
            'true', '1', 'yes', 'y', 'active', 'published', 'enabled' => true,
            default => false,
        };
    }

    /** Split a tags cell on commas or pipes, trimmed and de-duplicated. */
    protected function parseTags(string $value): array
    {
        $tags = array_values(array_unique(array_filter(array_map(
            fn ($tag) => trim($tag),
            preg_split('/[,|]/', $value) ?: []
        ))));

        return array_slice($tags, 0, 15);
    }

    /**
     * Split a list cell (shipping options) on pipes or semicolons.
     *
     * @return array<int, string>
     */
    protected function parseList(string $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($item) => trim($item),
            preg_split('/[|;]/', $value) ?: []
        ))));
    }

    /**
     * Decode a JSON cell (bulk pricing, package dimensions).
     *
     * @throws CsvImportException on malformed JSON or a non-array result
     */
    protected function parseJsonArray(string $value, string $label): array
    {
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw new CsvImportException("{$label} must be valid JSON.");
        }

        return $decoded;
    }

    /**
     * Parse the variants cell into the `product_variants` JSON shape used
     * by ProductResource. Accepts either JSON or a compact pipe format:
     *
     *   JSON:  [{"name":"Size","options":[{"value":"M","priceModifier":0},...]},...]
     *   Pipe:  Size: M|L|XL; Color: Red|Blue   (option name: values)
     *
     * @return array<int, array{name: string, options: array<int, array{value: string, priceModifier?: float, price_adjustment?: float}>}>
     *
     * @throws CsvImportException
     */
    protected function parseVariants(string $value): array
    {
        $trimmed = trim($value);
        if (str_starts_with($trimmed, '[')) {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                throw new CsvImportException('Variants must be valid JSON or a pipe format (e.g. Size: M|L).');
            }

            $normalized = [];
            foreach ($decoded as $variantGroup) {
                if (! is_array($variantGroup) || empty($variantGroup['name'])) {
                    throw new CsvImportException('Each variant group must have a name.');
                }
                $options = [];
                foreach ((array) ($variantGroup['options'] ?? []) as $option) {
                    if (! is_array($option) || empty($option['value'])) {
                        throw new CsvImportException('Each variant option must have a value.');
                    }
                    $normalizedOption = ['value' => (string) $option['value']];
                    foreach (['priceModifier', 'price_adjustment', 'stockQuantity', 'stock'] as $numericKey) {
                        if (isset($option[$numericKey]) && is_numeric($option[$numericKey])) {
                            $normalizedOption[$numericKey] = (float) $option[$numericKey];
                        }
                    }
                    $options[] = $normalizedOption;
                }
                $normalized[] = [
                    'name' => (string) $variantGroup['name'],
                    'options' => $options,
                ];
            }

            return array_slice($normalized, 0, 10);
        }

        // Compact format: "Size: M|L|XL; Color: Red|Blue"
        $groups = [];
        foreach (preg_split('/[;]/', $trimmed) ?: [] as $group) {
            [$name, $values] = array_pad(explode(':', $group, 2), 2, null);
            $name = trim((string) $name);
            if ($name === '' || $values === null || trim($values) === '') {
                throw new CsvImportException('Variants must use the format "Size: M|L|XL; Color: Red|Blue".');
            }
            $options = array_map(
                fn ($optionValue) => ['value' => trim($optionValue)],
                array_filter(array_map('trim', preg_split('/[|]/', $values) ?: []))
            );
            $groups[] = ['name' => $name, 'options' => $options];
        }

        if ($groups === []) {
            throw new CsvImportException('Variants must contain at least one option group.');
        }

        return array_slice($groups, 0, 10);
    }

    /**
     * Extract remote image URLs from the row. Supports a single `image`
     * column and/or a multi-URL `images` column (pipe/semicolon separated).
     *
     * @return array<int, string>
     */
    protected function imageUrls(CsvRowData $row): array
    {
        $urls = [];

        if ($row->has('image')) {
            $urls[] = trim((string) $row->value('image'));
        }

        if ($row->has('images')) {
            foreach (preg_split('/[|;]/', (string) $row->value('images')) ?: [] as $url) {
                $url = trim($url);
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }

        $valid = [];
        foreach (array_unique($urls) as $url) {
            if (filter_var($url, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//i', $url)) {
                $valid[] = $url;
            }
        }

        return array_slice($valid, 0, 10);
    }

    /** POSIX or Windows drive-absolute path? */
    protected function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return true;
        }

        // Windows drive prefix: C:\ or C:/
        return strlen($path) >= 3
            && ctype_alpha($path[0])
            && $path[1] === ':'
            && ($path[2] === '\\' || $path[2] === '/');
    }

    protected function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    protected function log(
        CsvImport $import,
        int $rowNumber,
        string $fileName,
        CsvRowData $row,
        string $action,
        ?string $reason = null,
        ?string $errorMessage = null,
    ): void {
        CsvImportLog::create([
            'csv_import_id' => $import->id,
            'row_number' => $rowNumber,
            'file_name' => $fileName,
            'sku' => $row->value('sku'),
            'product_title' => $row->value('title'),
            'action' => $action,
            'reason' => $reason,
            'error_message' => $errorMessage,
        ]);
    }
}
