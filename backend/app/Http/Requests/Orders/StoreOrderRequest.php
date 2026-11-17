<?php

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return [
            ...$this->all(),
            'idempotency_key' => $this->header('Idempotency-Key'),
        ];
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'fulfillment_method' => ['required', Rule::in(['delivery', 'pickup'])],
            'customer_name' => ['required', 'string', 'max:160'],
            'customer_phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9()\-\s]{7,32}$/'],
            'customer_email' => ['nullable', 'email', 'max:254'],
            'customer_notifications_opted_in' => ['sometimes', 'boolean'],
            'delivery_city' => ['required_if:fulfillment_method,delivery', 'nullable', 'string', 'max:120'],
            'delivery_street' => ['required_if:fulfillment_method,delivery', 'nullable', 'string', 'max:180'],
            'delivery_building' => ['required_if:fulfillment_method,delivery', 'nullable', 'string', 'max:80'],
            'delivery_apartment' => ['nullable', 'string', 'max:80'],
            'delivery_instructions' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.variant_id' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    filter_var($this->input('customer_notifications_opted_in', false), FILTER_VALIDATE_BOOLEAN)
                    && blank($this->input('customer_email'))
                ) {
                    $validator->errors()->add(
                        'customer_notifications_opted_in',
                        'Для уведомлений о статусе укажите электронную почту.',
                    );
                }
            },
        ];
    }
}
