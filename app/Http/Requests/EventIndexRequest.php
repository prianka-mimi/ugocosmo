<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventIndexRequest extends FormRequest
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
            'upcoming' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'min_rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'tag' => ['nullable', 'string', 'max:30'],
            'sort' => ['nullable', 'string', 'in:latest,oldest,newest,date_asc,date_desc,price_asc,price_desc,price_low_high,price_high_low,highest_rated,rating_desc,most_popular,popular'],
        ];
    }
}
