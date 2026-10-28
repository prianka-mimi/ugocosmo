<?php

namespace App\Services\Fulfillment\Contracts;

use App\Models\Order;

interface FulfillmentStrategyInterface
{
    /**
     * Fulfill the order.
     *
     * @param  array  $data  Optional data for fulfillment (like tracking_number, courier_name)
     */
    public function fulfill(Order $order, array $data = []): void;
}
