<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BankAccountResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'bank_name' => $this->bank_name,
            'account_name' => $this->account_name,
            'account_number' => '****'.substr($this->account_number, -4),
            'account_number_full' => $this->account_number,
            'sort_code' => $this->sort_code,
            'iban' => $this->iban ? '****'.substr($this->iban, -4) : null,
            'swift_code' => $this->swift_code,
            'statement_url' => $this->statement_path ? asset('storage/'.$this->statement_path) : null,
            'is_primary' => (bool) $this->is_primary,
            'is_verified' => (bool) $this->is_verified,
            'status' => $this->status ?: 'pending',
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
