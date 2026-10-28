<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentIntentRequest;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Fulfillment\FulfillmentProviderResolver;
use App\Services\StripePaymentIntentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

class PaymentController extends Controller
{
    public function __construct(
        protected StripePaymentIntentService $stripePaymentService,
        protected FulfillmentProviderResolver $fulfillmentResolver
    ) {}

    /**
     * Create a Stripe PaymentIntent for an order.
     */
    public function createPaymentIntent(PaymentIntentRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $order = $request->getOrder();

            // Check if there is an existing pending payment intent for this order to reuse
            $existingPayment = Payment::where('order_id', $order->id)
                ->where('payment_status', 'pending')
                ->first();

            if ($existingPayment && $existingPayment->payment_intent_id) {
                try {
                    $paymentIntent = $this->stripePaymentService->retrievePaymentIntent($existingPayment->payment_intent_id);

                    return response()->json([
                        'success' => true,
                        'message' => 'PaymentIntent retrieved successfully (reused).',
                        'client_secret' => $paymentIntent->client_secret,
                        'payment_intent_id' => $paymentIntent->id,
                    ], 200);
                } catch (\Exception $stripeEx) {
                    // If retrieve fails (e.g. expired or cancelled on Stripe), proceed to create a new one
                    Log::warning("Failed to retrieve existing PaymentIntent {$existingPayment->payment_intent_id}: ".$stripeEx->getMessage());
                }
            }

            // Create new PaymentIntent on Stripe
            $paymentIntent = $this->stripePaymentService->createPaymentIntent($order, $user);

            // Record payment intent details in local database
            Payment::create([
                'order_id' => $order->id,
                'user_id' => $user->id,
                'payment_intent_id' => $paymentIntent->id,
                'amount' => $order->total,
                'currency' => strtoupper($paymentIntent->currency ?? 'USD'),
                'payment_status' => 'pending',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'PaymentIntent created successfully.',
                'client_secret' => $paymentIntent->client_secret,
                'payment_intent_id' => $paymentIntent->id,
            ], 201);

        } catch (ApiErrorException $e) {
            Log::error('Stripe PaymentIntent API error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Stripe API error: '.$e->getMessage(),
            ], 502);
        } catch (\Exception $e) {
            Log::error('Create PaymentIntent server error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Server error: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/payments/{order}/pay
     *
     * Retrieve payment details for a pending order so the buyer can pay later.
     * Returns client_secret (Flutter) + payment_url (web).
     * Blocks duplicate payment if the order is already paid.
     */
    public function getPaymentDetails(Order $order, Request $request): JsonResponse
    {
        try {
            $user = auth()->guard('jwt')->user();
            $profile = $user?->profile;

            if (! $profile || (int) $order->buyer_id !== (int) $profile->id) {
                return response()->json(['status' => false, 'status_message' => 'Unauthorized.'], 403);
            }

            // Block payment only if an order requires live dynamic rate selection and has neither
            // a selected rate ID nor a settled shipping method/amount from checkout.
            $hasPresetShipping = ! empty($order->shipping_method) && $order->shipping_amount !== null;
            if (! $hasPresetShipping && empty($order->selected_rate_id) && $this->fulfillmentResolver->orderRequiresPrePaymentShippingRate($order)) {
                return response()->json([
                    'status' => false,
                    'status_code' => 422,
                    'status_message' => 'Please select a shipping rate before paying.',
                    'data' => [
                        'order_id' => $order->id,
                        'next_steps' => [
                            'step_1' => 'POST /api/shipengine/get-rates with {"order_id": '.$order->id.'} to get rates.',
                            'step_2' => 'Select preferred carrier rate before finalizing payment.',
                            'step_3' => 'GET /api/payments/'.$order->id.'/pay to generate payment.',
                        ],
                    ],
                ], 422);
            }

            // Block duplicate payment
            if (in_array($order->status, ['processing', 'shipped', 'delivered', 'completed'], true)) {
                return response()->json([
                    'status' => false,
                    'status_code' => 422,
                    'status_message' => 'This order has already been paid.',
                    'data' => ['order_id' => $order->id, 'order_status' => $order->status],
                ], 422);
            }

            if ($order->status === 'cancelled') {
                return response()->json([
                    'status' => false,
                    'status_code' => 422,
                    'status_message' => 'This order has been cancelled and cannot be paid.',
                    'data' => ['order_id' => $order->id, 'order_status' => $order->status],
                ], 422);
            }

            $paymentIntentId = $order->stripe_payment_intent_id;
            $clientSecret = null;
            $paymentUrl = null;

            // Reuse existing PaymentIntent if valid
            if ($paymentIntentId) {
                try {
                    $intent = $this->stripePaymentService->retrievePaymentIntent($paymentIntentId);

                    // Double-check not already paid on Stripe side
                    if (in_array($intent->status ?? '', ['succeeded', 'processing'], true)) {
                        return response()->json([
                            'status' => false,
                            'status_code' => 422,
                            'status_message' => 'This order has already been paid.',
                            'data' => ['order_id' => $order->id, 'order_status' => $order->status],
                        ], 422);
                    }

                    $clientSecret = $intent->client_secret;
                } catch (\Throwable) {
                    $paymentIntentId = null;
                }
            }

            // Create new PaymentIntent if none exists or previous was invalid
            if (! $paymentIntentId || ! $clientSecret) {
                $intent = $this->stripePaymentService->createPaymentIntent($order, $user);
                $paymentIntentId = $intent->id;
                $clientSecret = $intent->client_secret;

                $order->update(['stripe_payment_intent_id' => $paymentIntentId]);

                Payment::updateOrCreate(
                    ['order_id' => $order->id, 'payment_status' => 'pending'],
                    [
                        'user_id' => $user->id,
                        'payment_intent_id' => $paymentIntentId,
                        'amount' => $order->total,
                        'currency' => 'USD',
                        'payment_status' => 'pending',
                    ]
                );
            }

            // Generate Checkout Session URL for web payment
            $successUrl = $request->input('success_url');
            $cancelUrl = $request->input('cancel_url');

            $session = $this->stripePaymentService->createCheckoutSession([$order], $user, $successUrl, $cancelUrl);
            $paymentUrl = $session['url'] ?? null;

            return response()->json([
                'status' => true,
                'status_code' => 200,
                'status_message' => 'Payment details retrieved. Complete your payment.',
                'data' => [
                    'order_id' => $order->id,
                    'order_status' => $order->status,
                    'total' => (float) $order->total,
                    'currency' => 'USD',
                    'payment_intent_id' => $paymentIntentId,
                    'client_secret' => $clientSecret,
                    'payment_url' => $paymentUrl,
                ],
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Get payment details error: '.$e->getMessage());

            return response()->json(['status' => false, 'status_message' => $e->getMessage()], 500);
        }
    }
}
