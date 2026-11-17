<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $product = $this->route('product');

        return [
            'category_id' => ['sometimes', 'required', 'integer', 'exists:categories,id'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')->ignore($product->id)],
            'name' => ['sometimes', 'required', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'is_published' => ['sometimes', 'required', 'boolean'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'variants' => ['sometimes', 'required', 'array', 'min:1', 'max:100'],
            'variants.*.id' => ['nullable', 'integer', 'distinct', 'exists:product_variants,id'],
            'variants.*.sku' => ['required_with:variants', 'string', 'max:80'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:120'],
            'variants.*.sale_unit' => ['required_with:variants', 'string', 'max:32'],
            'variants.*.attributes' => ['nullable', 'array', 'max:30'],
            'variants.*.attributes.*' => ['string', 'max:120'],
            'variants.*.price_kzt' => ['required_with:variants', 'integer', 'min:0'],
            'variants.*.is_active' => ['required_with:variants', 'boolean'],
            'variants.*.is_returnable' => ['required_with:variants', 'boolean'],
            'variants.*.sort_order' => ['required_with:variants', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
