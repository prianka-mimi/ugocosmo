<?php

namespace App\Manager;

use App\Models\Profile;
use App\Models\SubscriptionPlan;
use App\Models\UserSubscription;
use Illuminate\Support\Facades\Auth;

class SubscriptionManager
{
    public function plans(): array
    {
        $currentPlanId = null;
        $profileId = $this->profileIdOrNull();

        if ($profileId) {
            $currentPlanId = UserSubscription::forUser($profileId)
                ->active()
                ->latest('id')
                ->value('subscription_plan_id');
        }

        return SubscriptionPlan::active()
            ->ordered()
            ->get()
            ->map(fn (SubscriptionPlan $plan) => $this->formatPlan($plan, (int) $plan->id === (int) $currentPlanId))
            ->all();
    }

    public function choose(array $data): array
    {
        $profileId = $this->profileIdOrThrow();
        $plan = SubscriptionPlan::active()->findOrFail($data['subscription_plan_id']);

        $currentSubscription = UserSubscription::forUser($profileId)
            ->active()
            ->latest('id')
            ->first();

        if ($currentSubscription && (int) $currentSubscription->subscription_plan_id === (int) $plan->id) {
            return $this->formatSubscription($currentSubscription->load('subscriptionPlan'));
        }

        UserSubscription::forUser($profileId)->active()->update(['is_active' => false]);

        $subscription = UserSubscription::create([
            'user_id' => $profileId,
            'subscription_plan_id' => $plan->id,
            'started_at' => now(),
            'expires_at' => null,
            'is_active' => true,
        ]);

        return $this->formatSubscription($subscription->load('subscriptionPlan'));
    }

    protected function formatPlan(SubscriptionPlan $plan, bool $isCurrent): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'price' => $plan->price,
            'description' => $plan->description,
            'features' => $plan->features ?? [],
            'is_popular' => (bool) $plan->is_popular,
            'is_current' => $isCurrent,
        ];
    }

    protected function formatSubscription(UserSubscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'subscription_plan' => $this->formatPlan($subscription->subscriptionPlan, true),
            'started_at' => $subscription->started_at?->toIso8601String(),
            'expires_at' => $subscription->expires_at?->toIso8601String(),
            'is_active' => (bool) $subscription->is_active,
        ];
    }

    protected function profileIdOrNull(): ?int
    {
        $user = Auth::guard('jwt')->user();

        return $user->profile?->id;
    }

    protected function profileIdOrThrow(): int
    {
        $id = $this->profileIdOrNull();

        if (! $id) {
            // Auto-create a Profile for users who registered before the fix
            $user = Auth::guard('jwt')->user();
            $profile = Profile::create([
                'user_id' => $user->id,
                'email' => $user->email,
                'full_name' => $user->name,
                'username' => $user->username,
            ]);

            return (int) $profile->id;
        }

        return $id;
    }
}
