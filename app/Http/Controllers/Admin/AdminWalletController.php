<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminWalletController extends Controller
{
    public function index(Request $request)
    {
        // Settled orders that count toward confirmed revenue/commission
        $settledStatuses = ['processing', 'shipped', 'delivered', 'completed'];

        // ── 1. High-level KPI Stats ──────────────────────────────────────────
        $stats = [
            'total_commission' => (float) Order::whereIn('status', $settledStatuses)->sum('seller_fee'),
            'today_commission' => (float) Order::whereIn('status', $settledStatuses)
                ->whereDate('created_at', today())
                ->sum('seller_fee'),
            'month_commission' => (float) Order::whereIn('status', $settledStatuses)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('seller_fee'),
            'total_volume' => (float) Order::whereIn('status', $settledStatuses)->sum('subtotal'),
            'seller_payouts' => (float) Order::whereIn('status', $settledStatuses)
                ->selectRaw('SUM(subtotal - COALESCE(seller_fee, 0)) as net_total')
                ->value('net_total') ?? 0.0,
            'pending_commission' => (float) Order::where('status', 'pending')->sum('seller_fee'),
            'total_orders_count' => Order::whereIn('status', $settledStatuses)->count(),
        ];

        // ── 2. Filtered Commission Ledger (Order breakdown) ─────────────────
        $query = Order::with(['seller.user', 'buyer.user']);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                    ->orWhere('tracking_number', 'like', "%{$search}%")
                    ->orWhere('purchase_reference', 'like', "%{$search}%")
                    ->orWhereHas('seller', fn ($s) => $s->where('full_name', 'like', "%{$search}%")->orWhere('username', 'like', "%{$search}%"))
                    ->orWhereHas('buyer', fn ($b) => $b->where('full_name', 'like', "%{$search}%")->orWhere('username', 'like', "%{$search}%"));
            });
        }

        if ($startDate = $request->input('start_date')) {
            $query->whereDate('created_at', '>=', $startDate);
        }

        if ($endDate = $request->input('end_date')) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $orders = $query->latestFirst()->paginate(20)->withQueryString();

        // ── 3. Recent Seller Earnings Credits ──────────────────────────────
        $recentCredits = WalletTransaction::where('type', 'order_earning')
            ->with(['user'])
            ->latest()
            ->limit(10)
            ->get();

        $statuses = ['all', 'processing', 'completed', 'shipped', 'delivered', 'pending', 'cancelled', 'refunded'];

        return view('modules.admin.wallet.index', compact('stats', 'orders', 'recentCredits', 'statuses'));
    }
}
