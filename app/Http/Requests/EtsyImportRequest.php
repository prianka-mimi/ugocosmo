<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EtsyImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Accept either listing_ids[] (numeric Etsy listing IDs) or
            // listing_urls[] (full etsy.com listing URLs).
            'listing_ids' => ['sometimes', 'array', 'min:1', 'max:100'],
            'listing_ids.*' => ['required_with:listing_ids', 'integer', 'min:1'],
            'listing_urls' => ['sometimes', 'array', 'min:1', 'max:100'],
            'listing_urls.*' => ['required_with:listing_urls', 'string', 'url'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:product_categories,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $hasIds = ! empty($this->input('listing_ids'));
            $hasUrls = ! empty($this->input('listing_urls'));

            if (! $hasIds && ! $hasUrls) {
                $v->errors()->add('listing_ids', 'Provide either listing_ids or listing_urls.');
            }
        });
    }

    /**
     * Numeric Etsy listing IDs parsed from both input shapes.
     *
     * @return list<int>
     */
    public function resolvedListingIds(): array
    {
        $ids = [];

        foreach ((array) $this->input('listing_urls', []) as $url) {
            $id = self::extractListingId((string) $url);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        foreach ((array) $this->input('listing_ids', []) as $rawId) {
            $id = self::extractListingId((string) $rawId);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Extract a numeric listing ID from a raw ID or an etsy.com listing URL
     * (e.g. https://www.etsy.com/listing/123456789/some-title).
     */
    public static function extractListingId(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        if (preg_match('#/listing/(\d+)#i', $value, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
