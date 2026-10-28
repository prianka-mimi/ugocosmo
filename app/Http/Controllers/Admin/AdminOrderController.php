<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Services\OrderTimelineService;
use App\Services\StripeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminOrderController extends Controller
{
    protected StripeService $stripeService;

    public function __construct(StripeService $stripeService)
    {
        $this->stripeService = $stripeService;
    }

    public function index()
    {
        $query = Order::with(['buyer', 'seller', 'items']);

        if ($status = request('status')) {
            $query->where('status', $status);
        }
        if ($search = request('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('buyer', fn ($b) => $b->where('full_name', 'like', "%{$search}%"))
                    ->orWhereHas('seller', fn ($s) => $s->where('full_name', 'like', "%{$search}%"))
                    ->orWhere('id', 'like', "%{$search}%");
            });
        }

        $orders = $query->latestFirst()->paginate(20);
        $statuses = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'completed', 'cancelled', 'refunded', 'disputed'];

        return view('modules.admin.orders.index', compact('orders', 'statuses'));
    }

    public function show(Order $order)
    {
        $order->load(['buyer', 'seller', 'items', 'transaction', 'disputes']);
        $paymentDetails = $this->stripeService->getPaymentDetails($order->stripe_payment_intent_id);

        return view('modules.admin.orders.show', compact('order', 'paymentDetails'));
    }

    public function updateStatus(Order $order)
    {
        $status = request()->input('status');
        $allowedStatuses = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'completed', 'cancelled', 'refunded'];

        if (! in_array($status, $allowedStatuses)) {
            return back()->with('error', 'Invalid status: '.$status);
        }

        try {
            DB::beginTransaction();

            $oldStatus = $order->status;
            $order->update(['status' => $status]);

            app(OrderTimelineService::class)->record(
                $order,
                $status,
                auth()->user()?->profile?->id,
                OrderTimelineService::ACTOR_ADMIN,
                'Status updated by admin',
                ['from' => $oldStatus, 'by' => 'admin']
            );

            AdminAuditLog::create([
                'admin_id' => auth()->user()?->profile?->id,
                'action' => 'order_status_updated',
                'entity_type' => 'order',
                'entity_id' => $order->id,
                'old_value' => ['status' => $oldStatus],
                'new_value' => ['status' => $status],
                'ip_address' => request()->ip(),
            ]);

            DB::commit();

            return back()->with('success', "Order #{$order->id} status updated to: ".ucfirst($status));
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Order status update failed: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }

    public function refund(Order $order)
    {
        $amount = request()->input('amount');
        $reason = request()->input('reason', '');

        if (! in_array($order->status, ['pending', 'confirmed', 'processing', 'disputed'])) {
            return back()->with('error', 'Order cannot be refunded in its current state: '.$order->status);
        }

        try {
            DB::beginTransaction();

            $oldStatus = $order->status;
            $order->update(['status' => 'refunded']);

            app(OrderTimelineService::class)->record(
                $order,
                'refunded',
                auth()->user()?->profile?->id,
                OrderTimelineService::ACTOR_ADMIN,
                "Refunded (amount: {$amount}) — {$reason}",
                ['from' => $oldStatus, 'refund_amount' => $amount, 'reason' => $reason]
            );

            AdminAuditLog::create([
                'admin_id' => auth()->user()?->profile?->id,
                'action' => 'order_refunded',
                'entity_type' => 'order',
                'entity_id' => $order->id,
                'old_value' => ['status' => $oldStatus],
                'new_value' => ['status' => 'refunded', 'refund_amount' => $amount, 'reason' => $reason],
                'ip_address' => request()->ip(),
            ]);

            DB::commit();

            return back()->with('success', "Order #{$order->id} has been refunded.");
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Order refund failed: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }
}
