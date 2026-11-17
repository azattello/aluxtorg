<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_includes_only_active_categories_and_published_products(): void
    {
        config(['app.frontend_url' => 'https://shop.example']);
        $activeCategory = Category::factory()->create([
            'is_active' => true,
            'slug' => 'active-category',
        ]);
        $inactiveCategory = Category::factory()->create([
            'is_active' => false,
            'slug' => 'hidden-category',
        ]);
        Product::factory()->for($activeCategory)->create([
            'is_published' => true,
            'slug' => 'published-item',
        ]);
        Product::factory()->for($activeCategory)->create([
            'is_published' => false,
            'slug' => 'draft-item',
        ]);
        Product::factory()->for($inactiveCategory)->create([
            'is_published' => true,
            'slug' => 'hidden-category-item',
        ]);

        $response = $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $this->assertStringContainsString('https://shop.example/', $response->getContent());
        $this->assertStringContainsString('https://shop.example/categories/active-category', $response->getContent());
        $this->assertStringContainsString('https://shop.example/products/published-item', $response->getContent());
        $this->assertStringNotContainsString('hidden-category', $response->getContent());
        $this->assertStringNotContainsString('draft-item', $response->getContent());
    }

    public function test_robots_txt_excludes_private_routes_and_points_to_frontend_sitemap(): void
    {
        config(['app.frontend_url' => 'https://shop.example']);
        $this->assertFileDoesNotExist(public_path('robots.txt'));

        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Disallow: /admin', false)
            ->assertSee('Disallow: /account', false)
            ->assertSee('Disallow: /checkout', false)
            ->assertSee('Sitemap: https://shop.example/sitemap.xml', false);
    }
}
