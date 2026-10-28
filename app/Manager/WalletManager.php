<?php

namespace App\Manager;

use App\Models\GiftWallet;
use App\Models\Order;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Stripe\StripeClient;

/**
 * WalletManager - Manages virtual wallet operations.
 *
 * Handles: getting/creating wallet, top-ups, sending gifts,
 * transaction history, and balance checks.
 */
class WalletManager
{
    // ─── Wallet ──────────────────────────────────────────────────

    /**
     * Get or create a wallet for the authenticated user.
     */
    public function getOrCreate(): GiftWallet
    {
        $profileId = (int) $this->profileId();

        return GiftWallet::firstOrCreate(
            ['user_id' => $profileId],
            [
                'balance' => 0,
                'total_deposited' => 0,
                'total_withdrawn' => 0,
                'total_gifted' => 0,
                'total_received' => 0,
            ]
        );
    }

    /**
     * Get wallet with loaded transactions.
     */
    public function show(): GiftWallet
    {
        return $this->getOrCreate()->load('user');
    }

    /**
     * Get paginated transaction history.
     */
    public function transactions(int $perPage = 20)
    {
        $profileId = (int) $this->profileId();
        $wallet = $this->getOrCreate();

        return WalletTransaction::byUser($profileId)
            ->latestFirst()
            ->with('recipient')
            ->paginate($perPage);
    }

    // ─── Top-up ──────────────────────────────────────────────────

    /**
     * Initialize top up with Stripe PaymentIntent (for mobile SDK/PaymentSheet)
     * and Stripe Checkout Session (for direct payment URL / webview).
     *
     * @return array<string, mixed>
     */
    public function initiateTopUp(array $data): array
    {
        $profileId = (int) $this->profileId();
        $user = Auth::guard('jwt')->user();
        $wallet = $this->getOrCreate();

        $amount = (float) $data['amount'];
        $amountInCents = (int) round($amount * 100);
        $reference = 'TOP-'.strtoupper(Str::random(12));

        $stripeSecret = config('services.stripe.secret') ?: env('STRIPE_SECRET', 'sk_test_placeholder');
        $stripe = new StripeClient($stripeSecret);

        // 1. Create Stripe PaymentIntent (for Mobile SDK / Flutter PaymentSheet)
        $paymentIntent = $stripe->paymentIntents->create([
            'amount' => $amountInCents,
            'currency' => 'usd',
            'metadata' => [
                'type' => 'wallet_topup',
                'profile_id' => (string) $profileId,
                'user_id' => (string) ($user?->id ?? ''),
                'amount' => (string) $amount,
                'reference' => $reference,
            ],
            'description' => "Wallet top-up of \${$amount} for Profile #{$profileId}",
        ]);

        // 2. Create Stripe Checkout Session (for direct payment link / webview)
        $frontendUrl = env('FRONTEND_URL', config('app.url', 'https://castilo.co.uk'));
        $successUrl = rtrim($frontendUrl, '/').'/wallet/top-up/success?session_id={CHECKOUT_SESSION_ID}&reference='.$reference;
        $cancelUrl = rtrim($frontendUrl, '/').'/wallet/top-up/cancel';

        $session = $stripe->checkout->sessions->create([
            'payment_method_types' => ['card'],
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => 'usd',
                        'unit_amount' => $amountInCents,
                        'product_data' => [
                            'name' => 'Wallet Balance Top-Up',
                            'description' => "Top-up \${$amount} to your wallet",
                        ],
                    ],
                    'quantity' => 1,
                ],
            ],
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer_email' => $user?->email,
            'metadata' => [
                'type' => 'wallet_topup',
                'profile_id' => (string) $profileId,
                'user_id' => (string) ($user?->id ?? ''),
                'amount' => (string) $amount,
                'reference' => $reference,
            ],
        ]);

        // 3. Create pending WalletTransaction
        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $profileId,
            'type' => 'deposit',
            'amount' => $amount,
            'balance_after' => $wallet->balance,
            'reference' => $reference,
            'description' => 'Wallet top-up initialized via Stripe',
            'status' => 'pending',
            'payment_method' => 'stripe',
            'stripe_payment_intent_id' => $paymentIntent->id,
            'completed_at' => null,
        ]);

        return [
            'client_secret' => $paymentIntent->client_secret,
            'payment_intent_id' => $paymentIntent->id,
            'checkout_url' => $session->url,
            // 'direct_payment_url' => $session->url,
            'amount' => (float) $amount,
            'currency' => 'usd',
            'reference' => $reference,
            // 'publishable_key'    => config('services.stripe.key') ?: env('STRIPE_KEY', ''),
            'status' => 'pending',
        ];
    }

    /**
     * Confirm a top-up after payment succeeds on client side.
     *
     * @return array<string, mixed>
     */
    public function confirmTopUp(string $paymentIntentId): array
    {
        $profileId = (int) $this->profileId();
        $wallet = $this->getOrCreate();

        $stripeSecret = config('services.stripe.secret') ?: env('STRIPE_SECRET', 'sk_test_placeholder');
        $stripe = new StripeClient($stripeSecret);

        $intent = $stripe->paymentIntents->retrieve($paymentIntentId);

        if ($intent->status !== 'succeeded') {
            throw new \Exception("Payment has not succeeded yet. Current status: {$intent->status}", 400);
        }

        $amount = (float) ($intent->amount / 100);

        // Find or update transaction
        $transaction = WalletTransaction::where('stripe_payment_intent_id', $paymentIntentId)->first();

        if (! $transaction || $transaction->status !== 'completed') {
            $wallet->balance += $amount;
            $wallet->total_deposited += $amount;
            $wallet->save();

            if ($transaction) {
                $transaction->update([
                    'status' => 'completed',
                    'balance_after' => $wallet->balance,
                    'completed_at' => now(),
                ]);
            } else {
                $transaction = WalletTransaction::create([
                    'wallet_id' => $wallet->id,
                    'user_id' => $profileId,
                    'type' => 'deposit',
                    'amount' => $amount,
                    'balance_after' => $wallet->balance,
                    'reference' => 'TOP-'.strtoupper(Str::random(12)),
                    'description' => 'Wallet top-up via Stripe',
                    'status' => 'completed',
                    'payment_method' => 'stripe',
                    'stripe_payment_intent_id' => $paymentIntentId,
                    'completed_at' => now(),
                ]);
            }
        }

        return [
            'status' => 'completed',
            'amount' => (float) $amount,
            'balance' => (float) $wallet->balance,
            'transaction' => $transaction,
        ];
    }

    /**
     * Top up wallet with funds directly (legacy / webhook fallback).
     */
    public function topUp(array $data): array
    {
        return $this->initiateTopUp($data);
    }

    // ─── Send Gift ───────────────────────────────────────────────

    /**
     * Send a gift to another user's wallet.
     */
    public function sendGift(array $data): array
    {
        $senderId = (int) $this->profileId();
        $recipientId = (int) $data['recipient_id'];
        $amount = (float) $data['amount'];

        if ($senderId === $recipientId) {
            throw new \Exception('Cannot send a gift to yourself', 422);
        }

        $senderWallet = $this->getOrCreate();

        if ($senderWallet->balance < $amount) {
            throw new \Exception('Insufficient wallet balance. Please top up first.', 400);
        }

        // Withdraw from sender
        $senderWallet->balance -= $amount;
        $senderWallet->total_gifted += $amount;
        $senderWallet->save();

        // Credit to recipient
        $recipientWallet = GiftWallet::firstOrCreate(
            ['user_id' => $recipientId],
            [
                'balance' => 0,
                'total_deposited' => 0,
                'total_withdrawn' => 0,
                'total_gifted' => 0,
                'total_received' => 0,
            ]
        );

        $recipientWallet->balance += $amount;
        $recipientWallet->total_received += $amount;
        $recipientWallet->save();

        $reference = 'GIFT-'.strtoupper(Str::random(12));

        // Sender transaction (debit)
        $sentTxn = WalletTransaction::create([
            'wallet_id' => $senderWallet->id,
            'user_id' => $senderId,
            'type' => 'gift_sent',
            'amount' => -$amount,
            'balance_after' => $senderWallet->balance,
            'reference' => $reference,
            'description' => $data['message'] ?? 'Gift sent',
            'status' => 'completed',
            'recipient_id' => $recipientId,
            'livestream_id' => $data['livestream_id'] ?? null,
            'completed_at' => now(),
        ]);

        // Recipient transaction (credit)
        $recvTxn = WalletTransaction::create([
            'wallet_id' => $recipientWallet->id,
            'user_id' => $recipientId,
            'type' => 'gift_received',
            'amount' => $amount,
            'balance_after' => $recipientWallet->balance,
            'reference' => $reference,
            'description' => $data['message'] ?? 'Gift received',
            'status' => 'completed',
            'livestream_id' => $data['livestream_id'] ?? null,
            'completed_at' => now(),
        ]);

        return [
            'sender_transaction' => $sentTxn->load('recipient'),
            'recipient_transaction' => $recvTxn,
            'sender_balance' => (float) $senderWallet->balance,
        ];
    }

    // ─── Balance ─────────────────────────────────────────────────

    /**
     * Get current wallet balance.
     */
    public function balance(): array
    {
        $wallet = $this->getOrCreate();

        return [
            'balance' => (float) $wallet->balance,
            'total_deposited' => (float) $wallet->total_deposited,
            'total_withdrawn' => (float) $wallet->total_withdrawn,
            'total_gifted' => (float) $wallet->total_gifted,
            'total_received' => (float) $wallet->total_received,
        ];
    }

    // ─── Seller Order Earnings ───────────────────────────────────

    /**
     * Credit seller's wallet with net earnings from a paid order.
     * Deducts exactly 2.5% platform fee from the subtotal.
     * Idempotently tracked via reference ORDER-EARN-{order_id}.
     */
    public function creditSellerOrderEarnings(Order $order): ?WalletTransaction
    {
        $sellerId = (int) $order->seller_id;
        if (! $sellerId) {
            return null;
        }

        $reference = 'ORDER-EARN-'.$order->id;

        // Idempotency check: do not credit twice for the same order
        $existing = WalletTransaction::where('reference', $reference)
            ->where('status', 'completed')
            ->first();

        if ($existing) {
            return $existing;
        }

        $subtotal = (float) $order->subtotal;
        $platformFee = round($subtotal * 0.025, 2);
        $netEarnings = round($subtotal - $platformFee, 2);

        // Find or create seller's gift wallet
        $wallet = GiftWallet::firstOrCreate(
            ['user_id' => $sellerId],
            [
                'balance' => 0,
                'total_deposited' => 0,
                'total_withdrawn' => 0,
                'total_gifted' => 0,
                'total_received' => 0,
            ]
        );

        $wallet->balance += $netEarnings;
        $wallet->total_deposited += $netEarnings;
        $wallet->save();

        $transaction = WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $sellerId,
            'type' => 'order_earning',
            'amount' => $netEarnings,
            'balance_after' => $wallet->balance,
            'reference' => $reference,
            'description' => "Order #{$order->id} earnings (2.5% platform fee deducted: \${$platformFee})",
            'status' => 'completed',
            'payment_method' => 'wallet',
            'completed_at' => now(),
        ]);

        $order->seller_fee = $platformFee;
        $order->save();

        return $transaction;
    }

    // ─── Helpers ─────────────────────────────────────────────────

    protected function profileId(): int
    {
        $user = Auth::guard('jwt')->user();
        $profile = $user->profile;

        if (! $profile) {
            throw new \Exception('Profile not found. Complete your profile first.', 400);
        }

        return (int) $profile->id;
    }
}
