<?php

namespace App\Http\Requests\Auth;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $identifier = $this->input('email');
        $this->merge([
            'email' => is_string($identifier)
                ? (str_contains($identifier, '@') ? mb_strtolower(trim($identifier)) : PhoneNumber::normalize($identifier))
                : $identifier,
        ]);
    }

    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'string',
                'max:254',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (str_contains((string) $value, '@')) {
                        if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $fail('Введите корректную электронную почту или номер телефона.');
                        }

                        return;
                    }

                    if (! PhoneNumber::isValid((string) $value)) {
                        $fail('Введите корректный номер телефона.');
                    }
                },
            ],
            'password' => ['required', 'string'],
        ];
    }
}
