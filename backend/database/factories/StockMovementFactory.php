<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'order_id' => null,
            'order_item_id' => null,
            'actor_user_id' => null,
            'correction_of_movement_id' => null,
            'type' => 'receipt',
            'quantity_delta' => fake()->numberBetween(1, 100),
            'note' => null,
        ];
    }
}
