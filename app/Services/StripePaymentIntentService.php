<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Str;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\Webhook as StripeWebhook;

class StripePaymentIntentService
{
    protected StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('services.stripe.secret') ?? 'sk_test_placeholder');
    }

    /**
     * Create a new Stripe PaymentIntent for the given order.
     */
    public function createPaymentIntent(Order $order, User $user): PaymentIntent
    {
        $amountInCents = (int) round($order->total * 100);

        if (app()->environment('testing') || config('services.stripe.secret') === 'sk_test_placeholder' || empty(config('services.stripe.secret'))) {
            $mockId = 'pi_'.Str::random(24);

            return PaymentIntent::constructFrom([
                'id' => $mockId,
                'client_secret' => $mockId.'_secret_'.Str::random(24),
                'currency' => 'usd',
                'amount' => $amountInCents,
                'metadata' => [
                    'order_id' => (string) $order->id,
                    'user_id' => (string) $user->id,
                ],
                'description' => "Order #{$order->id} payment by User #{$user->id}",
            ]);
        }

        return $this->stripe->paymentIntents->create([
            'amount' => $amountInCents,
            'currency' => 'usd',
            'metadata' => [
                'order_id' => (string) $order->id,
                'user_id' => (string) $user->id,
            ],
            'description' => "Order #{$order->id} payment by User #{$user->id}",
        ]);
    }

    /**
     * Create a new Stripe PaymentIntent for the given orders.
     * Also creates a Stripe Checkout Session so non-Flutter clients
     * (web, email link) can pay by just entering card details.
     *
     * @param  Order[]  $orders
     * @return array{intent: PaymentIntent, payment_url: string}
     */
    public function createCombinedPaymentIntent(array $orders, User $user): PaymentIntent
    {
        $totalAmount = 0.0;
        $orderIds = [];
        foreach ($orders as $order) {
            $totalAmount += $order->total;
            $orderIds[] = $order->id;
        }

        $amountInCents = (int) round($totalAmount * 100);
        $orderIdsString = implode(',', $orderIds);

        if (app()->environment('testing') || config('services.stripe.secret') === 'sk_test_placeholder' || empty(config('services.stripe.secret'))) {
            $mockId = 'pi_'.Str::random(24);

            return PaymentIntent::constructFrom([
                'id' => $mockId,
                'client_secret' => $mockId.'_secret_'.Str::random(24),
                'currency' => 'usd',
                'amount' => $amountInCents,
                'metadata' => [
                    'order_ids' => $orderIdsString,
                    'user_id' => (string) $user->id,
                ],
                'description' => "Combined Order {$orderIdsString} payment by User #{$user->id}",
            ]);
        }

        return $this->stripe->paymentIntents->create([
            'amount' => $amountInCents,
            'currency' => 'usd',
            'receipt_email' => $user->email,
            'metadata' => [
                'order_ids' => $orderIdsString,
                'user_id' => (string) $user->id,
            ],
            'description' => "Combined Order {$orderIdsString} payment by User #{$user->id}",
        ]);
    }

    /**
     * Create a Stripe Checkout Session as the primary payment method.
     * Returns session url, session id, and the underlying payment_intent_id.
     * If already paid, returns null.
     *
     * @param  Order[]  $orders
     * @return array{url: string, session_id: string, payment_intent_id: string|null}|null
     */
    public function createCheckoutSession(array $orders, User $user, ?string $successUrl = null, ?string $cancelUrl = null): ?array
    {
        if (app()->environment('testing') || config('services.stripe.secret') === 'sk_test_placeholder' || empty(config('services.stripe.secret'))) {
            return null;
        }

        $orderIds = implode(',', array_map(fn ($o) => $o->id, $orders));
        $appUrl = rtrim((string) config('app.url'), '/');

        $resolvedSuccessUrl = $successUrl ?? ($appUrl.'/payment/success?order_ids='.urlencode($orderIds));
        $resolvedCancelUrl = $cancelUrl ?? ($appUrl.'/payment/cancel?order_ids='.urlencode($orderIds));

        $lineItems = [];
        foreach ($orders as $order) {
            $order->loadMissing('items');
            foreach ($order->items as $item) {
                $unitAmount = (int) round((float) $item->unit_price * 100);
                if ($unitAmount < 1) {
                    continue;
                }
                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'usd',
                        'unit_amount' => $unitAmount,
                        'product_data' => ['name' => mb_substr((string) $item->title, 0, 127)],
                    ],
                    'quantity' => max(1, (int) $item->quantity),
                ];
            }
            if ((float) $order->shipping_amount > 0) {
                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'usd',
                        'unit_amount' => (int) round((float) $order->shipping_amount * 100),
                        'product_data' => ['name' => 'Shipping'],
                    ],
                    'quantity' => 1,
                ];
            }
            $feeAmount = (float) $order->buyer_fee + (float) $order->platform_fee_total;
            if ($feeAmount > 0) {
                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'usd',
                        'unit_amount' => (int) round($feeAmount * 100),
                        'product_data' => ['name' => 'Platform Fee'],
                    ],
                    'quantity' => 1,
                ];
            }
        }

        if (empty($lineItems)) {
            $total = (int) round(array_reduce($orders, fn ($c, $o) => $c + $o->total, 0.0) * 100);
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => max(50, $total),
                    'product_data' => ['name' => 'Order #'.$orderIds],
                ],
                'quantity' => 1,
            ];
        }

        $session = $this->stripe->checkout->sessions->create([
            'payment_method_types' => ['card'],
            'mode' => 'payment',
            'customer_email' => $user->email,
            'line_items' => $lineItems,
            'success_url' => $resolvedSuccessUrl,
            'cancel_url' => $resolvedCancelUrl,
            'payment_intent_data' => [
                'metadata' => ['order_ids' => $orderIds, 'user_id' => (string) $user->id],
            ],
            'metadata' => ['order_ids' => $orderIds, 'user_id' => (string) $user->id],
        ]);

        return [
            'url' => $session->url,
            'session_id' => $session->id,
            'payment_intent_id' => $session->payment_intent,
        ];
    }

    /**
     * @deprecated Use createCheckoutSession() instead.
     */
    public function createCheckoutSessionUrl(string $paymentIntentId, array $orders, User $user, ?string $successUrl = null, ?string $cancelUrl = null): ?string
    {
        $result = $this->createCheckoutSession($orders, $user, $successUrl, $cancelUrl);

        return $result['url'] ?? null;
    }

    /**
     * Recompute an order's payable total (subtotal + fees + shipping + tax)
     * and update the pending PaymentIntent so the buyer is charged the
     * backend-verified amount — never a frontend-submitted shipping price.
     *
     * Called after the buyer selects a shipping rate at checkout. Mocks the
     * response in test/unconfigured environments like the create paths.
     *
     * @return array<string, mixed>
     */
    public function updateIntentAmountForOrder(Order $order): array
    {
        // Backend-computed final amount: order total + verified shipping.
        // tax_amount and discounts are already part of the stored total when
        // applicable (tax_amount is stored separately and currently 0).
        $finalTotal = round(
            (float) $order->subtotal
            + (float) $order->buyer_fee
            + (float) $order->platform_fee_total
            + (float) ($order->shipping_amount ?? 0)
            + (float) ($order->tax_amount ?? 0),
            2
        );

        $order->update(['total' => $finalTotal]);

        // A combined checkout charges several seller orders with ONE
        // PaymentIntent — the new amount must include every order that
        // shares it, not just the one whose shipping was just selected.
        // (No shared intent → nothing to add.)
        $paymentIntentId = $order->stripe_payment_intent_id
            ?? Payment::where('order_id', $order->id)->where('payment_status', 'pending')->latest()->value('payment_intent_id');

        $siblingTotal = 0.0;
        if ($paymentIntentId !== null) {
            $siblingTotal = (float) Order::query()
                ->where('stripe_payment_intent_id', $paymentIntentId)
                ->whereKeyNot($order->getKey())
                ->sum('total');
        }

        $finalTotal = round($finalTotal + $siblingTotal, 2);

        $amountInCents = (int) round($finalTotal * 100);

        if (app()->environment('testing') || config('services.stripe.secret') === 'sk_test_placeholder' || empty(config('services.stripe.secret')) || $paymentIntentId === null || str_starts_with((string) $paymentIntentId, 'pi_mock_')) {
            $mockId = $paymentIntentId ?? 'pi_mock_'.Str::random(24);

            return [
                'payment_intent_id' => $mockId,
                'client_secret' => $mockId.'_secret_'.Str::random(24),
                'amount' => $finalTotal,
                'currency' => $order->shipping_currency ?? 'USD',
                'updated' => true,
            ];
        }

        $this->stripe->paymentIntents->update($paymentIntentId, [
            'amount' => $amountInCents,
            'currency' => strtolower((string) ($order->shipping_currency ?? 'usd')),
        ]);

        // Keep the local payment record in sync.
        Payment::where('order_id', $order->id)
            ->where('payment_intent_id', $paymentIntentId)
            ->update(['amount' => $finalTotal]);

        return [
            'payment_intent_id' => $paymentIntentId,
            'amount' => $finalTotal,
            'currency' => $order->shipping_currency ?? 'USD',
            'updated' => true,
        ];
    }

    /**
     * Retrieve a Stripe PaymentIntent by ID.
     */
    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntent
    {
        if (app()->environment('testing') || str_starts_with($paymentIntentId, 'pi_mock_') || config('services.stripe.secret') === 'sk_test_placeholder' || empty(config('services.stripe.secret'))) {
            return PaymentIntent::constructFrom([
                'id' => $paymentIntentId,
                'client_secret' => $paymentIntentId.'_secret_'.Str::random(24),
                'currency' => 'usd',
                'amount' => 1000,
            ]);
        }

        return $this->stripe->paymentIntents->retrieve($paymentIntentId);
    }

    /**
     * Verify and construct a Webhook event.
     */
    public function constructWebhookEvent(string $payload, string $sigHeader, string $secret)
    {
        return StripeWebhook::constructEvent($payload, $sigHeader, $secret);
    }
}
