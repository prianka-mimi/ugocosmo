<?php

namespace App\Manager;

use App\Models\Profile;
use App\Models\SubscriptionPlan;
use App\Models\VerificationSubmission;
use App\Services\StripeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class VerificationManager
{
    /**
     * Get the current user's verification submissions.
     */
    public function index(): array
    {
        return VerificationSubmission::forUser($this->userId())
            ->latestFirst()
            ->with('subscriptionPlan')
            ->get()
            ->map(fn (VerificationSubmission $submission) => $this->format($submission))
            ->all();
    }

    /**
     * Show a specific verification submission.
     */
    public function show(VerificationSubmission $submission): array
    {
        $this->ensureOwned($submission);

        return $this->format($submission->load('subscriptionPlan'));
    }

    /**
     * Get the required document fields for a subscription plan.
     */
    public function requiredDocuments(int $planId): array
    {
        $plan = SubscriptionPlan::active()->findOrFail($planId);

        $documents = [
            'photo_id' => [
                'required' => true,
                'label' => 'Photographic ID',
                'description' => "Driver's licence, passport, or national ID",
                'accept' => 'image/*,application/pdf',
            ],
            'proof_of_address' => [
                'required' => true,
                'label' => 'Proof of Address',
                'description' => 'Bank statement or utility bill (last 3 months)',
                'accept' => 'image/*,application/pdf',
            ],
            'selfie' => [
                'required' => true,
                'label' => 'Live Selfie Verification',
                'description' => 'Take a live photo of yourself for biometric verification',
                'accept' => 'image/*',
            ],
        ];

        if (in_array($plan->slug, ['business', 'premium-business'])) {
            $documents['business_insurance'] = [
                'required' => true,
                'label' => 'Business Insurance',
                'description' => 'Copy of your business insurance certificate',
                'accept' => 'image/*,application/pdf',
            ];
            $documents['certification'] = [
                'required' => true,
                'label' => 'Certification/Qualification',
                'description' => 'Proof of qualification to sell your products/services',
                'accept' => 'image/*,application/pdf',
            ];
            $documents['branding_license'] = [
                'required' => false,
                'label' => 'Branding License (Optional)',
                'description' => 'Copyright document or license to sell branded products',
                'accept' => 'image/*,application/pdf',
            ];
        }

        if ($plan->slug === 'premium-business') {
            $documents['incorporation_certificate'] = [
                'required' => true,
                'label' => 'Certificate of Incorporation',
                'description' => 'Your business incorporation certificate',
                'accept' => 'image/*,application/pdf',
            ];
        }

        return [
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
            ],
            'stripe_connect_info' => in_array($plan->slug, ['business', 'premium-business']) ? [
                'required' => true,
                'label' => 'Stripe Connect',
                'description' => "After verification approval, you'll be able to connect your Stripe account in Settings to receive payments for your products and services.",
            ] : null,
            'documents' => $documents,
        ];
    }

    /**
     * Submit a verification with documents.
     */
    public function submit(array $data, int $planId): array
    {
        $plan = SubscriptionPlan::active()->findOrFail($planId);

        $price = (float) $plan->price;

        if ($price > 0) {
            $status = 'payment_pending';
            $paymentStatus = 'pending';
        } else {
            $status = 'pending';
            $paymentStatus = 'completed';
        }

        $submissionData = [
            'user_id' => $this->userId(),
            'subscription_plan_id' => $plan->id,
            'stripe_payment_intent_id' => null,
            'payment_status' => $paymentStatus,
            'status' => $status,
        ];

        // Handle file uploads
        $fileFields = [
            'photo_id' => 'photo_id_url',
            'proof_of_address' => 'proof_of_address_url',
            'business_insurance' => 'business_insurance_url',
            'certification' => 'certification_url',
            'branding_license' => 'branding_license_url',
            'incorporation_certificate' => 'incorporation_certificate_url',
        ];

        foreach ($fileFields as $inputField => $dbField) {
            if (isset($data[$inputField]) && $data[$inputField] instanceof UploadedFile) {
                $file = $data[$inputField];
                // Route images through ImageManager for WebP conversion; store other files (PDFs) as-is
                if (str_starts_with($file->getMimeType(), 'image/')) {
                    $submissionData[$dbField] = app(ImageManager::class)->upload($file, 'verifications/'.$this->userId());
                } else {
                    $submissionData[$dbField] = $file->store('verifications/'.$this->userId(), 'public');
                }
            }
        }

        // Handle selfies (can be single file or array of files)
        $selfieUrls = [];
        if (isset($data['selfies']) && is_array($data['selfies'])) {
            foreach ($data['selfies'] as $selfieFile) {
                if ($selfieFile instanceof UploadedFile) {
                    if (str_starts_with($selfieFile->getMimeType(), 'image/')) {
                        $selfieUrls[] = app(ImageManager::class)->upload($selfieFile, 'verifications/'.$this->userId());
                    } else {
                        $selfieUrls[] = $selfieFile->store('verifications/'.$this->userId(), 'public');
                    }
                }
            }
        } elseif (isset($data['selfie']) && $data['selfie'] instanceof UploadedFile) {
            $selfieFile = $data['selfie'];
            if (str_starts_with($selfieFile->getMimeType(), 'image/')) {
                $selfieUrls[] = app(ImageManager::class)->upload($selfieFile, 'verifications/'.$this->userId());
            } else {
                $selfieUrls[] = $selfieFile->store('verifications/'.$this->userId(), 'public');
            }
        }

        if (! empty($selfieUrls)) {
            $submissionData['selfie_url'] = json_encode($selfieUrls);
        }

        $submission = VerificationSubmission::create($submissionData);

        $checkoutUrl = null;
        if ($price > 0) {
            $stripeService = app(StripeService::class);
            $successUrl = $data['success_url'] ?? (config('app.url').'payment-success?type=verification&submission_id='.$submission->id);
            $cancelUrl = $data['cancel_url'] ?? (config('app.url').'payment-cancelled?type=verification&submission_id='.$submission->id);

            try {
                $session = $stripeService->createCheckoutSession(
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
                    ]
                );

                $submission->update([
                    'stripe_payment_intent_id' => $session->id,
                ]);
                $checkoutUrl = $session->url;
            } catch (\Exception $e) {
                logger()->error('Stripe Checkout Session creation failed: '.$e->getMessage());
            }
        }

        return $this->format($submission->load('subscriptionPlan'), $checkoutUrl);
    }

    /**
     * Re-submit for a new plan (for users who have already completed onboarding).
     * All document fields are optional — existing docs from the latest submission are reused
     * if no new files are provided.
     */
    public function choosePlan(array $data, int $planId): array
    {
        $plan = SubscriptionPlan::active()->findOrFail($planId);
        $price = (float) $plan->price;

        $status = $price > 0 ? 'payment_pending' : 'pending';
        $paymentStatus = $price > 0 ? 'pending' : 'completed';

        // Pull existing docs from the user's latest submission as fallback
        $previous = VerificationSubmission::forUser($this->userId())
            ->latestFirst()
            ->first();

        $submissionData = [
            'user_id' => $this->userId(),
            'subscription_plan_id' => $plan->id,
            'stripe_payment_intent_id' => null,
            'payment_status' => $paymentStatus,
            'status' => $status,
            // Carry over existing doc URLs as defaults
            'photo_id_url' => $previous?->photo_id_url,
            'proof_of_address_url' => $previous?->proof_of_address_url,
            'selfie_url' => $previous?->selfie_url,
            'business_insurance_url' => $previous?->business_insurance_url,
            'certification_url' => $previous?->certification_url,
            'branding_license_url' => $previous?->branding_license_url,
            'incorporation_certificate_url' => $previous?->incorporation_certificate_url,
        ];

        // Override with any newly uploaded files
        $fileFields = [
            'photo_id' => 'photo_id_url',
            'proof_of_address' => 'proof_of_address_url',
            'business_insurance' => 'business_insurance_url',
            'certification' => 'certification_url',
            'branding_license' => 'branding_license_url',
            'incorporation_certificate' => 'incorporation_certificate_url',
        ];

        foreach ($fileFields as $inputField => $dbField) {
            if (isset($data[$inputField]) && $data[$inputField] instanceof UploadedFile) {
                $file = $data[$inputField];
                if (str_starts_with($file->getMimeType(), 'image/')) {
                    $submissionData[$dbField] = app(ImageManager::class)->upload($file, 'verifications/'.$this->userId());
                } else {
                    $submissionData[$dbField] = $file->store('verifications/'.$this->userId(), 'public');
                }
            }
        }

        // Handle selfies
        $selfieUrls = [];
        if (isset($data['selfies']) && is_array($data['selfies'])) {
            foreach ($data['selfies'] as $selfieFile) {
                if ($selfieFile instanceof UploadedFile) {
                    $selfieUrls[] = str_starts_with($selfieFile->getMimeType(), 'image/')
                        ? app(ImageManager::class)->upload($selfieFile, 'verifications/'.$this->userId())
                        : $selfieFile->store('verifications/'.$this->userId(), 'public');
                }
            }
        } elseif (isset($data['selfie']) && $data['selfie'] instanceof UploadedFile) {
            $selfieFile = $data['selfie'];
            $selfieUrls[] = str_starts_with($selfieFile->getMimeType(), 'image/')
                ? app(ImageManager::class)->upload($selfieFile, 'verifications/'.$this->userId())
                : $selfieFile->store('verifications/'.$this->userId(), 'public');
        }

        if (! empty($selfieUrls)) {
            $submissionData['selfie_url'] = json_encode($selfieUrls);
        }

        $submission = VerificationSubmission::create($submissionData);

        $checkoutUrl = null;
        if ($price > 0) {
            $stripeService = app(StripeService::class);
            $successUrl = $data['success_url'] ?? (config('app.url').'payment-success?type=verification&submission_id='.$submission->id);
            $cancelUrl = $data['cancel_url'] ?? (config('app.url').'payment-cancelled?type=verification&submission_id='.$submission->id);

            try {
                $session = $stripeService->createCheckoutSession(
                    [[
                        'price_data' => [
                            'currency' => 'usd',
                            'product_data' => [
                                'name' => 'Verification: '.$plan->name,
                                'description' => 'Verification fee for plan '.$plan->name,
                            ],
                            'unit_amount' => (int) ($price * 100),
                        ],
                        'quantity' => 1,
                    ]],
                    $successUrl,
                    $cancelUrl,
                    ['type' => 'verification', 'submission_id' => (string) $submission->id]
                );

                $submission->update(['stripe_payment_intent_id' => $session->id]);
                $checkoutUrl = $session->url;
            } catch (\Exception $e) {
                logger()->error('Stripe Checkout Session creation failed in choosePlan: '.$e->getMessage());
            }
        }

        return $this->format($submission->load('subscriptionPlan'), $checkoutUrl);
    }

    /**
     * Confirm Stripe payment for a verification submission.
     */
    public function confirmPayment(VerificationSubmission $submission, array $urls = []): array
    {
        $this->ensureOwned($submission);

        if ($submission->status !== 'payment_pending') {
            throw new \Exception('This verification submission does not require payment or is already paid.', 400);
        }

        $plan = $submission->subscriptionPlan;
        $price = (float) ($plan?->price ?? 0);

        if ($price <= 0) {
            throw new \Exception('This verification submission does not require payment.', 400);
        }

        $stripeService = app(StripeService::class);
        $successUrl = $urls['success_url'] ?? (config('app.url').'payment-success?type=verification&submission_id='.$submission->id);
        $cancelUrl = $urls['cancel_url'] ?? (config('app.url').'payment-cancelled?type=verification&submission_id='.$submission->id);

        try {
            $session = $stripeService->createCheckoutSession(
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
                ]
            );

            $submission->update([
                'stripe_payment_intent_id' => $session->id,
            ]);

            return $this->format($submission, $session->url);
        } catch (\Exception $e) {
            logger()->error('Stripe Checkout Session creation failed in confirmPayment: '.$e->getMessage());
            throw new \Exception('Could not create payment session: '.$e->getMessage(), 500);
        }
    }

    /**
     * Format a verification submission for API response.
     */
    protected function format(VerificationSubmission $submission, ?string $checkoutUrl = null): array
    {
        return [
            'id' => $submission->id,
            'subscription_plan' => [
                'id' => $submission->subscriptionPlan?->id,
                'name' => $submission->subscriptionPlan?->name,
                'slug' => $submission->subscriptionPlan?->slug,
            ],
            'status' => $submission->status,
            'payment_status' => $submission->payment_status,
            'stripe_payment_intent_id' => $submission->stripe_payment_intent_id,
            'checkout_url' => $checkoutUrl,
            'first_name' => $submission->first_name,
            'last_name' => $submission->last_name,
            'business_name' => $submission->business_name,
            'business_description' => $submission->business_description,
            'address_line_1' => $submission->address_line_1,
            'address_line_2' => $submission->address_line_2,
            'city' => $submission->city,
            'town' => $submission->town,
            'country' => $submission->country,
            'postal_code' => $submission->postal_code,
            'photo_id_url' => $this->fileUrl($submission->photo_id_url),
            'proof_of_address_url' => $this->fileUrl($submission->proof_of_address_url),
            'selfie_url' => $this->fileUrl($submission->selfie_url),
            'business_insurance_url' => $this->fileUrl($submission->business_insurance_url),
            'certification_url' => $this->fileUrl($submission->certification_url),
            'branding_license_url' => $this->fileUrl($submission->branding_license_url),
            'incorporation_certificate_url' => $this->fileUrl($submission->incorporation_certificate_url),
            'admin_notes' => $submission->admin_notes,
            'rejection_reason' => $submission->rejection_reason,
            'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
            'created_at' => $submission->created_at?->toIso8601String(),
            'updated_at' => $submission->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Generate a full URL for a stored file, handling arrays as well.
     */
    protected function fileUrl(?string $path)
    {
        if (empty($path)) {
            return null;
        }

        // Try decoding as JSON (e.g. for multi selfies)
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
    }

    /**
     * Ensure the submission belongs to the current user.
     */
    protected function ensureOwned(VerificationSubmission $submission): void
    {
        if ((int) $submission->user_id !== $this->userId()) {
            abort(404);
        }
    }

    /**
     * Get the current authenticated user's Profile ID.
     */
    protected function userId(): int
    {
        $user = Auth::guard('jwt')->user();

        if (! $user) {
            throw new \Exception('Unauthorized', 401);
        }

        $profile = $user->profile;

        if (! $profile) {
            // Auto-create a Profile for users who don't have one yet
            $profile = Profile::create([
                'user_id' => $user->id,
                'email' => $user->email,
                'full_name' => $user->name,
                'username' => $user->username,
            ]);
        }

        return (int) $profile->id;
    }
}
