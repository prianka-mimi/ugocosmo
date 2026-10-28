<?php

namespace App\Support;

use App\Exceptions\Csv\CsvImportException;
use Generator;
use SplFileObject;

/**
 * Streaming CSV reader.
 *
 * Uses the native `fgetcsv` parser (correct handling of quoted values,
 * commas/line-breaks inside quotes) instead of naive `explode(',', $line)`
 * splitting, and never loads the whole file into memory — rows are streamed
 * from disk via a generator.
 *
 * Responsibilities:
 *  - normalize the header row (UTF-8 BOM, casing, whitespace)
 *  - map headers to canonical product fields via aliases
 *  - reject duplicate/empty headers
 *  - convert non-UTF-8 cells (e.g. Windows-1252 Excel exports) to UTF-8
 *  - skip fully-blank rows
 */
final class CsvReader
{
    public const SEPARATOR = ',';

    public const ENCLOSURE = '"';

    /** Required canonical columns — the import fails fast when absent. */
    public const REQUIRED_FIELDS = ['sku', 'title'];

    /** Canonical field => accepted header aliases (normalized). */
    private const ALIASES = [
        'sku' => ['sku', 'product_sku', 'item_sku'],
        'title' => ['title', 'name', 'product_name', 'product_title'],
        'description' => ['description', 'product_description', 'details'],
        'price' => ['price', 'selling_price', 'unit_price', 'product_price'],
        'compare_at_price' => ['compare_at_price', 'compare_price', 'original_price', 'was_price'],
        'currency' => ['currency'],
        'category' => ['category', 'category_name'],
        'category_id' => ['category_id'],
        'brand' => ['brand', 'manufacturer'],
        'condition' => ['condition'],
        'product_type' => ['product_type', 'type'],
        'image' => ['image', 'image_url', 'main_image', 'featured_image'],
        'images' => ['images', 'image_urls', 'gallery'],
        'ean_upc' => ['ean_upc', 'barcode', 'ean', 'upc'],
        'weight' => ['weight'],
        'tags' => ['tags', 'tag'],
        'is_active' => ['is_active', 'status', 'active', 'published'],

        // ── Details / wholesale (product table columns) ──────────────
        'variants' => ['variants', 'product_variants'],
        'specifications' => ['specifications', 'specs'],
        'features_benefits' => ['features_benefits', 'features'],
        'wholesale_product' => ['wholesale_product', 'wholesale'],
        'moq' => ['moq', 'minimum_order_quantity'],
        'bulk_pricing' => ['bulk_pricing', 'bulk_prices'],

        // ── Shipping (product table columns) ─────────────────────────
        'ship_from_address' => ['ship_from_address', 'shipping_from'],
        'shipping_options' => ['shipping_options', 'ship_options'],
        'shipping_cost' => ['shipping_cost', 'ship_cost'],
        'package_dimensions' => ['package_dimensions', 'dimensions'],
        'handling_time' => ['handling_time'],
        'estimated_delivery' => ['estimated_delivery', 'delivery_time'],
        'shipping_info' => ['shipping_info'],
        'location' => ['location', 'ship_location'],

        // ── Media / status (product table columns) ───────────────────
        'video_url' => ['video_url', 'video'],
        'digital_download_url' => ['digital_download_url', 'download_url'],

        // Recognized but intentionally NOT persisted — see the inventory
        // separation rule in docs/csv-product-import.md.
        'quantity' => ['quantity', 'stock', 'stock_quantity', 'inventory', 'available_quantity', 'qty'],
    ];

    private SplFileObject $file;

    /** Canonical field => original header text. */
    private array $headerMap = [];

    /** Normalized raw headers that could not be mapped to a field. */
    private array $unmappedHeaders = [];

    public function __construct(private readonly string $path)
    {
        // NOTE: no SplFileObject flags are set. The READ_AHEAD flag makes
        // rewind()+fgetcsv() skip the first line (known quirk), and blank
        // rows are already filtered by isBlankRow().
        $this->file = new SplFileObject($path, 'r');
    }

    /**
     * Read and validate the header row, building the canonical field map.
     *
     * @return array<string, string> canonical field => original header text
     *
     * @throws CsvImportException when the file is empty, has duplicate or
     *                            blank headers, or misses a required column
     */
    public function headers(): array
    {
        if ($this->headerMap !== []) {
            return $this->headerMap;
        }

        $this->file->rewind();
        $raw = $this->readRow();

        if ($raw === false || $raw === null || $this->isBlankRow($raw)) {
            throw new CsvImportException('The CSV file is empty or missing a header row.');
        }

        $normalized = [];
        $seen = [];
        foreach ($raw as $index => $header) {
            $header = $this->decode($header);
            if ($index === 0) {
                $header = $this->stripBom($header);
            }

            $key = $this->normalizeHeader($header);
            if ($key === '') {
                throw new CsvImportException('The CSV header row contains an empty column name.');
            }
            if (isset($seen[$key])) {
                throw new CsvImportException("The CSV header contains the duplicate column \"{$header}\".");
            }
            $seen[$key] = true;
            $normalized[] = $key;
        }

        foreach ($normalized as $key) {
            $field = $this->resolveField($key);
            if ($field === null) {
                $this->unmappedHeaders[] = $key;

                continue;
            }
            // First occurrence wins; duplicates were already rejected above.
            $this->headerMap[$field] ??= $key;
        }

        $missing = array_diff(self::REQUIRED_FIELDS, array_keys($this->headerMap));
        if ($missing !== []) {
            throw new CsvImportException(
                'The CSV is missing required column(s): '.implode(', ', $missing).'.'
            );
        }

        return $this->headerMap;
    }

    /**
     * Normalized raw headers that were not recognized.
     *
     * @return array<int, string>
     */
    public function unmappedHeaders(): array
    {
        $this->headers();

        return $this->unmappedHeaders;
    }

    /**
     * Stream data rows as canonical field => raw value (null when blank).
     *
     * @return Generator<int, array<string, string|null>>
     */
    public function rows(): Generator
    {
        $headerMap = $this->headers();

        $this->file->rewind();
        $this->readRow(); // consume header

        $fields = array_keys($headerMap);

        while (($row = $this->readRow()) !== false) {
            if ($this->isBlankRow($row)) {
                continue;
            }

            $values = [];
            foreach ($fields as $index => $field) {
                $value = $row[$index] ?? null;
                $values[$field] = $value === null || $value === '' ? null : $this->decode($value);
            }

            yield $values;
        }
    }

    /**
     * Count non-blank data rows (streaming pass over the file).
     */
    public function countRows(): int
    {
        $count = 0;
        foreach ($this->rows() as $_) {
            $count++;
        }

        return $count;
    }

    // ─── Helpers ───────────────────────────────────────────────────

    /**
     * Read one CSV row. The escape character is passed explicitly because
     * PHP 8.4 changes its default (SplFileObject::fgetcsv).
     *
     * @return array<int, string|null>|false
     */
    protected function readRow(): array|false
    {
        return $this->file->fgetcsv(self::SEPARATOR, self::ENCLOSURE, '\\');
    }

    protected function resolveField(string $normalizedHeader): ?string
    {
        foreach (self::ALIASES as $field => $aliases) {
            if (in_array($normalizedHeader, $aliases, true)) {
                return $field;
            }
        }

        return null;
    }

    protected function normalizeHeader(string $header): string
    {
        return str_replace(' ', '_', mb_strtolower(trim($header)));
    }

    protected function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }

    /**
     * Convert non-UTF-8 cells to UTF-8 (Windows-1252 — the common Excel
     * export encoding). Valid UTF-8 passes through untouched.
     */
    protected function decode(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        return $value;
    }

    /** True when the row is empty (all cells blank) or a stray [null]. */
    protected function isBlankRow(array $row): bool
    {
        $values = array_values($row);
        if ($values === [null] || $values === ['']) {
            return true;
        }

        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }
}
