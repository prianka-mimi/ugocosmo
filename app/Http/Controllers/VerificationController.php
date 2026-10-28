<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChoosePlanRequest;
use App\Http\Requests\VerificationSubmitRequest;
use App\Manager\VerificationManager;
use App\Models\VerificationSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class VerificationController extends Controller
{
    protected VerificationManager $verificationManager;

    public function __construct(VerificationManager $verificationManager)
    {
        $this->verificationManager = $verificationManager;
    }

    /**
     * List all verification submissions for the current user.
     */
    public function index(): JsonResponse
    {
        try {
            $this->data = $this->verificationManager->index();
            $this->status_message = 'Verification submissions retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Verification submissions retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Show a specific verification submission.
     */
    public function show(VerificationSubmission $submission): JsonResponse
    {
        try {
            $this->data = $this->verificationManager->show($submission);
            $this->status_message = 'Verification submission retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Verification submission retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Get required documents for a subscription plan.
     */
    public function requiredDocuments(int $planId): JsonResponse
    {
        try {
            $this->data = $this->verificationManager->requiredDocuments($planId);
            $this->status_message = 'Required documents retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Required documents retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Submit a verification with documents.
     */
    public function submit(VerificationSubmitRequest $request, int $planId): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->verificationManager->submit($request->validated(), $planId);
            DB::commit();

            $this->status_message = 'Verification submitted successfully';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Verification submission error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Re-choose a plan for users who have already completed onboarding.
     * All document fields are optional — existing docs are reused if not re-uploaded.
     */
    public function choosePlan(ChoosePlanRequest $request, int $planId): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->verificationManager->choosePlan($request->validated(), $planId);
            DB::commit();

            $this->status_message = 'Plan chosen and verification submitted successfully';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Choose plan error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Confirm Stripe payment for a pending verification submission.
     * Generates a Stripe Checkout Session URL for the submission payment.
     */
    public function confirmPayment(Request $request, VerificationSubmission $submission): JsonResponse
    {
        try {
            $request->validate([
                'success_url' => ['nullable', 'url'],
                'cancel_url' => ['nullable', 'url'],
            ]);

            DB::beginTransaction();
            $this->data = $this->verificationManager->confirmPayment(
                $submission,
                $request->only(['success_url', 'cancel_url'])
            );
            DB::commit();

            $this->status_message = 'Checkout session URL generated successfully.';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Verification payment confirmation error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
