<?php

namespace App\DTOs\Etsy;

/**
 * Options for an Etsy import run — mirrors AliExpressImportOptions.
 * Etsy listings have no supplier cost price, so margin-based pricing
 * does not apply: the Etsy price IS the marketplace price.
 */
final readonly class EtsyImportOptions
{
    public function __construct(
        public ?int $categoryId,
        public bool $isActive,
    ) {}

    public static function fromRequest(array $validated): self
    {
        return new self(
            categoryId: isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            isActive: (bool) ($validated['is_active'] ?? true),
        );
    }

    public function toArray(): array
    {
        return [
            'category_id' => $this->categoryId,
            'is_active' => $this->isActive,
        ];
    }
}
