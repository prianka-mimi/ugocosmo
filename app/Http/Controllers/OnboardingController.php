<?php

namespace App\Http\Controllers;

use App\Http\Requests\TermsAcceptRequest;
use App\Manager\OnboardingManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class OnboardingController extends Controller
{
    protected OnboardingManager $onboardingManager;

    public function __construct(OnboardingManager $onboardingManager)
    {
        $this->onboardingManager = $onboardingManager;
    }

    final public function completionStatus(): JsonResponse
    {
        try {
            $this->data = $this->onboardingManager->completionStatus();
            $this->status_message = 'Onboarding completion status retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Onboarding status retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    final public function acceptTerms(TermsAcceptRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->onboardingManager->acceptTerms($request->validated());
            DB::commit();

            $this->status_message = 'Terms and guidelines accepted successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Terms acceptance error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    final public function complete(): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = $this->onboardingManager->complete();
            DB::commit();

            $this->status_message = 'Onboarding completed successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Onboarding completion error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
