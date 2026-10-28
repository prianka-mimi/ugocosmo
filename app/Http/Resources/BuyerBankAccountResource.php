<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BuyerBankAccountResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'bank_name' => $this->bank_name,
            'account_name' => $this->account_name,
            'account_number' => $this->account_number,
            'account_number_masked' => '****'.substr($this->account_number_last4, -4),
            'account_number_last4' => $this->account_number_last4,
            'sort_code' => $this->sort_code,
            'iban' => $this->iban,
            'swift_code' => $this->swift_code,
            'statement_url' => $this->statement_path ? asset('storage/'.$this->statement_path) : null,
            'is_own_account' => (bool) $this->is_own_account,
            'status' => $this->status ?: 'pending',
            'declaration_signed_at' => $this->declaration_signed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
