<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;
use App\Support\PhoneNumber;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->input('email')) ? mb_strtolower(trim($this->input('email'))) : $this->input('email'),
            'phone' => is_string($this->input('phone')) ? PhoneNumber::normalize($this->input('phone')) : $this->input('phone'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:254', Rule::unique('users', 'email')],
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{9,14}$/', Rule::unique('users', 'phone')],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }
}
