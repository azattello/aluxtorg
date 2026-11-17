<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        $unitPrice = fake()->numberBetween(100, 100000);
        $quantity = fake()->numberBetween(1, 10);

        return [
            'order_id' => Order::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'product_name_snapshot' => fake()->words(3, true),
            'variant_name_snapshot' => 'Default',
            'sku_snapshot' => strtoupper(fake()->unique()->bothify('SKU-########')),
            'variant_attributes_snapshot' => [],
            'unit_price_kzt' => $unitPrice,
            'quantity' => $quantity,
            'line_total_kzt' => $unitPrice * $quantity,
        ];
    }
}
