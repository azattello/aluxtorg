<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
{
    public function definition(): array
    {
        $publicId = 'products/'.Str::uuid();

        return [
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'cloudinary_public_id' => $publicId,
            'secure_url' => 'https://res.cloudinary.com/demo/image/upload/'.$publicId.'.jpg',
            'alt_text' => fake()->optional()->sentence(4),
            'sort_order' => 0,
        ];
    }
}
