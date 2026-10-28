<?php

namespace App\Services\Fulfillment;

use App\Models\Order;
use App\Services\Fulfillment\Contracts\FulfillmentProviderInterface;
use Exception;

class OrderFulfillmentService
{
    public function __construct(
        protected FulfillmentProviderResolver $resolver
    ) {}

    /**
     * Fulfill an order using the appropriate strategy resolved dynamically.
     *
     * @param  array<string, mixed>  $data  Additional data (status, tracking, rate_id, etc.)
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function fulfill(Order $order, array $data = []): array
    {
        $provider = $this->resolver->resolveForOrder($order);

        return $provider->fulfillOrder($order, $data);
    }

    /**
     * Resolve the fulfillment provider for an order.
     */
    public function resolveStrategy(Order $order): FulfillmentProviderInterface
    {
        return $this->resolver->resolveForOrder($order);
    }
}
