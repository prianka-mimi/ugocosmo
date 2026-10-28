<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Profile;
use App\Models\Shipment;
use Illuminate\Database\Seeder;

/**
 * Creates orders that are ready for the ShipEngine shipping flow.
 *
 * The ShipEngine endpoints require more than the generic OrderSeeder
 * provides:
 *
 *   - order status must be `ready_to_ship` (ShipmentService asserts this
 *     before creating a shipment or purchasing a label),
 *   - shipping_address must be a complete JSON object (name, line1, city,
 *     postal_code, country — ShipEngineAddress::fromOrder throws otherwise),
 *   - order items must reference products with a weight > 0 and, ideally,
 *     package dimensions (ShipEnginePackage::fromProduct throws otherwise).
 *
 * This seeder creates those orders, the shippable products they need, and
 * a local shipment record per order so every shipping endpoint can be
 * exercised immediately. It is idempotent — safe to run repeatedly.
 *
 * IMPORTANT (sandbox testing): every seeded order ships to a **US**
 * destination on purpose. ShipEngine's sandbox test carriers (Stamps.com /
 * USPS, UPS, DHL test accounts) only return rates for domestic US
 * shipments — international destinations return an empty rates list. In
 * production with real carrier accounts, international orders work; the
 * sandbox dataset keeps every order testable end-to-end.
 *
 * Note: the seller's ship-from origin is read from SHIPENGINE_SHIP_FROM in
 * your .env, not from the database. Set it before calling the shipping
 * endpoints (see config/services.php).
 */
class ShipEngineOrderSeeder extends Seeder
{
    /**
     * [buyer, seller] pairs with a shippable product each. Several orders
     * share alice as the seller so one login has multiple test orders.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    protected array $pairs = [
        ['grace', 'alice'],
        ['bob',   'alice'],
        ['frank', 'alice'],
        ['isla',  'eva'],
        ['david', 'jack'],
    ];

    /**
     * Shippable product per seller key: title, price, weight (in the unit
     * configured via SHIPENGINE_WEIGHT_UNIT, default ounce), dimensions in
     * inches, and stock.
     *
     * @var array<string, array{title: string, price: float, weight: float, length: float, width: float, height: float, stock: int}>
     */
    protected array $products = [
        'alice' => [
            'title' => 'Handmade Ceramic Mug',
            'price' => 24.99,
            'weight' => 12.0,
            'length' => 4.5,
            'width' => 3.5,
            'height' => 4.0,
            'stock' => 50,
        ],
        'eva' => [
            'title' => 'Framed Photo Print (A4)',
            'price' => 59.99,
            'weight' => 24.0,
            'length' => 13.0,
            'width' => 2.0,
            'height' => 17.0,
            'stock' => 25,
        ],
        'jack' => [
            'title' => 'Bluetooth Speaker (Mini)',
            'price' => 34.99,
            'weight' => 10.5,
            'length' => 4.0,
            'width' => 4.0,
            'height' => 5.5,
            'stock' => 100,
        ],
    ];

    /**
     * Complete buyer shipping addresses keyed by buyer username. All
     * destinations are US so sandbox rate/label calls succeed (see class
     * docblock). Country is ISO 3166-1 alpha-2.
     *
     * @var array<string, array<string, string>>
     */
    protected array $addresses = [
        'grace' => [
            'name' => 'Grace Kim',
            'line1' => '2120 Maple Avenue',
            'line2' => 'Apt 4B',
            'city' => 'Los Angeles',
            'state' => 'CA',
            'postal_code' => '90012',
            'country' => 'US',
            'phone' => '+1 555-0101',
            'email' => 'grace@example.com',
        ],
        'bob' => [
            'name' => 'Bob Smith',
            'line1' => '45 Broadway',
            'line2' => 'Floor 12',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10006',
            'country' => 'US',
            'phone' => '+1 212-555-0102',
            'email' => 'bob@example.com',
        ],
        'frank' => [
            'name' => 'Frank Lee',
            'line1' => '1 Michigan Avenue',
            'line2' => 'Suite 502',
            'city' => 'Chicago',
            'state' => 'IL',
            'postal_code' => '60601',
            'country' => 'US',
            'phone' => '+1 312-555-0103',
            'email' => 'frank@example.com',
        ],
        'isla' => [
            'name' => 'Isla Turner',
            'line1' => '800 Pike Street',
            'line2' => '',
            'city' => 'Seattle',
            'state' => 'WA',
            'postal_code' => '98101',
            'country' => 'US',
            'phone' => '+1 206-555-0104',
            'email' => 'isla@example.com',
        ],
        'david' => [
            'name' => 'David Brown',
            'line1' => '88 Market Street',
            'line2' => 'Suite 12',
            'city' => 'San Francisco',
            'state' => 'CA',
            'postal_code' => '94105',
            'country' => 'US',
            'phone' => '+1 415-555-0105',
            'email' => 'david@example.com',
        ],
    ];

    public function run(): void
    {
        $profiles = $this->profiles();
        $physical = ProductCategory::where('product_type', 'physical')->first();

        foreach ($this->pairs as [$buyerKey, $sellerKey]) {
            $buyer = $profiles[$buyerKey] ?? null;
            $seller = $profiles[$sellerKey] ?? null;

            if (! $buyer || ! $seller) {
                $this->command?->warn("ShipEngineOrderSeeder: skipping {$buyerKey}/{$sellerKey} — profile missing. Run UserSeeder + ProfileSeeder first.");

                continue;
            }

            $product = $this->shippableProduct($seller, $physical);
            $order = $this->order($buyer, $seller, $product, $this->addresses[$buyerKey]);
            $shipment = $this->shipment($order);

            $this->command?->info(sprintf(
                'ShipEngine order ready: #%d (buyer: %s, seller: %s, shipment: #%d, ship-to: %s)',
                $order->id,
                $buyerKey,
                $sellerKey,
                $shipment->id,
                $this->addresses[$buyerKey]['city']
            ));
        }
    }

    /**
     * Ensure the seller has a shippable physical product (weight + package
     * dimensions). Reuses an existing product with that title/seller.
     */
    protected function shippableProduct(Profile $seller, ?ProductCategory $physical): Product
    {
        $spec = $this->products[$seller->user?->username] ?? null;

        if (! $spec) {
            throw new \RuntimeException(
                "ShipEngineOrderSeeder: no product spec for seller '{$seller->user?->username}'. Add one to \$products."
            );
        }

        return Product::firstOrCreate(
            ['title' => $spec['title'], 'seller_id' => $seller->id],
            [
                'seller_id' => $seller->id,
                'title' => $spec['title'],
                'description' => 'Shippable test product seeded for the ShipEngine integration.',
                'price' => $spec['price'],
                'currency' => 'USD',
                'category_id' => $physical?->id,
                'stock_quantity' => $spec['stock'],
                'product_type' => 'physical',
                'condition' => 'new',
                'weight' => $spec['weight'],
                'package_dimensions' => [
                    'length' => $spec['length'],
                    'width' => $spec['width'],
                    'height' => $spec['height'],
                    'unit' => 'inch',
                ],
                'is_active' => true,
            ]
        );
    }

    /**
     * Create a ready_to_ship order for the pair with a complete shipping
     * address and one line item. Idempotent per buyer/seller pair.
     */
    protected function order(Profile $buyer, Profile $seller, Product $product, array $address): Order
    {
        $quantity = 1;
        $subtotal = round((float) $product->price * $quantity, 2);

        $order = Order::firstOrCreate(
            ['buyer_id' => $buyer->id, 'seller_id' => $seller->id],
            [
                'buyer_id' => $buyer->id,
                'seller_id' => $seller->id,
                'status' => OrderStatus::ReadyToShip->value,
                'subtotal' => $subtotal,
                'buyer_fee' => round($subtotal * 0.03, 2),
                'seller_fee' => round($subtotal * 0.05, 2),
                'platform_fee_total' => round($subtotal * 0.08, 2),
                'tax_amount' => 0.00,
                'total' => round($subtotal * 1.03, 2),
                'shipping_address' => $address,
                'purchased_at' => now()->subDays(3),
                'stock_received_at' => now()->subDay(),
            ]
        );

        OrderItem::firstOrCreate(
            ['order_id' => $order->id, 'product_id' => $product->id],
            [
                'order_id' => $order->id,
                'product_id' => $product->id,
                'title' => $product->title,
                'quantity' => $quantity,
                'unit_price' => $product->price,
                'total_price' => $subtotal,
            ]
        );

        return $order;
    }

    /**
     * Create the local shipment record so shipping endpoints work without
     * a prior POST /api/shipping/shipments call. Idempotent per order.
     */
    protected function shipment(Order $order): Shipment
    {
        return Shipment::firstOrCreate(
            ['order_id' => $order->id],
            ['status' => ShipmentStatus::Created->value]
        );
    }

    /**
     * @return array<string, Profile>
     */
    protected function profiles(): array
    {
        $map = [];
        $usernames = array_unique(array_merge(
            array_column($this->pairs, 0),
            array_column($this->pairs, 1)
        ));

        foreach ($usernames as $username) {
            $profile = Profile::whereHas('user', fn ($q) => $q->where('username', $username))->first();
            if ($profile) {
                $map[$username] = $profile;
            }
        }

        return $map;
    }
}
