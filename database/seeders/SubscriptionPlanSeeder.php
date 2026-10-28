<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Verified',
                'slug' => 'verified',
                'price' => 0.00,
                'description' => 'Free with ID and card verification',
                'features' => [
                    'Message other users',
                    'Send connection & follow requests',
                    'Match professionally or socially',
                    'See and accept requests',
                    'Blue verification badge',
                ],
                'is_popular' => false,
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'price' => 0.00,
                'description' => 'Free with ID and card verification',
                'features' => [
                    'All Verified features',
                    'List and sell services',
                    'List and sell products',
                    'Business analytics',
                    'Blue verification badge',
                ],
                'is_popular' => true,
            ],
            [
                'name' => 'Premium Business',
                'slug' => 'premium-business',
                'price' => 19.99,
                'description' => 'Full access to all platform features',
                'features' => [
                    'All Business features',
                    'Create and sell event tickets',
                    'Post job listings',
                    'Priority support',
                    'Blue verification badge',
                ],
                'is_popular' => false,
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::firstOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
