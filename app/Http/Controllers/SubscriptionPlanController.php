<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubscriptionPlanStoreRequest;
use App\Http\Requests\SubscriptionPlanUpdateRequest;
use App\Manager\SubscriptionPlanManager;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SubscriptionPlanController extends Controller
{
    protected SubscriptionPlanManager $subscriptionPlanManager;

    public function __construct(SubscriptionPlanManager $subscriptionPlanManager)
    {
        $this->subscriptionPlanManager = $subscriptionPlanManager;
    }

    public function index()
    {
        $plans = SubscriptionPlan::orderBy('sort_order', 'asc')->get();

        return view('modules.subscription.index', compact('plans'));
    }

    public function create()
    {
        return view('modules.subscription.form', ['plan' => null]);
    }

    public function store(SubscriptionPlanStoreRequest $request)
    {
        try {
            DB::beginTransaction();
            $features = $this->subscriptionPlanManager->parseFeatures($request->input('features'));

            SubscriptionPlan::create([
                'sort_order' => $request->sort_order ?? 0,
                'name' => $request->name,
                'slug' => $request->slug,
                'price' => $request->price,
                'description' => $request->description,
                'features' => $features,
                'is_popular' => $request->has('is_popular'),
                'status' => $request->status ?? 'active',
            ]);
            DB::commit();

            return redirect()->route('subscription.index')->with('success', 'Subscription plan created successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Subscription plan creation failed: '.$e->getMessage());

            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(SubscriptionPlan $subscription)
    {
        return view('modules.subscription.show', ['plan' => $subscription]);
    }

    public function edit(SubscriptionPlan $subscription)
    {
        return view('modules.subscription.form', ['plan' => $subscription]);
    }

    public function update(SubscriptionPlanUpdateRequest $request, SubscriptionPlan $subscription)
    {
        try {
            DB::beginTransaction();
            $features = $this->subscriptionPlanManager->parseFeatures($request->input('features'));

            $subscription->update([
                'sort_order' => $request->sort_order ?? $subscription->sort_order,
                'name' => $request->name,
                'slug' => $request->slug,
                'price' => $request->price,
                'description' => $request->description,
                'features' => $features,
                'is_popular' => $request->has('is_popular'),
                'status' => $request->status ?? $subscription->status,
            ]);
            DB::commit();

            return redirect()->route('subscription.index')->with('success', 'Subscription plan updated successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Subscription plan update failed: '.$e->getMessage());

            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function destroy(SubscriptionPlan $subscription)
    {
        try {
            DB::beginTransaction();
            $subscription->delete();
            DB::commit();

            return redirect()->route('subscription.index')->with('success', 'Subscription plan deleted successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Subscription plan deletion failed: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }

    public function toggleStatus(SubscriptionPlan $subscription)
    {
        try {
            DB::beginTransaction();
            $this->subscriptionPlanManager->toggleStatus($subscription);
            DB::commit();

            return back()->with('success', 'Subscription plan status updated.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Subscription plan status toggle failed: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }
}
