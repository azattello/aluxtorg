<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'slug' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')],
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:20000'],
            'is_published' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'meta_title' => ['nullable', 'string', 'max:160'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'variants' => ['required', 'array', 'min:1', 'max:100'],
            'variants.*.sku' => ['required', 'string', 'max:80', Rule::unique('product_variants', 'sku')],
            'variants.*.name' => ['required', 'string', 'max:120'],
            'variants.*.sale_unit' => ['required', 'string', 'max:32'],
            'variants.*.attributes' => ['nullable', 'array', 'max:30'],
            'variants.*.attributes.*' => ['string', 'max:120'],
            'variants.*.price_kzt' => ['required', 'integer', 'min:0'],
            'variants.*.is_active' => ['required', 'boolean'],
            'variants.*.is_returnable' => ['required', 'boolean'],
            'variants.*.sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
