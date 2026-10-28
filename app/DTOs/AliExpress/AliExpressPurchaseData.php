<?php

namespace App\DTOs\AliExpress;

/**
 * The seller's manual AliExpress purchase record for an order.
 * AliExpress ordering is never automated — this is bookkeeping only.
 */
final readonly class AliExpressPurchaseData
{
    public function __construct(
        public ?string $purchaseReference,
        public ?string $supplierName,
        public ?float $supplierCost,
        public ?string $purchaseDate,
        public ?string $purchaseNotes,
    ) {}

    public static function fromRequest(array $validated): self
    {
        return new self(
            purchaseReference: isset($validated['purchase_reference']) ? (string) $validated['purchase_reference'] : null,
            supplierName: isset($validated['supplier_name']) ? (string) $validated['supplier_name'] : null,
            supplierCost: isset($validated['supplier_cost']) ? (float) $validated['supplier_cost'] : null,
            purchaseDate: isset($validated['purchase_date']) ? (string) $validated['purchase_date'] : null,
            purchaseNotes: isset($validated['purchase_notes']) ? (string) $validated['purchase_notes'] : null,
        );
    }
}
