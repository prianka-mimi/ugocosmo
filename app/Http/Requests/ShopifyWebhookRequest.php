<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShopifyWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
