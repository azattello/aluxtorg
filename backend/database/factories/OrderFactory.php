<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        $subtotal = fake()->numberBetween(100, 500000);

        return [
            'user_id' => null,
            'number' => 'ORD-'.Str::upper(Str::random(12)),
            'order_status' => 'new',
            'payment_status' => 'unpaid',
            'fulfillment_method' => 'pickup',
            'customer_name' => fake()->name(),
            'customer_phone' => '+7701'.fake()->numerify('#######'),
            'customer_email' => fake()->optional()->safeEmail(),
            'delivery_city' => null,
            'delivery_street' => null,
            'delivery_building' => null,
            'delivery_apartment' => null,
            'delivery_instructions' => null,
            'subtotal_kzt' => $subtotal,
            'delivery_fee_kzt' => 0,
            'total_kzt' => $subtotal,
            'idempotency_key' => null,
        ];
    }

    public function delivery(): static
    {
        return $this->state(fn (): array => [
            'fulfillment_method' => 'delivery',
            'delivery_city' => fake()->city(),
            'delivery_street' => fake()->streetName(),
            'delivery_building' => fake()->buildingNumber(),
            'delivery_fee_kzt' => null,
            'total_kzt' => null,
        ]);
    }
}
