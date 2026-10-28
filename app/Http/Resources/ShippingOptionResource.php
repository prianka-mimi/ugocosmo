<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShippingOptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Maps ShipStation Shipping Rules directly into standard API contract:
     * - rule_code: e.g. STANDARD_SHIPPING, EXPRESS_SHIPPING, INTERNATIONAL_SHIPPING_
     * - rule_id: e.g. se-163, se-164, se-162, se-161, se-160
     * - rule_type: Service Group | Condition
     * - key: standard_shipping, express_shipping, etc.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $raw = is_array($this->resource) ? $this->resource : (array) $this->resource;

        return [
            'rule_code' => $raw['rule_code'] ?? strtoupper($raw['key'] ?? ''),
            'rule_id' => $raw['rule_id'] ?? null,
            'rule_type' => $raw['rule_type'] ?? 'Service Group',
            'key' => strtolower($raw['key'] ?? $raw['rule_code'] ?? ''),
            'name' => $raw['name'] ?? ucwords(str_replace('_', ' ', strtolower($raw['rule_code'] ?? $raw['key'] ?? ''))),
            'cost' => (float) ($raw['cost'] ?? 0.0),
            'estimated_delivery' => $raw['estimated_delivery'] ?? ($raw['estimated_days'] ?? '3-5 business days'),
            'description' => $raw['description'] ?? 'Automated shipping option configured in ShipStation rules.',
            'is_available' => (bool) ($raw['is_available'] ?? true),
        ];
    }
}

