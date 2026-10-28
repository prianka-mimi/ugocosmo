<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChoosePlanRequest;
use App\Manager\SubscriptionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SubscriptionController extends Controller
{
    protected SubscriptionManager $subscriptionManager;

    public function __construct(SubscriptionManager $subscriptionManager)
    {
        $this->subscriptionManager = $subscriptionManager;
    }

    public function index(): JsonResponse
    {
        try {
            $this->data = $this->subscriptionManager->plans();
            $this->status_message = 'Subscription plans retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Subscription plans retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function choose(ChoosePlanRequest $request, int $subscriptionPlan): JsonResponse
    {
        try {
            DB::beginTransaction();
            $data = $request->validated();
            $data['subscription_plan_id'] = $subscriptionPlan;
            $this->data = $this->subscriptionManager->choose($data);
            DB::commit();

            $this->status_message = 'Subscription plan chosen successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Subscription plan choose error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
