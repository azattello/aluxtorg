<?php

namespace App\Http\Requests\Auth;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        if ($this->exists('email') && is_string($this->input('email'))) {
            $data['email'] = mb_strtolower(trim($this->input('email')));
        }
        if ($this->exists('phone') && is_string($this->input('phone'))) {
            $data['phone'] = PhoneNumber::normalize($this->input('phone'));
        }
        $this->merge($data);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => [
                'sometimes',
                'nullable',
                'string',
                'email:rfc',
                'max:254',
                Rule::unique('users', 'email')->ignore($this->user()->getKey()),
            ],
            'phone' => [
                'sometimes',
                'required',
                'string',
                'regex:/^\+[1-9]\d{9,14}$/',
                Rule::unique('users', 'phone')->ignore($this->user()->getKey()),
            ],
        ];
    }
}
