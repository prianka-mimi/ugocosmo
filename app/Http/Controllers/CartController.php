<?php

namespace App\Http\Controllers;

use App\Http\Requests\CartAddRequest;
use App\Http\Requests\CartUpdateRequest;
use App\Http\Resources\CartResource;
use App\Manager\CartManager;
use App\Manager\CheckoutManager;
use App\Models\CartItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CartController extends Controller
{
    protected CartManager $cartManager;

    protected CheckoutManager $checkoutManager;

    public function __construct(CartManager $cartManager, CheckoutManager $checkoutManager)
    {
        $this->cartManager = $cartManager;
        $this->checkoutManager = $checkoutManager;
    }

    public function show(): JsonResponse
    {
        try {
            $this->data = CartResource::make($this->cartManager->show());
            $this->status_message = 'Cart retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Cart retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function add(CartAddRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->cartManager->add($request->validated());
            DB::commit();

            $this->status_message = 'Item added to cart';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Cart add error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $code = is_numeric($e->getCode()) && $e->getCode() >= 400 && $e->getCode() <= 599 ? (int) $e->getCode() : 422;
            $this->status_code = $code;
        }

        return $this->JsonResponseFormat();
    }

    public function update(CartUpdateRequest $request, CartItem $cartItem): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->cartManager->updateQuantity($cartItem, $request->input('quantity'));
            DB::commit();

            $this->status_message = 'Cart item updated';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Cart update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function remove(CartItem $cartItem): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->cartManager->remove($cartItem);
            DB::commit();

            $this->data = [];
            $this->status_message = 'Item removed from cart';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Cart remove error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function clear(): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->cartManager->clear();
            DB::commit();

            $this->data = [];
            $this->status_message = 'Cart cleared';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Cart clear error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function count(): JsonResponse
    {
        try {
            $this->data = ['item_count' => $this->cartManager->count()];
            $this->status_message = 'Cart count retrieved';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Cart count error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * GET /api/cart/detail
     *
     * Full cart detail page data in a single call:
     * - Cart items (name, quantity, price, image, seller)
     * - Address status (has_address, saved_addresses list)
     * - Seller-enabled shipping methods with nested live carrier rates
     *   (only shown when address is selected; disabled state flag when no address)
     * - Cart summary (subtotal, shipping, total)
     *
     * Query params:
     *   address_id   int     — selected saved address ID
     *   cart_item_ids array  — optional subset of cart items
     */
    public function detail(Request $request): JsonResponse
    {
        try {
            $user = auth()->guard('jwt')->user();
            $profile = $user?->profile;

            if (! $profile) {
                throw new \Exception('Profile not found. Complete your profile first.', 400);
            }

            // 1. Cart items
            $cart = $this->cartManager->show();
            $items = $cart->items;

            if ($request->filled('cart_item_ids')) {
                $ids = is_array($request->input('cart_item_ids'))
                    ? $request->input('cart_item_ids')
                    : explode(',', (string) $request->input('cart_item_ids'));
                $items = $items->whereIn('id', array_filter(array_map('intval', $ids)));
            }

            $formattedItems = $items->map(function ($item) {
                return [
                    'id'         => $item->id,
                    'item_type'  => $item->item_type,
                    'product_id' => $item->product_id,
                    'title'      => $item->title,
                    'image_url'  => $item->image_url,
                    'unit_price' => (float) $item->unit_price,
                    'quantity'   => (int) $item->quantity,
                    'subtotal'   => round((float) $item->unit_price * $item->quantity, 2),
                    'seller'     => $item->product?->seller ? [
                        'id'        => $item->product->seller->id,
                        'full_name' => $item->product->seller->full_name,
                        'username'  => $item->product->seller->username,
                    ] : null,
                    'available_shipping_options' => $item->product
                        ? array_map(fn ($o) => $o->toArray(), $item->product->getEnabledShippingOptions())
                        : [],
                ];
            })->values()->all();

            // 2. Address status
            $savedAddresses = $profile->savedAddresses()->orderByDesc('is_default')->get();
            $hasAddress = $savedAddresses->isNotEmpty();

            $addressId = $request->input('address_id');
            $selectedAddress = null;
            if ($addressId) {
                $selectedAddress = $savedAddresses->firstWhere('id', (int) $addressId);
            }
            if (! $selectedAddress) {
                $selectedAddress = $savedAddresses->firstWhere('is_default', true) ?? $savedAddresses->first();
            }

            $formattedAddresses = $savedAddresses->map(fn ($a) => [
                'id'             => $a->id,
                'label'          => $a->label,
                'name'           => $a->name,
                'address_line1'  => $a->address_line1,
                'address_line2'  => $a->address_line2,
                'city_locality'  => $a->city_locality,
                'state_province' => $a->state_province,
                'postal_code'    => $a->postal_code,
                'country_code'   => $a->country_code,
                'phone'          => $a->phone,
                'is_default'     => (bool) $a->is_default,
            ])->values()->all();

            // 3. Shipping methods with nested carrier rates
            // shipping_methods_enabled = address exists (but rates only load when address selected)
            // shipping_methods_active  = address is actually selected
            $shippingMethods = [];
            $shippingMethodsEnabled = $hasAddress;   // show section (disabled state)
            $shippingMethodsActive  = $selectedAddress !== null; // rates loaded

            if ($shippingMethodsActive && $items->isNotEmpty()) {
                // Delegate to buyerShippingMethods logic via ShipmentController
                // Build a synthetic request with address_id resolved
                $syntheticRequest = Request::create('/api/cart/shipping-methods', 'GET', [
                    'address_id'    => $selectedAddress->id ?? null,
                    'cart_item_ids' => $items->pluck('id')->all(),
                ]);
                $syntheticRequest->headers->set('Authorization', $request->header('Authorization'));
                $syntheticRequest->setUserResolver(fn () => $user);

                $methodsResponse = app(\App\Http\Controllers\ShipmentController::class)
                    ->buyerShippingMethods($syntheticRequest);

                $methodsData = json_decode($methodsResponse->getContent(), true);
                $shippingMethods = $methodsData['data']['shipping_methods'] ?? [];
            } elseif ($shippingMethodsEnabled && $items->isNotEmpty()) {
                // Address exists but none selected yet — return method stubs (disabled)
                $products = $items->filter(fn ($i) => $i->product)->map(fn ($i) => $i->product);
                $intersection = null;
                foreach ($products as $prod) {
                    $enabled = $prod->getEnabledShippingOptionKeys();
                    $intersection = $intersection === null ? $enabled : array_values(array_intersect($intersection, $enabled));
                }
                foreach ($intersection ?? [] as $key) {
                    $dto = \App\DTOs\ShippingOptionDTO::make($key, 0);
                    $shippingMethods[] = [
                        'key'               => $dto->key,
                        'name'              => $dto->name,
                        'estimated_delivery'=> $dto->estimatedDelivery,
                        'description'       => $dto->description,
                        'is_available'      => true,
                        'starting_from'     => null,
                        'rates_count'       => 0,
                        'rates'             => [],
                    ];
                }
            }

            // 4. Summary
            $summary = $this->cartManager->summary();

            $this->data = [
                'items_count'              => (int) $items->sum('quantity'),
                'items'                    => $formattedItems,
                'address' => [
                    'has_address'           => $hasAddress,
                    'message'               => $hasAddress ? null : 'Add address to go further',
                    'selected_address'      => $selectedAddress,
                    'saved_addresses'       => $formattedAddresses,
                ],
                'shipping' => [
                    'enabled'               => $shippingMethodsEnabled,  // show section
                    'active'                => $shippingMethodsActive,   // rates loaded
                    'disabled_reason'       => ! $shippingMethodsEnabled ? 'No address saved' : (! $shippingMethodsActive ? 'Select an address to see rates' : null),
                    'shipping_methods'      => $shippingMethods,
                    'selected_shipping'     => $summary['selected_shipping'] ?? null,
                ],
                'summary' => [
                    'subtotal'              => $summary['subtotal'],
                    'sub_total'             => $summary['subtotal'],
                    'shipping_total'        => $summary['shipping_total'],
                    'shipping_amount'       => $summary['shipping_total'],
                    'shipping_fee'          => $summary['shipping_total'],
                    'local_shipping'        => $summary['local_shipping'],
                    'aliexpress_shipping'   => $summary['aliexpress_shipping'],
                    'buyer_fee'             => 0.00,
                    'total'                 => $summary['total'],
                    'total_amount'          => $summary['total'],
                    'currency'              => 'USD',
                ],
            ];

            $this->status_message = 'Cart detail retrieved successfully';
            $this->status_code = 200;
        } catch (\Throwable $e) {
            Log::error('Cart detail error: ' . $e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * POST /api/cart/select-shipping
     *
     * Store buyer's selected shipping rate in cart before checkout.
     */
    public function selectShipping(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'rate_id'    => ['nullable', 'string', 'max:255'],
                'carrier_id' => ['nullable', 'string', 'max:255'],
                'shipping_method' => ['nullable', 'string', 'max:255'],
                'shipping_amount' => ['nullable', 'numeric', 'min:0'],
                'currency' => ['nullable', 'string', 'max:10'],
            ]);

            $user      = auth()->guard('jwt')->user();
            $profileId = (int) ($user->profile?->id ?? $user->id);
            $lookupKey = $validated['rate_id'] ?? $validated['carrier_id'] ?? null;

            $snapshot = null;
            if ($lookupKey) {
                $cached   = \Illuminate\Support\Facades\Cache::get("checkout:rates:user:{$profileId}", []);
                $snapshot = $cached[$lookupKey] ?? null;
            }

            $enriched = [
                'rate_id'                => $validated['rate_id']         ?? ($snapshot['rate_id']       ?? null),
                'carrier_id'             => $validated['carrier_id']      ?? ($snapshot['carrier_id']    ?? null),
                'carrier_code'           => $snapshot['carrier_code']     ?? ($snapshot['carrier_friendly_name'] ?? null),
                'service_code'           => $snapshot['service_code']     ?? null,
                'service_name'           => $snapshot['service_name']     ?? null,
                'shipping_method'        => $validated['shipping_method'] ?? ($snapshot['shipping_method'] ?? ($snapshot['shipping_option_key'] ?? null)),
                'shipping_amount'        => (float) ($validated['shipping_amount'] ?? $snapshot['shipping_amount'] ?? 0),
                'currency'               => $validated['currency']        ?? ($snapshot['currency'] ?? 'USD'),
                'selected_rate_snapshot' => $snapshot,
            ];

            $this->data = $this->cartManager->selectShipping($enriched);
            $this->status_message = 'Shipping method selected successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Cart select shipping error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * GET /api/cart/summary
     *
     * Computes Subtotal + AliExpress shipping (synced) + Selected Local ShipEngine rate.
     * ZERO buyer fee.
     */
    public function summary(): JsonResponse
    {
        try {
            $this->data = $this->cartManager->summary();
            $this->status_message = 'Cart summary retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Cart summary error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
