<?php

namespace App\Http\Controllers;

use App\Manager\EscrowManager;
use App\Manager\OrderManager;
use App\Manager\WalletManager;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\EscrowPayment;
use App\Models\GiftWallet;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\VerificationSubmission;
use App\Models\WalletTransaction;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Exception\SignatureVerificationException;

class StripeWebhookController extends Controller
{
    protected StripeService $stripeService;

    protected EscrowManager $escrowManager;

    protected WalletManager $walletManager;

    protected OrderManager $orderManager;

    public function __construct(
        StripeService $stripeService,
        EscrowManager $escrowManager,
        WalletManager $walletManager,
        OrderManager $orderManager
    ) {
        $this->stripeService = $stripeService;
        $this->escrowManager = $escrowManager;
        $this->walletManager = $walletManager;
        $this->orderManager = $orderManager;
    }

    /**
     * Handle incoming Stripe webhook events.
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('stripe-signature') ?? '';
        $webhookSecret = config('services.stripe.webhook_secret') ?? 'whsec_placeholder';

        try {
            // Validate signature if secret is set and not placeholder
            if ($webhookSecret && $webhookSecret !== 'whsec_placeholder' && ! empty($sigHeader)) {
                $event = $this->stripeService->constructWebhookEvent($payload, $sigHeader, $webhookSecret);
            } else {
                // In local test environments without a configured signature, decode json payload directly
                $data = json_decode($payload, true);
                $event = json_decode(json_encode([
                    'type' => $data['type'] ?? '',
                    'data' => [
                        'object' => $data['data']['object'] ?? [],
                    ],
                ]));
            }
        } catch (\UnexpectedValueException $e) {
            Log::error('Stripe webhook error: invalid payload: '.$e->getMessage());

            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (SignatureVerificationException $e) {
            Log::error('Stripe webhook error: invalid signature: '.$e->getMessage());

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        Log::info('Stripe webhook received event: '.($event->type ?? 'unknown'));

        $eventType = $event->type ?? '';

        if ($eventType === 'checkout.session.completed') {
            $session = $event->data->object;
            $metadata = $session->metadata ?? null;

            if ($metadata) {
                $type = $metadata->type ?? null;

                if ($type === 'verification') {
                    $submissionId = $metadata->submission_id ?? null;
                    $submission = VerificationSubmission::find($submissionId);

                    if ($submission && $submission->status === 'payment_pending') {
                        $submission->update([
                            'status' => 'pending',
                            'payment_status' => 'completed',
                            'stripe_payment_intent_id' => $session->payment_intent ?? $session->id,
                        ]);
                        Log::info("Verification submission {$submissionId} successfully paid and moved to pending review.");
                    } else {
                        Log::warning("Verification submission {$submissionId} not found or status is not payment_pending.");
                    }
                } elseif ($type === 'wallet_topup') {
                    $profileId = (int) ($metadata->profile_id ?? 0);
                    $amount = (float) ($metadata->amount ?? 0);
                    $paymentIntentId = (string) ($session->payment_intent ?? $session->id);
                    $reference = (string) ($metadata->reference ?? '');

                    $this->processWalletTopUp($profileId, $amount, $paymentIntentId, $reference);
                } elseif ($type === 'order_payment') {
                    $orderIdsString = $metadata->order_ids ?? $metadata->order_id ?? '';
                    $orderIds = array_filter(explode(',', $orderIdsString));
                    $paymentId = (string) ($session->payment_intent ?? $session->id);

                    foreach ($orderIds as $orderId) {
                        $order = Order::find($orderId);

                        if ($order && $order->status === 'pending') {
                            $this->orderManager->markAsPaid($order, $paymentId);
                            Log::info("Order {$orderId} successfully marked as paid and inventory decreased.");
                        } else {
                            Log::warning("Order {$orderId} not found or status is not pending.");
                        }
                    }
                }
            }
        } elseif (in_array($eventType, ['payment_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.canceled'])) {
            $paymentIntent = $event->data->object;
            $paymentIntentId = (string) ($paymentIntent->id ?? '');
            $metadata = $paymentIntent->metadata ?? null;
            $type = $metadata->type ?? null;

            // Handle Wallet Top-Up via PaymentIntent
            if ($type === 'wallet_topup' || WalletTransaction::where('stripe_payment_intent_id', $paymentIntentId)->exists()) {
                if ($eventType === 'payment_intent.succeeded') {
                    $profileId = (int) ($metadata->profile_id ?? 0);
                    $amount = (float) ($metadata->amount ?? (($paymentIntent->amount ?? 0) / 100));
                    $reference = (string) ($metadata->reference ?? '');

                    $this->processWalletTopUp($profileId, $amount, $paymentIntentId, $reference);
                } elseif ($eventType === 'payment_intent.payment_failed') {
                    WalletTransaction::where('stripe_payment_intent_id', $paymentIntentId)
                        ->where('status', 'pending')
                        ->update(['status' => 'failed']);
                    Log::warning("Wallet top-up failed for PaymentIntent: {$paymentIntentId}");
                } elseif ($eventType === 'payment_intent.canceled') {
                    WalletTransaction::where('stripe_payment_intent_id', $paymentIntentId)
                        ->where('status', 'pending')
                        ->update(['status' => 'failed']);
                    Log::info("Wallet top-up cancelled for PaymentIntent: {$paymentIntentId}");
                }

                return response()->json(['status' => 'success']);
            }

            // Find payment record
            $payment = Payment::where('payment_intent_id', $paymentIntentId)->first();

            $orderId = $paymentIntent->metadata->order_id ?? null;
            $orderIdsString = $paymentIntent->metadata->order_ids ?? null;
            $userId = $paymentIntent->metadata->user_id ?? null;

            // Fallback: create record if not found locally but metadata is present
            if (! $payment && $userId) {
                // If there are multiple orders, pick the first order ID for the payment record
                $firstOrderId = null;
                if ($orderIdsString) {
                    $orderIds = array_filter(explode(',', $orderIdsString));
                    $firstOrderId = reset($orderIds);
                } else {
                    $firstOrderId = $orderId;
                }

                if ($firstOrderId) {
                    $payment = Payment::create([
                        'order_id' => $firstOrderId,
                        'user_id' => $userId,
                        'payment_intent_id' => $paymentIntentId,
                        'amount' => $paymentIntent->amount / 100,
                        'currency' => strtoupper($paymentIntent->currency ?? 'USD'),
                        'payment_status' => 'pending',
                    ]);
                }
            }

            if ($payment) {
                // Get all associated order IDs
                $orderIds = [];
                if ($orderIdsString) {
                    $orderIds = array_filter(explode(',', $orderIdsString));
                } elseif ($orderId) {
                    $orderIds = [$orderId];
                } else {
                    $orderIds = [$payment->order_id];
                }

                if ($eventType === 'payment_intent.succeeded') {
                    $payment->update([
                        'payment_status' => 'succeeded',
                        'stripe_transaction_id' => $paymentIntent->latest_charge ?? null,
                        'paid_at' => now(),
                        'webhook_payload' => (array) $paymentIntent,
                    ]);

                    foreach ($orderIds as $id) {
                        $order = Order::find($id);
                        if ($order && $order->status === 'pending') {
                            $this->orderManager->markAsPaid($order, $paymentIntentId);
                            Log::info("Order {$id} successfully marked as paid and inventory decreased via PaymentIntent.");
                        }
                    }

                    Log::info('Payment for Orders ['.implode(',', $orderIds).'] succeeded via PaymentIntent.');

                } elseif ($eventType === 'payment_intent.payment_failed') {
                    $payment->update([
                        'payment_status' => 'failed',
                        'webhook_payload' => (array) $paymentIntent,
                    ]);

                    Log::warning("Payment for Order #{$payment->order_id} failed via PaymentIntent.");
                } elseif ($eventType === 'payment_intent.canceled') {
                    $payment->update([
                        'payment_status' => 'cancelled',
                        'webhook_payload' => (array) $paymentIntent,
                    ]);

                    Log::info("Payment for Order #{$payment->order_id} was cancelled via PaymentIntent.");
                }
            } else {
                Log::warning("Stripe PaymentIntent webhook received but no local Payment record was found for intent ID: {$paymentIntentId}");
            }
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Process wallet top-up idempotently.
     */
    private function processWalletTopUp(int $profileId, float $amount, string $paymentIntentId, string $reference = ''): void
    {
        // 1. Resolve transaction if existing
        $transaction = WalletTransaction::where('stripe_payment_intent_id', $paymentIntentId)->first();

        // 2. Prevent duplicate credit if already completed
        if ($transaction && $transaction->status === 'completed') {
            Log::info("Wallet top-up for intent {$paymentIntentId} is already completed.");

            return;
        }

        if ($profileId <= 0 && $transaction) {
            $profileId = (int) $transaction->user_id;
        }

        if ($profileId <= 0 || $amount <= 0) {
            Log::warning("Wallet top-up skipped: Invalid profile ({$profileId}) or amount ({$amount}) for intent {$paymentIntentId}");

            return;
        }

        $wallet = GiftWallet::firstOrCreate(
            ['user_id' => $profileId],
            [
                'balance' => 0,
                'total_deposited' => 0,
                'total_withdrawn' => 0,
                'total_gifted' => 0,
                'total_received' => 0,
            ]
        );

        $wallet->balance += $amount;
        $wallet->total_deposited += $amount;
        $wallet->save();

        if ($transaction) {
            $transaction->update([
                'status' => 'completed',
                'amount' => $amount,
                'balance_after' => $wallet->balance,
                'completed_at' => now(),
            ]);
        } else {
            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $profileId,
                'type' => 'deposit',
                'amount' => $amount,
                'balance_after' => $wallet->balance,
                'reference' => $reference ?: ('TOP-'.strtoupper(Str::random(12))),
                'description' => 'Wallet top-up via Stripe Webhook',
                'status' => 'completed',
                'payment_method' => 'stripe',
                'stripe_payment_intent_id' => $paymentIntentId,
                'completed_at' => now(),
            ]);
        }

        Log::info("Wallet top-up of {$amount} for profile {$profileId} successfully processed via Stripe webhook (Intent: {$paymentIntentId}).");
    }
}
