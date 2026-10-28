<?php

namespace App\Manager;

use App\Models\Dispute;
use App\Models\EscrowPayment;
use App\Models\Order;
use App\Models\Payout;
use App\Models\SellerBankAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * EscrowManager - Manages the escrow lifecycle.
 *
 * Flow:
 * 1. Escrow created when buyer pays (payment_received)
 * 2. Funds held until buyer confirms delivery
 * 3. Escrow released → Payout created for seller
 * 4. If dispute → Admin resolves (refund buyer or release seller)
 */
class EscrowManager
{
    // ─── Escrow Payments ─────────────────────────────────────────

    /**
     * Create an escrow payment when buyer pays for an order.
     */
    public function createFromOrder(Order $order): EscrowPayment
    {
        $existing = EscrowPayment::where('order_id', $order->id)->first();

        if ($existing) {
            throw new \Exception('Escrow payment already exists for this order', 422);
        }

        $escrow = EscrowPayment::create([
            'order_id' => $order->id,
            'amount' => (float) $order->total,
            'payment_reference' => 'ESC-'.strtoupper(Str::random(16)),
            'status' => 'pending',
            'buyer_id' => $order->buyer_id,
            'seller_id' => $order->seller_id,
            'payment_received_at' => now(),
        ]);

        // Update status to 'held' once payment is received
        $escrow->update(['status' => 'held']);

        return $escrow->load(['buyer', 'seller', 'order']);
    }

    /**
     * Release escrow funds to the seller when order is completed.
     * Called by authenticated user (buyer or admin via API).
     */
    public function release(EscrowPayment $escrow): EscrowPayment
    {
        if ($escrow->status !== 'held') {
            throw new \Exception('Escrow is not in held status. Current: '.$escrow->status, 400);
        }

        $profileId = (int) $this->profileId();

        $escrow->update([
            'status' => 'released',
            'escrow_released_at' => now(),
            'escrow_released_by' => $profileId,
        ]);

        // Auto-create payout
        $this->createPayout($escrow);

        return $escrow->fresh()->load(['buyer', 'seller', 'order', 'payouts']);
    }

    /**
     * Release escrow from a system context (no auth guard needed).
     * Used when auto-releasing after buyer confirms delivery.
     */
    public function releaseAsSystem(EscrowPayment $escrow, int $actorProfileId): EscrowPayment
    {
        if (! in_array($escrow->status, ['held', 'pending'], true)) {
            throw new \Exception('Escrow is not in held or pending status. Current: '.$escrow->status, 400);
        }

        $escrow->update([
            'status' => 'released',
            'escrow_released_at' => now(),
            'escrow_released_by' => $actorProfileId,
        ]);

        $this->createPayout($escrow);

        return $escrow->fresh()->load(['buyer', 'seller', 'order', 'payouts']);
    }

    /**
     * Get paginated escrow payments for the current user.
     */
    public function myEscrows(string $role = 'buyer', int $perPage = 20)
    {
        $profileId = (int) $this->profileId();

        $query = EscrowPayment::with(['buyer', 'seller', 'order', 'disputes'])->latestFirst();

        if ($role === 'seller') {
            $query->bySeller($profileId);
        } else {
            $query->byBuyer($profileId);
        }

        return $query->paginate($perPage);
    }

    /**
     * Get a single escrow payment detail.
     */
    public function show(EscrowPayment $escrow): EscrowPayment
    {
        $this->ensureParticipant($escrow);

        return $escrow->load(['buyer', 'seller', 'order', 'disputes', 'payouts']);
    }

    // ─── Disputes ─────────────────────────────────────────────────

    /**
     * Raise a dispute on an escrow payment.
     */
    public function createDispute(EscrowPayment $escrow, array $data): Dispute
    {
        $profileId = (int) $this->profileId();

        if ($escrow->status !== 'held') {
            throw new \Exception('Can only dispute an active escrow (held status)', 400);
        }

        $existing = Dispute::where('escrow_payment_id', $escrow->id)
            ->whereIn('status', ['pending', 'investigating'])
            ->first();

        if ($existing) {
            throw new \Exception('An active dispute already exists for this escrow', 422);
        }

        $dispute = Dispute::create([
            'order_id' => $escrow->order_id,
            'escrow_payment_id' => $escrow->id,
            'raised_by' => $profileId,
            'reason' => $data['reason'],
            'description' => $data['description'] ?? null,
            'evidence_urls' => $data['evidence_urls'] ?? null,
            'status' => 'pending',
        ]);

        // Mark escrow as disputed
        $escrow->update(['status' => 'disputed']);

        return $dispute->load(['raisedBy', 'escrowPayment']);
    }

    /**
     * Resolve a dispute (admin only).
     */
    public function resolveDispute(Dispute $dispute, array $data): Dispute
    {
        if (! in_array($dispute->status, ['pending', 'investigating'])) {
            throw new \Exception('Dispute is already resolved', 400);
        }

        $adminId = (int) $this->profileId();
        $resolution = $data['resolution'];
        $escrow = $dispute->escrowPayment;

        $dispute->update([
            'status' => 'resolved',
            'resolution' => $resolution,
            'resolution_notes' => $data['resolution_notes'] ?? null,
            'resolved_by' => $adminId,
            'resolved_at' => now(),
            'refund_amount' => $resolution === 'refund_buyer' ? ($data['refund_amount'] ?? 0) : null,
        ]);

        if ($resolution === 'refund_buyer') {
            // Refund buyer: escrow cancelled
            $escrow->update(['status' => 'cancelled']);
        } else {
            // Release to seller
            $escrow->update([
                'status' => 'released',
                'escrow_released_at' => now(),
                'escrow_released_by' => $adminId,
            ]);
            $this->createPayout($escrow);
        }

        return $dispute->fresh()->load(['raisedBy', 'resolvedBy', 'escrowPayment']);
    }

    /**
     * Get disputes for the current user.
     */
    public function myDisputes(int $perPage = 20)
    {
        $profileId = (int) $this->profileId();

        return Dispute::byUser($profileId)
            ->latestFirst()
            ->with(['raisedBy', 'escrowPayment', 'order'])
            ->paginate($perPage);
    }

    /**
     * Get all disputes (admin view).
     */
    public function allDisputes(array $filters = [], int $perPage = 20)
    {
        $query = Dispute::with(['raisedBy', 'escrowPayment', 'order'])
            ->latestFirst();

        if (! empty($filters['status'])) {
            $query->byStatus($filters['status']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Get a single dispute detail.
     */
    public function showDispute(Dispute $dispute): Dispute
    {
        return $dispute->load(['raisedBy', 'resolvedBy', 'escrowPayment', 'order']);
    }

    // ─── Payouts ──────────────────────────────────────────────────

    /**
     * Create a payout for the seller when escrow is released.
     */
    protected function createPayout(EscrowPayment $escrow): Payout
    {
        $sellerId = (int) $escrow->seller_id;

        // Find seller's primary bank account
        $bankAccount = $sellerId
            ? SellerBankAccount::bySeller($sellerId)->primary()->verified()->first()
            : null;

        $payout = Payout::create([
            'escrow_payment_id' => $escrow->id,
            'seller_id' => $sellerId,
            'seller_bank_account_id' => $bankAccount?->id,
            'amount' => (float) $escrow->amount,
            'status' => 'pending',
        ]);

        $escrow->update([
            'payout_sent_at' => now(),
            'payout_reference' => 'PO-'.strtoupper(Str::random(12)),
        ]);

        return $payout;
    }

    /**
     * Get payouts for the current seller.
     */
    public function myPayouts(int $perPage = 20)
    {
        $profileId = (int) $this->profileId();

        return Payout::bySeller($profileId)
            ->latestFirst()
            ->with(['escrowPayment', 'bankAccount'])
            ->paginate($perPage);
    }

    // ─── Helpers ─────────────────────────────────────────────────

    protected function ensureParticipant(EscrowPayment $escrow): void
    {
        $profileId = (int) $this->profileId();

        if (
            (int) $escrow->buyer_id !== $profileId &&
            (int) $escrow->seller_id !== $profileId
        ) {
            abort(404);
        }
    }

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
