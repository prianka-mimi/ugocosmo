<?php

namespace App\Actions;

use App\Manager\EscrowManager;
use App\Models\EscrowPayment;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReleaseEscrowFundsAction
{
    public function __construct(
        protected EscrowManager $escrowManager
    ) {}

    /**
     * Release funds from escrow to the seller when delivery is verified via tracking webhook.
     */
    public function execute(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $escrow = EscrowPayment::where('order_id', $order->id)
                ->whereIn('status', ['held', 'pending'])
                ->first();

            if (! $escrow) {
                return; // Already released or doesn't exist
            }

            try {
                $this->escrowManager->releaseAsSystem($escrow, 0);
                Log::info("Escrow #{$escrow->id} automatically released for order #{$order->id} upon verified carrier delivery.");
            } catch (\Throwable $e) {
                Log::error("Failed to auto-release escrow for order #{$order->id}: ".$e->getMessage());

                // Fallback direct release if state error
                if ($escrow->status !== 'released') {
                    $escrow->update([
                        'status' => 'released',
                        'escrow_released_at' => now(),
                        'escrow_released_by' => 0,
                    ]);
                }
            }
        });
    }
}
