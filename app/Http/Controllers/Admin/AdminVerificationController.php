<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Manager\ImageManager;
use App\Models\AdminAuditLog;
use App\Models\SubscriptionPlan;
use App\Models\UserSubscription;
use App\Models\VerificationSubmission;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminVerificationController extends Controller
{
    protected StripeService $stripeService;

    public function __construct(StripeService $stripeService)
    {
        $this->stripeService = $stripeService;
    }

    /**
     * List all verification submissions with optional filters.
     */
    public function index(Request $request)
    {
        $query = VerificationSubmission::with(['subscriptionPlan', 'user.user'])
            ->latest('created_at');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($planId = $request->input('subscription_plan_id')) {
            $query->where('subscription_plan_id', $planId);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%");
            });
        }

        $submissions = $query->paginate(20)->withQueryString();
        $plans = SubscriptionPlan::orderBy('sort_order')->get();

        return view('modules.admin.verifications.index', compact('submissions', 'plans'));
    }

    /**
     * Show a single verification submission in detail.
     */
    public function show(VerificationSubmission $submission)
    {
        $submission->load(['subscriptionPlan', 'user.user', 'reviewer.user']);

        // Build document list with storage URLs
        $documents = [];
        $docFields = [
            'photo_id_url' => 'Photographic ID',
            'proof_of_address_url' => 'Proof of Address',
            'selfie_url' => 'Live Selfie',
            'business_insurance_url' => 'Business Insurance',
            'certification_url' => 'Certification / Qualification',
            'branding_license_url' => 'Branding License',
            'incorporation_certificate_url' => 'Certificate of Incorporation',
        ];

        foreach ($docFields as $field => $label) {
            if ($submission->$field) {
                $decoded = json_decode($submission->$field, true);
                if ($field === 'selfie_url' && is_array($decoded)) {
                    foreach ($decoded as $index => $path) {
                        $documents[] = [
                            'label' => $label.' '.($index + 1),
                            'url' => ImageManager::fileUrl($path),
                            'field' => $field,
                        ];
                    }
                } else {
                    $documents[] = [
                        'label' => $label,
                        'url' => ImageManager::fileUrl($submission->$field),
                        'field' => $field,
                    ];
                }
            }
        }

        $paymentDetails = $this->stripeService->getPaymentDetails($submission->stripe_payment_intent_id);

        return view('modules.admin.verifications.show', compact('submission', 'documents', 'paymentDetails'));
    }

    /**
     * Approve a verification submission, verify the user, and activate their subscription.
     */
    public function approve(VerificationSubmission $submission)
    {
        try {
            DB::beginTransaction();

            if ($submission->status === 'approved') {
                return back()->with('error', 'This verification is already approved.');
            }

            $adminProfileId = auth()->user()?->profile?->id;

            $submission->update([
                'status' => 'approved',
                'reviewed_at' => now(),
                'reviewed_by' => $adminProfileId,
            ]);

            // Mark the User (not Profile) as verified
            $profile = $submission->user;
            if ($profile) {
                $profile->user()->update([
                    'is_verified' => true,
                    'verified_at' => now(),
                ]);

                // Deactivate existing subscriptions and create a fresh active one
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

            DB::commit();

            return redirect()
                ->route('admin.verifications.index')
                ->with('success', 'Verification approved and user subscription activated.');
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Admin approve verification error: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Generate a fresh Stripe checkout link for admin use (no email sent).
     */
    public function generatePaymentLink(VerificationSubmission $submission)
    {
        if ($submission->payment_status === 'completed') {
            return back()->with('error', 'This submission is already paid.');
        }

        $plan = $submission->subscriptionPlan;
        $price = (float) ($plan?->price ?? 0);

        if ($price <= 0) {
            return back()->with('error', 'This submission does not require payment.');
        }

        $customerEmail = $submission->user?->user?->email ?? $submission->user?->email;

        try {
            $successUrl = config('app.url').'payment-success?type=verification&submission_id='.$submission->id;
            $cancelUrl = config('app.url').'payment-cancelled?type=verification&submission_id='.$submission->id;

            $session = $this->stripeService->createCheckoutSession(
                [
                    [
                        'price_data' => [
                            'currency' => 'usd',
                            'product_data' => [
                                'name' => 'Verification: '.$plan->name,
                                'description' => 'Verification fee for plan '.$plan->name,
                            ],
                            'unit_amount' => (int) ($price * 100),
                        ],
                        'quantity' => 1,
                    ],
                ],
                $successUrl,
                $cancelUrl,
                [
                    'type' => 'verification',
                    'submission_id' => (string) $submission->id,
                    'profile_id' => (string) $submission->user_id,
                ],
                $customerEmail
            );

            $submission->update([
                'stripe_payment_intent_id' => $session->id,
            ]);

            $this->logAudit('verification_payment_link_generated', 'verification_submission', $submission->id, [
                'user_id' => $submission->user_id,
                'checkout_session_id' => $session->id,
            ]);

            return back()
                ->with('success', 'Payment link generated. Copy and share it manually if needed.')
                ->with('checkout_url', $session->url);
        } catch (Throwable $e) {
            Log::error('Admin generate verification payment link error: '.$e->getMessage());

            return back()->with('error', 'Could not generate payment link: '.$e->getMessage());
        }
    }

    /**
     * Reject a verification submission with a reason.
     */
    public function reject(Request $request, VerificationSubmission $submission)
    {
        $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            DB::beginTransaction();

            if ($submission->status === 'approved') {
                return back()->with('error', 'Cannot reject an already approved verification.');
            }

            $adminProfileId = auth()->user()?->profile?->id;

            $submission->update([
                'status' => 'rejected',
                'rejection_reason' => $request->input('rejection_reason'),
                'reviewed_at' => now(),
                'reviewed_by' => $adminProfileId,
            ]);

            $this->logAudit('verification_rejected', 'verification_submission', $submission->id, [
                'reason' => $request->input('rejection_reason'),
                'subscription_plan_id' => $submission->subscription_plan_id,
                'user_id' => $submission->user_id,
            ]);

            DB::commit();

            return redirect()
                ->route('admin.verifications.index')
                ->with('success', 'Verification rejected successfully.');
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Admin reject verification error: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }

    protected function logAudit(string $action, string $entityType, ?int $entityId, array $metadata): void
    {
        try {
            AdminAuditLog::create([
                'admin_id' => auth()->user()?->profile?->id,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'new_value' => $metadata,
                'ip_address' => request()->ip(),
            ]);
        } catch (\Exception $e) {
            logger()->error('Failed to log admin audit: '.$e->getMessage());
        }
    }
}
