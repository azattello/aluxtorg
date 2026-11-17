<?php

namespace App\Http\Requests\Catalog;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'placement' => ['required', Rule::in(['home_hero', 'home_promo'])],
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'alt_text' => ['required', 'string', 'max:255'],
            'destination_url' => [
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
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
