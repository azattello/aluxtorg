<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_catalog_excludes_unpublished_products_and_inactive_categories(): void
    {
        $category = Category::factory()->create();
        $visible = Product::factory()->create(['category_id' => $category->id, 'is_published' => true]);
        ProductVariant::factory()->create(['product_id' => $visible->id, 'sku' => 'VISIBLE-01']);
        $draft = Product::factory()->create(['category_id' => $category->id, 'is_published' => false]);
        ProductVariant::factory()->create(['product_id' => $draft->id, 'sku' => 'DRAFT-01']);
        $hiddenCategory = Category::factory()->create(['is_active' => false]);
        $hidden = Product::factory()->create(['category_id' => $hiddenCategory->id, 'is_published' => true]);
        ProductVariant::factory()->create(['product_id' => $hidden->id, 'sku' => 'HIDDEN-01']);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $visible->slug);

        $this->getJson("/api/v1/products/{$draft->slug}")->assertNotFound();
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_catalog_search_filters_and_sorting_are_server_side(): void
    {
        $category = Category::factory()->create();
        $cheap = Product::factory()->create(['category_id' => $category->id, 'is_published' => true, 'name' => 'Blue fabric']);
        ProductVariant::factory()->create(['product_id' => $cheap->id, 'sku' => 'FABRIC-100', 'price_kzt' => 1000]);
        $expensive = Product::factory()->create(['category_id' => $category->id, 'is_published' => true, 'name' => 'Green fabric']);
        ProductVariant::factory()->create(['product_id' => $expensive->id, 'sku' => 'FABRIC-200', 'price_kzt' => 5000]);

        $this->getJson('/api/v1/products?q=FABRIC-200&sort=price_asc')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expensive->id);

        $this->getJson('/api/v1/products?min_price=4000&sort=price_desc')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expensive->id);

        $this->getJson('/api/v1/products?sort=unknown')->assertUnprocessable();
        $this->getJson('/api/v1/products?min_price=9000&max_price=100')
            ->assertUnprocessable()
            ->assertJsonPath('errors.max_price.0', 'Значение поля максимальная цена должно быть не меньше 9000.');
    }

    public function test_selecting_a_parent_category_includes_products_in_active_descendants(): void
    {
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $product = Product::factory()->create(['category_id' => $child->id, 'is_published' => true]);
        ProductVariant::factory()->create(['product_id' => $product->id]);

        $this->getJson("/api/v1/products?category_id={$parent->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $product->id);
    }

    public function test_product_detail_shows_prices_availability_and_seo_metadata(): void
    {
        $product = Product::factory()->create([
            'is_published' => true,
            'meta_title' => 'SEO title',
            'meta_description' => 'SEO description',
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price_kzt' => 12900,
            'is_active' => true,
        ]);
        StockBalance::factory()->create([
            'product_variant_id' => $variant->id,
            'on_hand' => 5,
            'reserved' => 2,
        ]);

        $this->getJson("/api/v1/products/{$product->slug}")
            ->assertOk()
            ->assertJsonPath('data.min_price_kzt', 12900)
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.meta_title', 'SEO title')
            ->assertJsonPath('data.variants.0.available', true)
            ->assertJsonPath('data.variants.0.available_quantity', null);
    }

    public function test_availability_filter_accepts_query_boolean_values(): void
    {
        $inStock = Product::factory()->create(['is_published' => true]);
        $availableVariant = ProductVariant::factory()->create(['product_id' => $inStock->id]);
        StockBalance::factory()->create([
            'product_variant_id' => $availableVariant->id,
            'on_hand' => 3,
            'reserved' => 0,
        ]);
        $outOfStock = Product::factory()->create(['is_published' => true]);
        ProductVariant::factory()->create(['product_id' => $outOfStock->id]);

        $this->getJson('/api/v1/products?available=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inStock->id);

        $this->getJson('/api/v1/products?available=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $outOfStock->id);
    }

    public function test_catalog_management_requires_a_catalog_role(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)
            ->postJson('/api/v1/admin/categories', [])
            ->assertForbidden();

        $this->getJson('/api/v1/admin/products')->assertForbidden();
    }

    public function test_admin_product_list_supports_search_status_and_allowlisted_sorting(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $category = Category::factory()->create();
        $published = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Blue cotton',
            'is_published' => true,
        ]);
        ProductVariant::factory()->create(['product_id' => $published->id, 'sku' => 'BLUE-COTTON-01']);
        $draft = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Red cotton',
            'is_published' => false,
        ]);
        ProductVariant::factory()->create(['product_id' => $draft->id, 'sku' => 'RED-COTTON-01']);

        $this->actingAs($manager)
            ->getJson('/api/v1/admin/products?search=RED-COTTON&status=draft&sort=name_asc')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $draft->id);

        $this->getJson('/api/v1/admin/products?sort=not-a-valid-sort')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort']);
    }

    public function test_admin_can_create_category_and_cannot_create_category_cycles(): void
    {
        $manager = $this->userWithRole(UserRole::Manager);
        $root = Category::factory()->create(['name' => 'Root']);
        $child = Category::factory()->create(['parent_id' => $root->id]);

        $this->actingAs($manager)
            ->postJson('/api/v1/admin/categories', [
                'name' => 'New category',
                'slug' => 'new-category',
                'is_active' => true,
                'sort_order' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'new-category');

        $this->patchJson("/api/v1/admin/categories/{$root->id}", ['parent_id' => $child->id])
            ->assertUnprocessable();
        $this->assertNull($root->fresh()->parent_id);
    }

    public function test_admin_product_creation_enforces_unique_sku_and_archives_instead_of_deleting(): void
    {
        $administrator = $this->userWithRole(UserRole::Administrator);
        $category = Category::factory()->create();
        ProductVariant::factory()->create(['sku' => 'EXISTING-SKU']);

        $this->actingAs($administrator)
            ->postJson('/api/v1/admin/products', $this->productData($category->id, 'EXISTING-SKU'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variants.0.sku']);

        $created = $this->postJson('/api/v1/admin/products', $this->productData($category->id, 'NEW-SKU'))
            ->assertCreated()
            ->assertJsonPath('data.is_published', true);
        $id = $created->json('data.id');

        $this->getJson('/api/v1/products?q=NEW-SKU')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/products/catalog-item')
            ->assertOk()
            ->assertJsonPath('data.name', 'Catalog item');

        $this->deleteJson("/api/v1/admin/products/{$id}")
            ->assertOk();
        $this->assertDatabaseHas('products', ['id' => $id, 'is_published' => false]);
        $this->assertDatabaseHas('product_variants', ['product_id' => $id, 'is_active' => false]);
    }

    public function test_published_product_requires_an_active_variant_and_updates_cannot_mutate_another_products_variant(): void
    {
        $manager = $this->userWithRole(UserRole::Manager);
        $category = Category::factory()->create();
        $payload = $this->productData($category->id, 'ACTIVE-01');
        $payload['variants'][0]['is_active'] = false;

        $this->actingAs($manager)
            ->postJson('/api/v1/admin/products', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variants']);

        $product = Product::factory()->create();
        $ownVariant = ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'OWN-01']);
        $otherProduct = Product::factory()->create();
        $otherVariant = ProductVariant::factory()->create(['product_id' => $otherProduct->id, 'sku' => 'OTHER-01']);

        $this->patchJson("/api/v1/admin/products/{$product->id}", [
            'variants' => [[
                'id' => $otherVariant->id,
                'sku' => 'OTHER-01',
                'name' => 'Default',
                'sale_unit' => 'piece',
                'attributes' => [],
                'price_kzt' => 100,
                'is_active' => true,
                'is_returnable' => false,
                'sort_order' => 0,
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['variants.0.id']);

        $this->patchJson("/api/v1/admin/products/{$product->id}", [
            'variants' => [[
                'id' => $ownVariant->id,
                'sku' => 'OWN-01',
                'name' => 'Default',
                'sale_unit' => 'piece',
                'attributes' => [],
                'price_kzt' => 250,
                'is_active' => false,
                'is_returnable' => false,
                'sort_order' => 0,
            ]],
        ])->assertOk();
        $this->assertFalse($ownVariant->fresh()->is_active);
    }

    public function test_cloudinary_image_upload_is_server_signed_and_attached_only_to_its_product(): void
    {
        config([
            'services.cloudinary.cloud_name' => 'test-cloud',
            'services.cloudinary.api_key' => 'test-key',
            'services.cloudinary.api_secret' => 'test-secret',
            'services.cloudinary.folder' => 'ecommerce',
        ]);
        Http::fake([
            'https://api.cloudinary.com/v1_1/test-cloud/image/upload' => Http::response([
                'public_id' => 'ecommerce/products/image-1',
                'secure_url' => 'https://res.cloudinary.com/test-cloud/image/upload/image-1.webp',
                'format' => 'webp',
                'width' => 1200,
                'height' => 800,
                'bytes' => 24000,
            ]),
        ]);
        $administrator = $this->userWithRole(UserRole::Administrator);
        $product = Product::factory()->create();
        $foreignVariant = ProductVariant::factory()->create();

        $this->actingAs($administrator)
            ->post('/api/v1/admin/products/'.$product->id.'/images', [
                'image' => UploadedFile::fake()->image('catalog.webp'),
                'alt_text' => 'Product image',
                'product_variant_id' => $foreignVariant->id,
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $this->post('/api/v1/admin/products/'.$product->id.'/images', [
            'image' => UploadedFile::fake()->image('catalog.webp'),
            'alt_text' => 'Product image',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.width', 1200);

        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'cloudinary_public_id' => 'ecommerce/products/image-1',
            'format' => 'webp',
        ]);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/image/upload')
            && str_contains($request->body(), 'name="signature"')
            && str_contains($request->body(), 'name="api_key"'));
    }

    public function test_cloudinary_upload_returns_an_explicit_error_when_server_credentials_are_missing(): void
    {
        config([
            'services.cloudinary.cloud_name' => null,
            'services.cloudinary.api_key' => null,
            'services.cloudinary.api_secret' => null,
        ]);
        $administrator = $this->userWithRole(UserRole::Administrator);
        $product = Product::factory()->create();

        $this->actingAs($administrator)
            ->post('/api/v1/admin/products/'.$product->id.'/images', [
                'image' => UploadedFile::fake()->image('catalog.jpg'),
                'alt_text' => 'Product image',
            ], ['Accept' => 'application/json'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Загрузка изображений временно недоступна: Cloudinary не настроен.');

        $this->assertSame(0, ProductImage::query()->count());
    }

    public function test_public_banners_only_include_active_banners_within_schedule(): void
    {
        $active = Banner::query()->create([
            'title' => 'Spring',
            'placement' => 'home_hero',
            'cloudinary_public_id' => 'banners/spring',
            'secure_url' => 'https://res.cloudinary.com/demo/spring.webp',
            'alt_text' => 'Spring banner',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
            'sort_order' => 0,
        ]);
        Banner::query()->create([
            'title' => 'Expired',
            'placement' => 'home_hero',
            'cloudinary_public_id' => 'banners/expired',
            'secure_url' => 'https://res.cloudinary.com/demo/expired.webp',
            'alt_text' => 'Expired banner',
            'ends_at' => now()->subMinute(),
            'is_active' => true,
            'sort_order' => 1,
        ]);
        Banner::query()->create([
            'title' => 'Draft',
            'placement' => 'home_hero',
            'cloudinary_public_id' => 'banners/draft',
            'secure_url' => 'https://res.cloudinary.com/demo/draft.webp',
            'alt_text' => 'Draft banner',
            'is_active' => false,
            'sort_order' => 2,
        ]);

        $this->getJson('/api/v1/banners')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);
    }

    public function test_admin_can_schedule_banner_with_safe_internal_url_and_reject_script_urls(): void
    {
        config([
            'services.cloudinary.cloud_name' => 'test-cloud',
            'services.cloudinary.api_key' => 'test-key',
            'services.cloudinary.api_secret' => 'test-secret',
            'services.cloudinary.folder' => 'ecommerce',
        ]);
        Http::fake([
            'https://api.cloudinary.com/v1_1/test-cloud/image/upload' => Http::response([
                'public_id' => 'ecommerce/banners/sale',
                'secure_url' => 'https://res.cloudinary.com/test-cloud/image/upload/sale.webp',
                'format' => 'webp',
                'width' => 1200,
                'height' => 500,
                'bytes' => 10000,
            ]),
        ]);
        $administrator = $this->userWithRole(UserRole::Administrator);

        $createdBanner = $this->actingAs($administrator)
            ->post('/api/v1/admin/banners', [
                'title' => 'Sale',
                'placement' => 'home_hero',
                'image' => UploadedFile::fake()->image('sale.webp'),
                'alt_text' => 'Sale banner',
                'destination_url' => '/products/sale',
                'starts_at' => now()->toIso8601String(),
                'ends_at' => now()->addDay()->toIso8601String(),
                'is_active' => '1',
                'sort_order' => '0',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.destination_url', '/products/sale');

        $bannerId = $createdBanner->json('data.id');
        $this->assertDatabaseHas('banners', [
            'title' => 'Sale',
            'cloudinary_public_id' => 'ecommerce/banners/sale',
            'width' => 1200,
        ]);

        $this->post('/api/v1/admin/banners', [
            'title' => 'Bad link',
            'placement' => 'home_hero',
            'image' => UploadedFile::fake()->image('bad.webp'),
            'alt_text' => 'Bad banner',
            'destination_url' => 'javascript:alert(1)',
            'is_active' => '0',
            'sort_order' => '0',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['destination_url']);

        $this->patchJson("/api/v1/admin/banners/{$bannerId}", [
            'starts_at' => now()->addDays(2)->toIso8601String(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at']);

        $this->post('/api/v1/admin/banners', [
            'title' => 'Array URL',
            'placement' => 'home_hero',
            'image' => UploadedFile::fake()->image('bad.webp'),
            'alt_text' => 'Bad banner',
            'destination_url' => ['https://example.test'],
            'is_active' => '0',
            'sort_order' => '0',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['destination_url']);
    }

    private function productData(int $categoryId, string $sku): array
    {
        return [
            'category_id' => $categoryId,
            'slug' => 'catalog-item',
            'name' => 'Catalog item',
            'description' => 'Product description',
            'is_published' => true,
            'sort_order' => 0,
            'variants' => [[
                'sku' => $sku,
                'name' => 'Default',
                'sale_unit' => 'piece',
                'attributes' => ['color' => 'green'],
                'price_kzt' => 1500,
                'is_active' => true,
                'is_returnable' => false,
                'sort_order' => 0,
            ]],
        ];
    }

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }
}
