<?php

namespace App\Http\Requests\Catalog;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'placement' => ['sometimes', 'required', Rule::in(['home_hero', 'home_promo'])],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'alt_text' => ['sometimes', 'required', 'string', 'max:255'],
            'destination_url' => [
                'sometimes',
                'nullable',
                'string',
                'max:2048',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null) {
                        return;
                    }
                    if (! is_string($value)) {
                        $fail('Укажите относительный путь сайта или ссылку с протоколом HTTP/HTTPS.');

                        return;
                    }
                    if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
                        return;
                    }

                    $scheme = parse_url($value, PHP_URL_SCHEME);
                    if (! filter_var($value, FILTER_VALIDATE_URL) || ! in_array(strtolower((string) $scheme), ['http', 'https'], true)) {
                        $fail('Укажите относительный путь сайта или ссылку с протоколом HTTP/HTTPS.');
                    }
                },
            ],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
