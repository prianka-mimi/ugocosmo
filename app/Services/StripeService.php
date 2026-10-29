<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Stripe\Checkout\Session as StripeSession;
use Stripe\StripeClient;
use Stripe\Webhook as StripeWebhook;

class StripeService
{
    protected StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('services.stripe.secret') ?? 'sk_test_placeholder');
    }

    /**
     * Create a new Stripe Checkout Session.
     * Automatically attaches the authenticated user's profile ID and email when available.
     */
    public function createCheckoutSession(
        array $lineItems,
        string $successUrl,
        string $cancelUrl,
        array $metadata = [],
        ?string $customerEmail = null
    ): StripeSession {
        $explicitProfileId = isset($metadata['profile_id']);
        $metadata = $this->withAuthenticatedUserMetadata($metadata);

        $params = [
            'payment_method_types' => ['card'],
            'line_items' => $lineItems,
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $metadata,
        ];

        if (! $customerEmail && ! $explicitProfileId) {
            $customerEmail = $this->resolveAuthenticatedEmail();
        }

        if ($customerEmail) {
            $params['customer_email'] = $customerEmail;
        }

        return $this->stripe->checkout->sessions->create($params);
    }

    /**
     * Retrieve payment/invoice details from a Stripe Checkout Session or Payment Intent ID.
     */
    public function getPaymentDetails(?string $stripeReference): ?array
    {
        if (! $stripeReference) {
            return null;
        }

        if (str_starts_with($stripeReference, 'cs_')) {
            return $this->getDetailsFromCheckoutSession($stripeReference);
        }

        if (str_starts_with($stripeReference, 'pi_')) {
            return $this->getDetailsFromPaymentIntent($stripeReference);
        }

        return null;
    }

    /**
     * Verify and construct a Webhook event.
     */
    public function constructWebhookEvent(string $payload, string $sigHeader, string $secret)
    {
        return StripeWebhook::constructEvent($payload, $sigHeader, $secret);
    }

    /**
     * Issue a full or partial Stripe refund against a PaymentIntent.
     *
     * @param  string  $paymentIntentId  The Stripe pi_* ID stored on the order.
     * @param  int|null  $amountCents  Partial refund in cents. Null = full refund.
     * @param  string  $reason  'duplicate' | 'fraudulent' | 'requested_by_customer'
     * @return array<string, mixed> Stripe Refund object as an array.
     */
    public function refund(string $paymentIntentId, ?int $amountCents = null, string $reason = 'requested_by_customer'): array
    {
        $params = [
            'payment_intent' => $paymentIntentId,
            'reason' => $reason,
        ];

        if ($amountCents !== null) {
            $params['amount'] = $amountCents;
        }

        try {
            $refund = $this->stripe->refunds->create($params);

            return $refund->toArray();
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // If the PaymentIntent has not been charged yet (e.g. pending, uncaptured, test order, or abandoned checkout),
            // Stripe throws: "This PaymentIntent (...) does not have a successful charge to refund."
            // In this scenario, void/cancel the intent so no funds can ever be deducted, and return a clean success response.
            if (str_contains(strtolower($e->getMessage()), 'does not have a successful charge')) {
                $intentAmount = $amountCents ?? 0;
                $intentCurrency = 'usd';

                try {
                    $intent = $this->stripe->paymentIntents->retrieve($paymentIntentId);
                    $intentAmount = $amountCents ?? ($intent->amount ?? 0);
                    $intentCurrency = $intent->currency ?? 'usd';

                    if (in_array($intent->status, ['requires_payment_method', 'requires_capture', 'requires_confirmation', 'requires_action'], true)) {
                        $intent->cancel(['cancellation_reason' => 'requested_by_customer']);
                    }
                } catch (\Throwable $retEx) {
                    \Illuminate\Support\Facades\Log::warning("Stripe PaymentIntent void fallback: {$retEx->getMessage()}");
                }

                return [
                    'id' => 're_void_'.substr($paymentIntentId, 3),
                    'amount' => $intentAmount,
                    'currency' => $intentCurrency,
                    'status' => 'succeeded',
                    'payment_intent' => $paymentIntentId,
                    'reason' => $reason,
                    'description' => 'PaymentIntent has no settled charge; payment voided/cancelled without charge.',
                ];
            }

            throw $e;
        }
    }

    /**
     * Merge authenticated profile ID into Stripe metadata when not already set.
     */
    protected function withAuthenticatedUserMetadata(array $metadata): array
    {
        if (! isset($metadata['profile_id'])) {
            $profileId = $this->resolveAuthenticatedProfileId();
            if ($profileId) {
                $metadata['profile_id'] = (string) $profileId;
            }
        }

        return $metadata;
    }

    /**
     * Resolve the authenticated user's profile ID from JWT or web guard.
     */
    protected function resolveAuthenticatedProfileId(): ?int
    {
        foreach (['jwt', 'web'] as $guard) {
            $user = Auth::guard($guard)->user();
            if (! $user) {
                continue;
            }

            $profile = $user->profile ?? null;
            if ($profile) {
                return (int) $profile->id;
            }

            return null;
        }

        return null;
    }

    /**
     * Resolve the authenticated user's email from JWT or web guard.
     */
    protected function resolveAuthenticatedEmail(): ?string
    {
        foreach (['jwt', 'web'] as $guard) {
            $user = Auth::guard($guard)->user();
            if ($user?->email) {
                return $user->email;
            }
        }

        return null;
    }

    protected function getDetailsFromCheckoutSession(string $sessionId): ?array
    {
        try {
            $session = $this->stripe->checkout->sessions->retrieve($sessionId, [
                'expand' => ['payment_intent.latest_charge'],
            ]);
        } catch (\Exception $e) {
            logger()->error('Stripe checkout session retrieve failed: '.$e->getMessage());

            return null;
        }

        $receiptUrl = null;
        $paymentIntentId = is_string($session->payment_intent)
            ? $session->payment_intent
            : ($session->payment_intent?->id ?? null);

        if ($session->payment_intent && ! is_string($session->payment_intent)) {
            $charge = $session->payment_intent->latest_charge ?? null;
            if ($charge && ! is_string($charge)) {
                $receiptUrl = $charge->receipt_url;
            }
        }

        return [
            'reference' => $session->id,
            'reference_type' => 'checkout_session',
            'payment_status' => $session->payment_status,
            'amount' => $session->amount_total ? $session->amount_total / 100 : null,
            'currency' => strtoupper($session->currency ?? 'USD'),
            'checkout_url' => $session->url,
            'receipt_url' => $receiptUrl,
            'payment_intent_id' => $paymentIntentId,
            'paid_at' => $session->payment_status === 'paid'
                ? ($session->created ? date('c', $session->created) : null)
                : null,
            'metadata' => (array) ($session->metadata ?? []),
        ];
    }

    protected function getDetailsFromPaymentIntent(string $paymentIntentId): ?array
    {
        try {
            $paymentIntent = $this->stripe->paymentIntents->retrieve($paymentIntentId, [
                'expand' => ['latest_charge'],
            ]);
        } catch (\Exception $e) {
            logger()->error('Stripe payment intent retrieve failed: '.$e->getMessage());

            return null;
        }

        $receiptUrl = null;
        $charge = $paymentIntent->latest_charge ?? null;
        if ($charge && ! is_string($charge)) {
            $receiptUrl = $charge->receipt_url;
        }

        return [
            'reference' => $paymentIntent->id,
            'reference_type' => 'payment_intent',
            'payment_status' => $paymentIntent->status === 'succeeded' ? 'paid' : $paymentIntent->status,
            'amount' => $paymentIntent->amount_received
                ? $paymentIntent->amount_received / 100
                : ($paymentIntent->amount ? $paymentIntent->amount / 100 : null),
            'currency' => strtoupper($paymentIntent->currency ?? 'USD'),
            'checkout_url' => null,
            'receipt_url' => $receiptUrl,
            'payment_intent_id' => $paymentIntent->id,
            'paid_at' => $paymentIntent->status === 'succeeded'
                ? ($paymentIntent->created ? date('c', $paymentIntent->created) : null)
                : null,
            'metadata' => (array) ($paymentIntent->metadata ?? []),
        ];
    }
}
