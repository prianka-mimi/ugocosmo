<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ServiceIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'category_id' => ['nullable', 'integer', 'exists:service_categories,id'],
            'category' => ['nullable'],
            'service_type' => ['nullable', 'string', 'in:online,in-person,hybrid'],
            'price_type' => ['nullable', 'string', 'in:fixed,hourly,daily,weekly,monthly'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'min_rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'tag' => ['nullable', 'string', 'max:30'],
            'is_insured' => ['nullable', 'boolean'],
            'is_certified' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'in:latest,oldest,newest,price_asc,price_desc,price_low_high,price_high_low,highest_rated,rating_desc,most_popular,popular'],
        ];
    }
}
