<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Event;
use App\Models\JobListing;
use App\Models\Post;
use App\Models\Product;
use App\Models\Profile;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserSubscription;

class AdminDashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'users' => [
                'total' => User::count(),
                'active' => User::where('status', 'active')->count(),
                'suspended' => User::where('status', 'suspended')->count(),
                'unverified' => User::where('is_verified', false)->count(),
                'new_today' => User::whereDate('created_at', today())->count(),
            ],
            'content' => [
                'posts' => Post::count(),
                'products' => Product::count(),
                'services' => Service::count(),
                'jobs' => JobListing::count(),
                'events' => Event::count(),
                'bookings' => Booking::count(),
            ],
            'revenue' => [
                'total' => (float) Transaction::where('status', 'completed')->sum('amount'),
                'today' => (float) Transaction::where('status', 'completed')
                    ->whereDate('created_at', today())->sum('amount'),
                'month' => (float) Transaction::where('status', 'completed')
                    ->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)->sum('amount'),
            ],
            'subscriptions' => [
                'total' => UserSubscription::count(),
                'active' => UserSubscription::where('is_active', true)->count(),
            ],
            'sellers' => Profile::where('is_seller', true)->count(),
        ];

        $revenueByMonth = Transaction::where('status', 'completed')
            ->selectRaw('YEAR(created_at) as year, MONTH(created_at) as month, SUM(amount) as total')
            ->groupBy('year', 'month')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->limit(6)
            ->get();

        return view('modules.admin.dashboard.index', compact('stats', 'revenueByMonth'));
    }
}
