<?php

namespace App\DTOs\Csv;

/**
 * Import configuration for a CSV product import run.
 */
final readonly class CsvImportOptions
{
    public function __construct(
        public ?int $categoryId,
        public string $defaultCondition,
        public string $defaultCurrency,
        public bool $downloadImages,
        public bool $isActive,
    ) {}

    public static function fromRequest(array $validated): self
    {
        return new self(
            categoryId: isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            defaultCondition: (string) ($validated['default_condition'] ?? 'new'),
            defaultCurrency: (string) ($validated['default_currency'] ?? 'USD'),
            downloadImages: (bool) ($validated['download_images'] ?? true),
            isActive: (bool) ($validated['is_active'] ?? true),
        );
    }

    public function toArray(): array
    {
        return [
            'category_id' => $this->categoryId,
            'default_condition' => $this->defaultCondition,
            'default_currency' => $this->defaultCurrency,
            'download_images' => $this->downloadImages,
            'is_active' => $this->isActive,
        ];
    }
}
