<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockBalance>
 */
class StockBalanceFactory extends Factory
{
    public function definition(): array
    {
        $onHand = fake()->numberBetween(0, 100);

        return [
            'warehouse_id' => Warehouse::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'on_hand' => $onHand,
            'reserved' => fake()->numberBetween(0, $onHand),
        ];
    }
}
