<?php

namespace App\Services\Fulfillment\Strategies;

use App\Actions\ReleaseEscrowFundsAction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Fulfillment\Contracts\FulfillmentProviderInterface;
use InvalidArgumentException;

class ManualFulfillmentStrategy implements FulfillmentProviderInterface
{
    public function getSource(): string
    {
        return 'manual';
    }

    public function requiresPrePaymentShippingRate(Order|OrderItem|Product $entity): bool
    {
        return false;
    }

    public function validateItemAvailability(Product $product, ?string $countryCode = null, int $quantity = 1, array $options = []): array
    {
        $stock = (int) $product->stock_quantity;
        $available = $stock >= $quantity;

        return [
            'available' => $available,
            'reason' => $available ? null : "Requested quantity ({$quantity}) exceeds available stock ({$stock}).",
            'details' => [
                'stock_quantity' => $stock,
                'requested_quantity' => $quantity,
                'source' => 'manual',
            ],
        ];
    }

    public function fulfill(Order $order, array $data = []): void
    {
        $this->fulfillOrder($order, $data);
    }

    public function fulfillOrder(Order $order, array $data = []): array
    {
        $status = $data['status'] ?? null;

        if (! in_array($status, ['processing', 'shipped', 'delivered'], true)) {
            throw new InvalidArgumentException('Invalid status for manual fulfillment.');
        }

        $updates = ['status' => $status];

        // Track when it was shipped or delivered
        if ($status === 'shipped' && ! $order->shipped_at) {
            $updates['shipped_at'] = now();
        } elseif ($status === 'delivered' && ! $order->delivered_at) {
            $updates['delivered_at'] = now();
        }

        // Allow adding courier name and tracking number manually
        if (isset($data['tracking_number'])) {
            $updates['tracking_number'] = $data['tracking_number'];
        }

        if (isset($data['supplier_name'])) {
            // Using supplier_name as courier_name per DB schema constraints or order metadata
            $updates['supplier_name'] = $data['supplier_name'];
        }

        $order->update($updates);

        // Payout escrow if delivered
        if ($status === 'delivered') {
            app(ReleaseEscrowFundsAction::class)->execute($order);
        }

        return [
            'success' => true,
            'status' => $order->fresh()->status,
            'tracking_number' => $order->tracking_number,
            'supplier_name' => $order->supplier_name,
        ];
    }
}
