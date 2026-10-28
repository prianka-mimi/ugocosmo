<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShopifySelectImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Array of selected Shopify product IDs (optional if using filters)
            'product_ids' => ['sometimes', 'array', 'min:1', 'max:250'],
            'product_ids.*' => ['required', 'string', 'max:50'],

            // ─── Collection-based import ────────────────────────────
            'collection_ids' => ['nullable', 'array', 'max:20'],
            'collection_ids.*' => ['required', 'string', 'max:50'],

            // ─── Tag-based import ───────────────────────────────────
            'tags' => ['nullable', 'array', 'max:50'],
            'tags.*' => ['required', 'string', 'max:100'],

            // ─── Status filtering ────────────────────────────────────
            'status_filter' => ['nullable', 'string', 'in:active,draft,archived,any'],

            // ─── Incremental import (only products updated since) ────
            'updated_since' => ['nullable', 'date'],

            // ─── Import all products from the store ─────────────────
            'import_all' => ['nullable', 'boolean'],

            // ─── Sync Policy Overrides (per-import, overrides store defaults) ─
            'price_sync_policy' => ['nullable', 'string', 'in:shopify_always,shopify_never,shopify_if_empty,manual'],
            'image_sync_strategy' => ['nullable', 'string', 'in:always,new_only,skip'],

            // Optional defaults
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'default_condition' => ['nullable', 'string', 'in:new,used,refurbished'],
            'default_currency' => ['nullable', 'string', 'max:10'],
            'download_images' => ['nullable', 'boolean'],

            // Pagination for rest of products (if importing all)
            'limit' => ['nullable', 'integer', 'min:1', 'max:250'],
            'since_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $data = $this->all();

            // Must have at least one selection method
            $hasProductIds = ! empty($data['product_ids']);
            $hasCollectionIds = ! empty($data['collection_ids']);
            $hasTags = ! empty($data['tags']);
            $hasImportAll = ! empty($data['import_all']);

            if (! $hasProductIds && ! $hasCollectionIds && ! $hasTags && ! $hasImportAll) {
                $validator->errors()->add(
                    'selection',
                    'You must provide at least one selection method: product_ids, collection_ids, tags, or import_all.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'product_ids.required' => 'Please select at least one product to import.',
            'product_ids.min' => 'Please select at least one product to import.',
            'product_ids.max' => 'You can import up to 250 products at a time.',
        ];
    }
}
