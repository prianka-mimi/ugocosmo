<?php

namespace App\Enums;

/**
 * Inventory movement types recorded in inventory_transactions.
 */
enum InventoryTransactionType: string
{
    case Receive = 'receive';       // stock in (seller received AliExpress goods)
    case Adjustment = 'adjustment'; // manual stock correction
    case Reserve = 'reserve';       // stock set aside for a confirmed order
    case Release = 'release';       // reservation removed (order cancelled)
    case Ship = 'ship';             // stock out (seller shipped the order)

    public function affectsStock(): bool
    {
        return in_array($this, [self::Receive, self::Adjustment, self::Ship], true);
    }

    public function affectsReserved(): bool
    {
        return in_array($this, [self::Reserve, self::Release, self::Ship], true);
    }
}
