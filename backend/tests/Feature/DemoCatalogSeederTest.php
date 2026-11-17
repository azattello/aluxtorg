<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Banner;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use Database\Seeders\DemoCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_catalog_seeder_is_repeatable_and_populates_public_products(): void
    {
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->assertSame(2, Category::query()->count());
        $this->assertSame(3, Product::query()->count());
        $this->assertSame(3, ProductImage::query()->count());
        $this->assertSame(5, ProductVariant::query()->count());
        $this->assertSame(5, StockBalance::query()->count());
        $this->assertSame(2, Banner::query()->count());
        $this->assertSame(1, Banner::query()->where('placement', 'home_hero')->where('is_active', true)->count());

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.is_published', true);
    }
}
