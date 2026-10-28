<?php

namespace App\Manager;

use App\Models\SubscriptionPlan;

class SubscriptionPlanManager
{
    /**
     * Parse newline-separated features string into array.
     */
    public function parseFeatures(?string $features): array
    {
        if (! $features || ! is_string($features)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode('
', $features)), fn ($line) => $line !== ''));
    }

    /**
     * Toggle plan active/inactive status.
     */
    public function toggleStatus(SubscriptionPlan $plan): SubscriptionPlan
    {
        $plan->update(['status' => $plan->status === 'active' ? 'inactive' : 'active']);

        return $plan->fresh();
    }
}
