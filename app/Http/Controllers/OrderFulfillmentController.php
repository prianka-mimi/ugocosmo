<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Support\ApiResponse;
use App\Traits\ResolvesAuthUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class OrderFulfillmentController extends Controller
{
    use ResolvesAuthUser;

    public function __construct(
        protected OrderFulfillmentService $fulfillmentService
    ) {}

    /**
     * Endpoint for the seller to fulfill custom products.
     * Routes automatically to ShipEngine or Manual flow depending on the product settings.
     */
    public function fulfill(Request $request, Order $order): JsonResponse
    {
        try {
            // Verify ownership
            if ($order->seller_id !== $this->user()->profile->id) {
                return ApiResponse::error('Unauthorized.', 403);
            }

            // Validate common payload for manual or automated overrides
            $data = $request->validate([
                'status' => ['nullable', 'string', 'in:processing,shipped,delivered'],
                'tracking_number' => ['nullable', 'string', 'max:255'],
                'courier_name' => ['nullable', 'string', 'max:255'],
                'supplier_name' => ['nullable', 'string', 'max:255'],
            ]);

            if (empty($data['supplier_name']) && ! empty($data['courier_name'])) {
                $data['supplier_name'] = $data['courier_name'];
            }

            $this->fulfillmentService->fulfill($order, $data);

            return ApiResponse::success(null, 'Order fulfillment updated successfully.');
        } catch (Throwable $e) {
            $statusCode = $e->getCode();
            $httpStatus = ($statusCode >= 400 && $statusCode < 600) ? $statusCode : 500;

            return ApiResponse::error($e->getMessage(), $httpStatus);
        }
    }
}
