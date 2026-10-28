<?php

namespace App\Http\Requests;

use App\Enums\InventoryTransactionType;
use Illuminate\Foundation\Http\FormRequest;

class AliExpressInventoryIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['sometimes', 'integer', 'exists:products,id'],
            'type' => ['sometimes', 'string', 'in:'.implode(',', array_map(fn ($t) => $t->value, InventoryTransactionType::cases()))],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
