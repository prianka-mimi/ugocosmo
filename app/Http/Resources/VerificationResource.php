<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class VerificationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'subscription_plan' => [
                'id' => $this->subscriptionPlan?->id,
                'name' => $this->subscriptionPlan?->name,
                'slug' => $this->subscriptionPlan?->slug,
            ],
            'status' => $this->status,
            // 'first_name'                    => $this->first_name,
            // 'last_name'                     => $this->last_name,
            // 'business_name'                 => $this->business_name,
            // 'business_description'          => $this->business_description,
            // 'address_line_1'                => $this->address_line_1,
            // 'address_line_2'                => $this->address_line_2,
            // 'city'                          => $this->city,
            // 'town'                          => $this->town,
            // 'country'                       => $this->country,
            // 'postal_code'                   => $this->postal_code,
            'photo_id_url' => $this->fileUrl($this->photo_id_url),
            'proof_of_address_url' => $this->fileUrl($this->proof_of_address_url),
            'selfie_url' => $this->fileUrl($this->selfie_url),
            'business_insurance_url' => $this->fileUrl($this->business_insurance_url),
            'certification_url' => $this->fileUrl($this->certification_url),
            'branding_license_url' => $this->fileUrl($this->branding_license_url),
            'incorporation_certificate_url' => $this->fileUrl($this->incorporation_certificate_url),
            'admin_notes' => $this->admin_notes,
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    protected function fileUrl(?string $path)
    {
        if (empty($path)) {
            return null;
        }

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
}
