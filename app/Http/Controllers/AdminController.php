<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignRoleRequest;
use App\Http\Resources\AdminUserResource;
use App\Manager\AdminManager;
use App\Models\Event;
use App\Models\Post;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Models\VerificationSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AdminController - All admin panel endpoints.
 *
 * Access control handled by AdminMiddleware in routes:
 * - 'admin:supervisor' → admin + supervisor can access
 * - 'admin:admin' → only admin can access
 *
 * All endpoints require JWT auth + admin/supervisor role.
 */
class AdminController extends Controller
{
    protected AdminManager $adminManager;

    public function __construct(AdminManager $adminManager)
    {
        $this->adminManager = $adminManager;
    }

    // ─── Dashboard ────────────────────────────────────────────────

    /**
     * Get overall platform dashboard statistics.
     * Access: admin, supervisor
     */
    public function dashboard(): JsonResponse
    {
        try {
            $this->data = $this->adminManager->dashboard();
            $this->status_message = 'Dashboard data retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Admin dashboard error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    // ─── User Management ──────────────────────────────────────────

    /**
     * Get paginated list of all users with optional filters.
     * Access: admin, supervisor
     */
    public function users(): JsonResponse
    {
        try {
            $filters = request()->only(['role', 'status', 'search', 'is_verified']);
            $perPage = (int) request()->input('per_page', 20);

            $this->data = AdminUserResource::collection(
                $this->adminManager->users($filters, $perPage)
            )->response()->getData();

            $this->status_message = 'Users retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Admin users list error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Get a single user's full details.
     * Access: admin, supervisor
     */
    public function userDetail(User $user): JsonResponse
    {
        try {
            $this->data = AdminUserResource::make(
                $this->adminManager->userDetail($user)
            );
            $this->status_message = 'User details retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Admin user detail error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Suspend a user account.
     * Access: admin, supervisor
     */
    public function suspendUser(User $user): JsonResponse
    {
        try {
            DB::beginTransaction();
            $reason = request()->input('reason');
            $this->data = AdminUserResource::make(
                $this->adminManager->suspendUser($user, $reason)
            );
            DB::commit();

            $this->status_message = 'User suspended successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Admin suspend user error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Activate a suspended user.
     * Access: admin, supervisor
     */
    public function activateUser(User $user): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = AdminUserResource::make(
                $this->adminManager->activateUser($user)
            );
            DB::commit();

            $this->status_message = 'User activated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Admin activate user error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Verify a user's account.
     * Access: admin (only)
     */
    public function verifyUser(User $user): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = AdminUserResource::make(
                $this->adminManager->verifyUser($user)
            );
            DB::commit();

            $this->status_message = 'User verified successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Admin verify user error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Permanently delete a user account.
     * Access: admin (only)
     */
    public function deleteUser(User $user): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->adminManager->deleteUser($user);
            DB::commit();

            $this->data = [];
            $this->status_message = 'User deleted successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Admin delete user error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    // ─── Role Management (Admin only) ────────────────────────────

    /**
     * Get all roles and distribution.
     * Access: admin (only)
     */
    public function roles(): JsonResponse
    {
        try {
            $this->data = $this->adminManager->roles();
            $this->status_message = 'Roles retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Admin roles error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Assign or change a user's role.
     * Access: admin (only)
     */
    public function assignRole(AssignRoleRequest $request, User $user): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = AdminUserResource::make(
                $this->adminManager->assignRole($user, $request->input('role'))
            );
            DB::commit();

            $this->status_message = 'Role assigned successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Admin assign role error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    // ─── Content Moderation ───────────────────────────────────────

    /**
     * Get reported/flagged posts.
     * Access: admin, supervisor
     */
    public function reportedPosts(): JsonResponse
    {
        try {
            $perPage = (int) request()->input('per_page', 20);
            $this->data = $this->adminManager->reportedPosts($perPage);
            $this->status_message = 'Reported posts retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Admin reported posts error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Get all posts for moderation.
     * Access: admin, supervisor
     */
    public function allPosts(): JsonResponse
    {
        try {
            $filters = request()->only(['is_active', 'search']);
            $perPage = (int) request()->input('per_page', 20);

            $this->data = $this->adminManager->allPosts($filters, $perPage);
            $this->status_message = 'Posts retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Admin posts list error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Deactivate a post (remove from public view).
     * Access: admin, supervisor
     */
    public function deactivatePost(Post $post): JsonResponse
    {
        try {
            DB::beginTransaction();
            $reason = request()->input('reason');
            $this->data = $this->adminManager->deactivatePost($post, $reason);
            DB::commit();

            $this->status_message = 'Post deactivated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Admin deactivate post error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Reactivate a deactivated post.
     * Access: admin, supervisor
     */
    public function activatePost(Post $post): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->adminManager->activatePost($post);
            DB::commit();

            $this->status_message = 'Post activated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Admin activate post error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Get all products for moderation.
     * Access: admin, supervisor
     */
    public function allProducts(): JsonResponse
    {
        try {
            $filters = request()->only(['is_active']);
            $perPage = (int) request()->input('per_page', 20);

            $this->data = $this->adminManager->allProducts($filters, $perPage);
            $this->status_message = 'Products retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Admin products list error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Deactivate a product listing.
     * Access: admin, supervisor
     */
    public function deactivateProduct(Product $product): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->adminManager->deactivateProduct($product);
            DB::commit();

            $this->status_message = 'Product deactivated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Admin deactivate product error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Reactivate a product listing.
     * Access: admin, supervisor
     */
    public function activateProduct(Product $product): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->adminManager->activateProduct($product);
            DB::commit();

            $this->status_message = 'Product activated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Admin activate product error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Get all events for moderation.
     * Access: admin, supervisor
     */
    public function allEvents(): JsonResponse
    {
        try {
            $filters = request()->only(['is_active']);
            $perPage = (int) request()->input('per_page', 20);

            $this->data = $this->adminManager->allEvents($filters, $perPage);
            $this->status_message = 'Events retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Admin events list error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Deactivate an event.
     * Access: admin, supervisor
     */
    public function deactivateEvent(Event $event): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->adminManager->deactivateEvent($event);
            DB::commit();

            $this->status_message = 'Event deactivated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Admin deactivate event error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Reactivate an event.
     * Access: admin, supervisor
     */
    public function activateEvent(Event $event): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->adminManager->activateEvent($event);
            DB::commit();

            $this->status_message = 'Event activated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Admin activate event error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Get all services for moderation.
     * Access: admin, supervisor
     */
    public function allServices(): JsonResponse
    {
        try {
            $filters = request()->only(['is_active']);
            $perPage = (int) request()->input('per_page', 20);

            $this->data = $this->adminManager->allServices($filters, $perPage);
            $this->status_message = 'Services retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Admin services list error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Deactivate a service listing.
     * Access: admin, supervisor
     */
    public function deactivateService(Service $service): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->adminManager->deactivateService($service);
            DB::commit();

            $this->status_message = 'Service deactivated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Admin deactivate service error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Reactivate a service listing.
     * Access: admin, supervisor
     */
    public function activateService(Service $service): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->adminManager->activateService($service);
            DB::commit();

            $this->status_message = 'Service activated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Admin activate service error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    // ─── Audit Logs ───────────────────────────────────────────────

    /**
     * Get paginated admin audit logs.
     * Access: admin (only)
     */
    public function auditLogs(): JsonResponse
    {
        try {
            $filters = request()->only(['action', 'entity_type']);
            $perPage = (int) request()->input('per_page', 50);

            $this->data = $this->adminManager->auditLogs($filters, $perPage);
            $this->status_message = 'Audit logs retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Admin audit logs error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    // ─── Verification Management ─────────────────────────────────

    /**
     * Get all verification submissions.
     * Access: admin, supervisor
     */
    public function verifications(): JsonResponse
    {
        try {
            $filters = request()->only(['status', 'subscription_plan_id']);
            $perPage = (int) request()->input('per_page', 20);

            $this->data = $this->adminManager->verifications($filters, $perPage);
            $this->status_message = 'Verifications retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Admin verifications list error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Get a single verification submission detail.
     * Access: admin, supervisor
     */
    public function verificationDetail(VerificationSubmission $submission): JsonResponse
    {
        try {
            $this->data = $this->adminManager->verificationDetail($submission);
            $this->status_message = 'Verification detail retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Admin verification detail error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Approve a verification submission.
     * Access: admin (only)
     */
    public function approveVerification(VerificationSubmission $submission): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->adminManager->approveVerification($submission);
            DB::commit();

            $this->status_message = 'Verification approved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Admin approve verification error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Reject a verification submission.
     * Access: admin (only)
     */
    public function rejectVerification(VerificationSubmission $submission): JsonResponse
    {
        try {
            DB::beginTransaction();
            $reason = request()->input('reason', 'Verification documents did not meet requirements.');
            $this->data = $this->adminManager->rejectVerification($submission, $reason);
            DB::commit();

            $this->status_message = 'Verification rejected';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Admin reject verification error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
