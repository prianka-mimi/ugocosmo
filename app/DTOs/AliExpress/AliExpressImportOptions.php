<?php

namespace App\DTOs\AliExpress;

final readonly class AliExpressImportOptions
{
    public function __construct(
        public ?int $categoryId,
        public float $profitMargin,
        public bool $downloadImages,
        public bool $isActive,
    ) {}

    public static function fromRequest(array $validated): self
    {
        return new self(
            categoryId: isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            profitMargin: (float) ($validated['profit_margin'] ?? 0),
            downloadImages: (bool) ($validated['download_images'] ?? true),
            isActive: (bool) ($validated['is_active'] ?? false),
        );
    }

    public function toArray(): array
    {
        return [
            'category_id' => $this->categoryId,
            'profit_margin' => $this->profitMargin,
            'download_images' => $this->downloadImages,
            'is_active' => $this->isActive,
        ];
    }
}
