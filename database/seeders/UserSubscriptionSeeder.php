<?php

namespace Database\Seeders;

use App\Models\Profile;
use App\Models\SubscriptionPlan;
use App\Models\UserSubscription;
use Illuminate\Database\Seeder;

class UserSubscriptionSeeder extends Seeder
{
    public function run(): void
    {
        $verified = SubscriptionPlan::where('slug', 'verified')->first();
        $business = SubscriptionPlan::where('slug', 'business')->first();
        $premiumBusiness = SubscriptionPlan::where('slug', 'premium-business')->first();

        $assignments = [
            'alice' => $business,
            'bob' => $premiumBusiness,
            'carol' => $premiumBusiness,
            'david' => $verified,
            'eva' => $business,
            'frank' => $premiumBusiness,
            'grace' => $business,
            'henry' => $business,
            'isla' => $business,
            'jack' => $premiumBusiness,
        ];

        foreach ($assignments as $username => $plan) {
            if (! $plan) {
                continue;
            }
            $profile = Profile::whereHas('user', fn ($q) => $q->where('username', $username))->first();
            if (! $profile) {
                continue;
            }

            UserSubscription::firstOrCreate(['user_id' => $profile->id], [
                'user_id' => $profile->id,
                'subscription_plan_id' => $plan->id,
                'started_at' => now()->subMonths(3),
                'expires_at' => now()->addMonths(9),
                'is_active' => true,
            ]);

            // Update profile subscription tier
            $profile->update(['subscription_tier' => $plan->slug, 'subscription_expires_at' => now()->addMonths(9)]);
        }
    }
}
