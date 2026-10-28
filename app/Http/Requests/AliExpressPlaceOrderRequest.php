<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AliExpressPlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Optional explicit SKU mapping for multi-variant listings:
            // sku_attr is the AliExpress "property id:value id#label" string
            // (e.g. "14:350853#Black;5:361386"), as exposed on the listing's
            // product_variants entries.
            'sku_map' => ['sometimes', 'array'],
            'sku_map.*.order_item_id' => ['required', 'integer', 'exists:order_items,id'],
            'sku_map.*.sku_attr' => ['required', 'string', 'max:500'],
        ];
    }
}
