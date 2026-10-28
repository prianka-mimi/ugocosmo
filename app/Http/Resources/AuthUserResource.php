<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AuthUserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'account_type' => $this->account_type,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'mobile_number' => $this->mobile_number,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'is_verified' => (bool) $this->is_verified,
        ];
    }
}
