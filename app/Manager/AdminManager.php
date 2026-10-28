<?php

namespace App\Manager;

use App\Models\AdminAuditLog;
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
use App\Models\VerificationSubmission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * AdminManager - Core business logic for the admin panel.
 *
 * Handles:
 * - Dashboard analytics (counts, revenue, growth)
 * - User management (list, suspend, activate, verify, delete)
 * - Role management (assign, update, list roles)
 * - Content moderation (posts, products, events)
 * - Audit logging (record all admin actions)
 */
class AdminManager
{
    // ─── Dashboard Analytics ──────────────────────────────────────

    /**
     * Get overall platform dashboard stats.
     */
    public function dashboard(): array
    {
        return [
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
            'revenue' => $this->revenueStats(),
            'subscriptions' => [
                'total' => UserSubscription::count(),
                'active' => UserSubscription::where('is_active', true)->count(),
            ],
            'profiles_with_seller' => Profile::where('is_seller', true)->count(),
        ];
    }

    /**
     * Get detailed revenue analytics.
     */
    public function revenueStats(): array
    {
        $totalRevenue = Transaction::where('status', 'completed')->sum('amount');
        $todayRevenue = Transaction::where('status', 'completed')
            ->whereDate('created_at', today())->sum('amount');
        $monthRevenue = Transaction::where('status', 'completed')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)->sum('amount');

        $pendingPayouts = Transaction::where('status', 'pending')->sum('amount');

        $revenueByMonth = Transaction::where('status', 'completed')
            ->selectRaw('YEAR(created_at) as year, MONTH(created_at) as month, SUM(amount) as total')
            ->groupBy('year', 'month')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->limit(12)
            ->get();

        $topSellers = Profile::select('profiles.id', 'profiles.full_name', 'profiles.username')
            ->selectRaw('COUNT(orders.id) as total_sales')
            ->selectRaw('COALESCE(SUM(orders.total), 0) as revenue')
            ->join('orders', 'profiles.id', '=', 'orders.seller_id')
            ->whereIn('orders.status', ['delivered', 'completed'])
            ->groupBy('profiles.id', 'profiles.full_name', 'profiles.username')
            ->orderBy('revenue', 'desc')
            ->limit(10)
            ->get();

        return [
            'total_revenue' => (float) $totalRevenue,
            'today_revenue' => (float) $todayRevenue,
            'month_revenue' => (float) $monthRevenue,
            'pending_payouts' => (float) $pendingPayouts,
            'revenue_by_month' => $revenueByMonth,
            'top_sellers' => $topSellers,
        ];
    }

    // ─── User Management ─────────────────────────────────────────

    /**
     * Get paginated list of all users with filters.
     */
    public function users(array $filters = [], int $perPage = 20)
    {
        $query = User::with('profile');

        if (! empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['is_verified'])) {
            $query->where('is_verified', $filters['is_verified'] === 'true' || $filters['is_verified'] === '1');
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Get a single user's full details.
     */
    public function userDetail(User $user): User
    {
        return $user->load([
            'profile',
            'subscriptions' => function ($q) {
                $q->latest()->limit(5);
            },
        ]);
    }

    /**
     * Suspend a user account.
     */
    public function suspendUser(User $user, ?string $reason = null): User
    {
        if ($user->role === 'admin') {
            throw new \Exception('Cannot suspend an admin user', 403);
        }

        $oldStatus = $user->status;

        $user->update([
            'status' => 'suspended',
            'is_approved' => false,
        ]);

        $this->logAudit('user_suspended', 'user', $user->id, [
            'old_status' => $oldStatus,
            'new_status' => 'suspended',
            'reason' => $reason,
        ]);

        return $user->fresh();
    }

    /**
     * Activate a suspended user account.
     */
    public function activateUser(User $user): User
    {
        $oldStatus = $user->status;

        $user->update([
            'status' => 'active',
            'is_approved' => true,
        ]);

        $this->logAudit('user_activated', 'user', $user->id, [
            'old_status' => $oldStatus,
            'new_status' => 'active',
        ]);

        return $user->fresh();
    }

    /**
     * Verify a user's account (mark email as verified).
     */
    public function verifyUser(User $user): User
    {
        $user->update([
            'is_verified' => true,
            'verified_at' => now(),
        ]);

        $this->logAudit('user_verified', 'user', $user->id, [
            'is_verified' => true,
        ]);

        return $user->fresh();
    }

    /**
     * Permanently delete a user account.
     */
    public function deleteUser(User $user): void
    {
        if ($user->role === 'admin') {
            throw new \Exception('Cannot delete an admin user', 403);
        }

        $this->logAudit('user_deleted', 'user', $user->id, [
            'email' => $user->email,
            'name' => $user->name,
        ]);

        $user->delete();
    }

    // ─── Role Management ──────────────────────────────────────────

    /**
     * Get all available roles and user counts.
     */
    public function roles(): array
    {
        $roles = User::select('role')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('role')
            ->orderBy('count', 'desc')
            ->get();

        return [
            'available_roles' => ['user', 'supervisor', 'admin'],
            'role_distribution' => $roles,
        ];
    }

    /**
     * Assign or change a user's role.
     *
     * Delegates to RolePermissionManager to ensure consistent
     * handling of both the legacy `role` column and Spatie roles.
     */
    public function assignRole(User $user, string $role): User
    {
        if (! in_array($role, ['user', 'supervisor', 'admin'])) {
            throw new \Exception('Invalid role. Allowed: user, supervisor, admin', 422);
        }

        // Only admins can assign 'admin' role
        $currentUserRole = Auth::user()->role;
        if ($role === 'admin' && $currentUserRole !== 'admin') {
            throw new \Exception('Only admins can assign the admin role', 403);
        }

        $adminProfileId = Auth::user()?->profile?->id;

        return app(RolePermissionManager::class)->assignRole($user, $role, $adminProfileId);
    }

    // ─── Content Moderation ───────────────────────────────────────

    /**
     * Get all reported/flagged posts with report details.
     */
    public function reportedPosts(int $perPage = 20)
    {
        return Post::where(function ($q) {
            $q->where('is_active', false)
                ->orWhere('is_reported', true);
        })
            ->with(['author', 'reports.reporter'])
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get all posts for moderation (with active filter).
     */
    public function allPosts(array $filters = [], int $perPage = 20)
    {
        $query = Post::with('author');

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active'] === 'true' || $filters['is_active'] === '1');
        }

        if (! empty($filters['search'])) {
            $query->where('content', 'like', "%{$filters['search']}%");
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Deactivate a post (remove from public view).
     */
    public function deactivatePost(Post $post, ?string $reason = null): Post
    {
        $oldActive = $post->is_active;

        $post->update([
            'is_active' => false,
            'is_reported' => true,
        ]);

        $this->logAudit('post_deactivated', 'post', $post->id, [
            'was_active' => $oldActive,
            'reason' => $reason,
        ]);

        return $post->fresh();
    }

    /**
     * Reactivate a previously deactivated post.
     */
    public function activatePost(Post $post): Post
    {
        $post->update([
            'is_active' => true,
            'is_reported' => false,
        ]);

        $this->logAudit('post_activated', 'post', $post->id, []);

        return $post->fresh();
    }

    /**
     * Get all products for moderation.
     */
    public function allProducts(array $filters = [], int $perPage = 20)
    {
        $query = Product::with('seller');

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active'] === 'true' || $filters['is_active'] === '1');
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Deactivate a product listing.
     */
    public function deactivateProduct(Product $product): Product
    {
        $product->update(['is_active' => false]);

        $this->logAudit('product_deactivated', 'product', $product->id, []);

        return $product->fresh();
    }

    /**
     * Reactivate a product listing.
     */
    public function activateProduct(Product $product): Product
    {
        $product->update(['is_active' => true]);

        $this->logAudit('product_activated', 'product', $product->id, []);

        return $product->fresh();
    }

    /**
     * Get all events for moderation.
     */
    public function allEvents(array $filters = [], int $perPage = 20)
    {
        $query = Event::with('organizer');

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active'] === 'true' || $filters['is_active'] === '1');
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Deactivate an event.
     */
    public function deactivateEvent(Event $event): Event
    {
        $event->update(['is_active' => false]);

        $this->logAudit('event_deactivated', 'event', $event->id, []);

        return $event->fresh();
    }

    /**
     * Reactivate an event.
     */
    public function activateEvent(Event $event): Event
    {
        $event->update(['is_active' => true]);

        $this->logAudit('event_activated', 'event', $event->id, []);

        return $event->fresh();
    }

    /**
     * Get all services for moderation.
     */
    public function allServices(array $filters = [], int $perPage = 20)
    {
        $query = Service::with('provider');

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active'] === 'true' || $filters['is_active'] === '1');
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Deactivate a service listing.
     */
    public function deactivateService(Service $service): Service
    {
        $service->update(['is_active' => false]);

        $this->logAudit('service_deactivated', 'service', $service->id, []);

        return $service->fresh();
    }

    /**
     * Reactivate a service listing.
     */
    public function activateService(Service $service): Service
    {
        $service->update(['is_active' => true]);

        $this->logAudit('service_activated', 'service', $service->id, []);

        return $service->fresh();
    }

    // ─── Audit Logs ──────────────────────────────────────────────

    /**
     * Get paginated admin audit logs.
     */
    public function auditLogs(array $filters = [], int $perPage = 50)
    {
        $query = AdminAuditLog::with('admin')->recent();

        if (! empty($filters['action'])) {
            $query->byAction($filters['action']);
        }

        if (! empty($filters['entity_type'])) {
            $query->byEntityType($filters['entity_type']);
        }

        return $query->paginate($perPage);
    }

    // ─── Verification Management ────────────────────────────────

    /**
     * Get all verification submissions for admin review.
     */
    public function verifications(array $filters = [], int $perPage = 20)
    {
        $query = VerificationSubmission::with(['subscriptionPlan', 'user']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['subscription_plan_id'])) {
            $query->where('subscription_plan_id', $filters['subscription_plan_id']);
        }

        return $query->latest('created_at')->paginate($perPage);
    }

    /**
     * Get a single verification submission with full details.
     */
    public function verificationDetail(VerificationSubmission $submission): array
    {
        $submission->load(['subscriptionPlan', 'user', 'reviewer']);

        $fileUrlHelper = function ($path) {
            if (! $path) {
                return null;
            }
            $decoded = json_decode($path, true);
            if (is_array($decoded)) {
                return array_map(function ($p) {
                    if (! $p) {
                        return null;
                    }
                    $url = Storage::url($p);

                    return str_starts_with($url, 'http://') || str_starts_with($url, 'https://') ? $url : url($url);
                }, $decoded);
            }
            $url = Storage::url($path);

            return str_starts_with($url, 'http://') || str_starts_with($url, 'https://') ? $url : url($url);
        };

        return [
            'id' => $submission->id,
            'user' => [
                'id' => $submission->user?->id,
                'name' => $submission->user?->name,
                'username' => $submission->user?->username,
                'email' => $submission->user?->email,
            ],
            'subscription_plan' => [
                'id' => $submission->subscriptionPlan?->id,
                'name' => $submission->subscriptionPlan?->name,
                'slug' => $submission->subscriptionPlan?->slug,
            ],
            'status' => $submission->status,
            // 'first_name'                    => $submission->first_name,
            // 'last_name'                     => $submission->last_name,
            // 'business_name'                 => $submission->business_name,
            // 'business_description'          => $submission->business_description,
            // 'address_line_1'                => $submission->address_line_1,
            // 'address_line_2'                => $submission->address_line_2,
            // 'city'                          => $submission->city,
            // 'town'                          => $submission->town,
            // 'country'                       => $submission->country,
            // 'postal_code'                   => $submission->postal_code,
            'photo_id_url' => $fileUrlHelper($submission->photo_id_url),
            'proof_of_address_url' => $fileUrlHelper($submission->proof_of_address_url),
            'selfie_url' => $fileUrlHelper($submission->selfie_url),
            'business_insurance_url' => $fileUrlHelper($submission->business_insurance_url),
            'certification_url' => $fileUrlHelper($submission->certification_url),
            'branding_license_url' => $fileUrlHelper($submission->branding_license_url),
            'incorporation_certificate_url' => $fileUrlHelper($submission->incorporation_certificate_url),
            'admin_notes' => $submission->admin_notes,
            'rejection_reason' => $submission->rejection_reason,
            'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
            'created_at' => $submission->created_at?->toIso8601String(),
        ];
    }

    /**
     * Approve a verification submission and mark user as verified.
     */
    public function approveVerification(VerificationSubmission $submission): array
    {
        if ($submission->status === 'approved') {
            throw new \Exception('Verification is already approved', 422);
        }

        $adminProfileId = Auth::user()?->profile?->id;

        $submission->update([
            'status' => 'approved',
            'reviewed_at' => now(),
            'reviewed_by' => $adminProfileId,
        ]);

        // Mark the actual User (not Profile) as verified
        $profile = $submission->user; // user() relation returns Profile model
        if ($profile) {
            $profile->user()->update([
                'is_verified' => true,
                'verified_at' => now(),
            ]);

            // Deactivate all existing subscriptions for the user, then activate the verified plan
            UserSubscription::forUser($profile->id)->active()->update(['is_active' => false]);

            UserSubscription::create([
                'user_id' => $profile->id,
                'subscription_plan_id' => $submission->subscription_plan_id,
                'started_at' => now(),
                'expires_at' => null,
                'is_active' => true,
            ]);
        }

        $this->logAudit('verification_approved', 'verification_submission', $submission->id, [
            'subscription_plan_id' => $submission->subscription_plan_id,
            'user_id' => $submission->user_id,
        ]);

        return $this->verificationDetail($submission->fresh());
    }

    /**
     * Reject a verification submission.
     */
    public function rejectVerification(VerificationSubmission $submission, string $reason): array
    {
        if ($submission->status === 'approved') {
            throw new \Exception('Cannot reject an already approved verification', 422);
        }

        $adminProfileId = Auth::user()?->profile?->id;

        $submission->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_at' => now(),
            'reviewed_by' => $adminProfileId,
        ]);

        $this->logAudit('verification_rejected', 'verification_submission', $submission->id, [
            'reason' => $reason,
            'subscription_plan_id' => $submission->subscription_plan_id,
            'user_id' => $submission->user_id,
        ]);

        return $this->verificationDetail($submission->fresh());
    }

    /**
     * Record an admin action for audit trail.
     */
    protected function logAudit(string $action, string $entityType, ?int $entityId = null, array $metadata = []): void
    {
        try {
            $admin = Auth::user();

            if (! $admin) {
                return;
            }

            $adminId = $admin->profile?->id;

            if (! $adminId) {
                return;
            }

            AdminAuditLog::create([
                'admin_id' => $adminId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'old_value' => isset($metadata['old_status']) ? ['status' => $metadata['old_status']] : null,
                'new_value' => $metadata,
                'ip_address' => request()->ip(),
            ]);
        } catch (\Exception $e) {
            // Audit logging should never break the main action
            logger()->error('Failed to log admin audit: '.$e->getMessage());
        }
    }
}
