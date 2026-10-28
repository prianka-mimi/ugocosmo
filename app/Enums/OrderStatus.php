<?php

namespace App\Enums;

/**
 * Marketplace order lifecycle.
 *
 * The first half (pending → confirmed → processing → shipped → delivered)
 * is the pre-existing flow; the AliExpress sourcing states
 * (awaiting_purchase → purchased → stock_received → ready_to_ship) slot in
 * between confirmed and shipped without breaking existing transitions.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case AwaitingPurchase = 'awaiting_purchase';
    case Purchased = 'purchased';
    case StockReceived = 'stock_received';
    case ReadyToShip = 'ready_to_ship';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Processing => 'Processing',
            self::AwaitingPurchase => 'Awaiting Purchase',
            self::Purchased => 'Purchased',
            self::StockReceived => 'Stock Received',
            self::ReadyToShip => 'Ready to Ship',
            self::Shipped => 'Shipped',
            self::Delivered => 'Delivered',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
