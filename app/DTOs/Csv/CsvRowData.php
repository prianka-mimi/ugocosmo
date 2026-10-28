<?php

namespace App\DTOs\Csv;

/**
 * A single normalized CSV row: canonical field => raw string value.
 *
 * Blank cells are stored as null so a caller can distinguish "column
 * missing from the file" from "column present but empty" via {@see has()}.
 */
final readonly class CsvRowData
{
    /** @param array<string, string|null> $values */
    public function __construct(public array $values) {}

    public function has(string $field): bool
    {
        $value = $this->values[$field] ?? null;

        return $value !== null && $value !== '';
    }

    public function value(string $field): ?string
    {
        return $this->values[$field] ?? null;
    }
}
