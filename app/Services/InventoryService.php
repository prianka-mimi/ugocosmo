<?php

namespace App\Services;

use App\Enums\InventoryTransactionType;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Inventory lifecycle for marketplace listings.
 *
 * Rules enforced here:
 *  - imported AliExpress listings start at zero stock
 *  - stock rises ONLY via receive/adjustment/restock
 *  - stock never drops before shipment (reserve, then ship)
 *  - available = stock - reserved
 * Every mutation is transactional and writes an audit record.
 */
class InventoryService
{
    public function availableQuantity(Product $product): int
    {
        return $product->availableQuantity();
    }

    /**
     * Stock in — seller received physical goods from AliExpress.
     */
    public function receive(Product $product, int $quantity, ?string $referenceType = null, ?int $referenceId = null, ?string $notes = null, ?int $createdBy = null): InventoryTransaction
    {
        $this->assertPositive($quantity);

        return $this->mutate($product, InventoryTransactionType::Receive, $quantity, $referenceType, $referenceId, $notes, $createdBy);
    }

    /**
     * Manual stock correction. Delta may be negative but never below zero.
     */
    public function adjust(Product $product, int $delta, ?string $notes = null, ?int $createdBy = null): InventoryTransaction
    {
        return $this->mutate($product, InventoryTransactionType::Adjustment, $delta, 'manual', null, $notes, $createdBy);
    }

    /**
     * Set aside stock for a confirmed order.
     */
    public function reserveForOrder(Order $order, ?int $createdBy = null): void
    {
        $order->loadMissing('items.product');

        foreach ($order->items as $item) {
            $product = $item->product;
            if (! $product || (int) $product->stock_quantity <= 0) {
                continue;
            }

            $this->reserve($product, (int) $item->quantity, 'order', $order->id, "Reserved for order #{$order->id}", $createdBy);
        }
    }

    /**
     * Release a reservation (e.g. order cancelled before shipment).
     */
    public function release(Product $product, int $quantity, ?string $referenceType = null, ?int $referenceId = null, ?string $notes = null, ?int $createdBy = null): InventoryTransaction
    {
        $this->assertPositive($quantity);

        return $this->mutate($product, InventoryTransactionType::Release, -$quantity, $referenceType, $referenceId, $notes, $createdBy);
    }

    /**
     * Ship: reduce stock AND its reservation. Available stays consistent.
     */
    public function ship(Product $product, int $quantity, ?string $referenceType = null, ?int $referenceId = null, ?string $notes = null, ?int $createdBy = null): InventoryTransaction
    {
        $this->assertPositive($quantity);

        return $this->mutate($product, InventoryTransactionType::Ship, -$quantity, $referenceType, $referenceId, $notes, $createdBy);
    }

    /**
     * Paginated transaction history for a seller.
     */
    public function transactions(int $sellerId, ?int $productId = null, ?string $type = null, int $perPage = 20): LengthAwarePaginator
    {
        return InventoryTransaction::bySeller($sellerId)
            ->when($productId, fn ($q) => $q->byProduct($productId))
            ->when($type, fn ($q) => $q->byType($type))
            ->with('product:id,title,image_url,stock_quantity,reserved_quantity')
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Stock overview for a seller's imported listings.
     */
    public function overview(int $sellerId, int $perPage = 20): LengthAwarePaginator
    {
        return Product::source('aliexpress')
            ->bySeller($sellerId)
            ->with(['category:id,name', 'aliexpressAccount:id,account_name'])
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Low-stock and out-of-stock products for a seller (filtered in SQL).
     */
    public function stockAlerts(int $sellerId, ?int $threshold = null): Collection
    {
        $threshold ??= (int) config('services.aliexpress.low_stock_threshold', 5);

        return Product::source('aliexpress')
            ->bySeller($sellerId)
            ->with(['category:id,name'])
            ->where('stock_quantity', '<=', $threshold)
            ->orderBy('stock_quantity')
            ->get();
    }

    // ─── Core mutation ─────────────────────────────────────────────

    protected function mutate(
        Product $product,
        InventoryTransactionType $type,
        int $quantity,
        ?string $referenceType,
        ?int $referenceId,
        ?string $notes,
        ?int $createdBy,
    ): InventoryTransaction {
        return DB::transaction(function () use ($product, $type, $quantity, $referenceType, $referenceId, $notes, $createdBy) {
            // Lock the row so concurrent reservations can't oversell.
            $locked = Product::whereKey($product->id)->lockForUpdate()->first();
            if (! $locked) {
                throw new \Exception('Product not found.', 404);
            }

            $stockBefore = (int) $locked->stock_quantity;
            $reservedBefore = (int) $locked->reserved_quantity;
            $delta = $quantity;

            $stockAfter = $stockBefore;
            $reservedAfter = $reservedBefore;

            if ($type->affectsStock()) {
                $stockAfter = $stockBefore + $delta;
                if ($stockAfter < 0) {
                    throw new \Exception(
                        "Insufficient stock. Available: {$this->availableQuantity($locked)}.",
                        422
                    );
                }
            }

            if ($type->affectsReserved()) {
                $reservedAfter = $reservedBefore + $delta;
                if ($reservedAfter < 0) {
                    throw new \Exception('Cannot release more than the reserved quantity.', 422);
                }
            }

            if ($type === InventoryTransactionType::Ship) {
                // Stock and reserved drop together; available unchanged.
                // Tolerates shipping un-reserved stock (orders confirmed via
                // the generic status endpoint never reserved anything).
                $shipping = abs($delta);
                $stockAfter = $stockBefore - $shipping;
                $reservedAfter = max(0, $reservedBefore - $shipping);
                if ($stockAfter < 0) {
                    throw new \Exception(
                        "Insufficient stock to ship for '{$locked->title}'. Available: {$this->availableQuantity($locked)}.",
                        422
                    );
                }
            }

            $locked->update([
                'stock_quantity' => $stockAfter,
                'reserved_quantity' => $reservedAfter,
            ]);

            $transaction = InventoryTransaction::create([
                'product_id' => $locked->id,
                'seller_id' => $locked->seller_id,
                'aliexpress_account_id' => $locked->aliexpress_account_id,
                'type' => $type->value,
                'quantity' => $delta,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'reserved_before' => $reservedBefore,
                'reserved_after' => $reservedAfter,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'notes' => $notes,
                'created_by' => $createdBy,
            ]);

            return $transaction;
        });
    }

    protected function reserve(Product $product, int $quantity, string $referenceType, int $referenceId, ?string $notes, ?int $createdBy): InventoryTransaction
    {
        $this->assertPositive($quantity);

        return DB::transaction(function () use ($product, $quantity, $referenceType, $referenceId, $notes, $createdBy) {
            $locked = Product::whereKey($product->id)->lockForUpdate()->first();
            if (! $locked) {
                throw new \Exception('Product not found.', 404);
            }

            if ((int) $locked->stock_quantity - (int) $locked->reserved_quantity < $quantity) {
                throw new \Exception(
                    "Insufficient available stock for '{$locked->title}'. Available: "
                    .max(0, (int) $locked->stock_quantity - (int) $locked->reserved_quantity).'.',
                    422
                );
            }

            $reservedBefore = (int) $locked->reserved_quantity;
            $locked->update(['reserved_quantity' => $reservedBefore + $quantity]);

            return InventoryTransaction::create([
                'product_id' => $locked->id,
                'seller_id' => $locked->seller_id,
                'aliexpress_account_id' => $locked->aliexpress_account_id,
                'type' => InventoryTransactionType::Reserve->value,
                'quantity' => $quantity,
                'stock_before' => (int) $locked->stock_quantity,
                'stock_after' => (int) $locked->stock_quantity,
                'reserved_before' => $reservedBefore,
                'reserved_after' => $reservedBefore + $quantity,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'notes' => $notes,
                'created_by' => $createdBy,
            ]);
        });
    }

    protected function assertPositive(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new \Exception('Quantity must be greater than zero.', 422);
        }
    }
}
