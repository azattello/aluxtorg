<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StoreSettingsAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_store_settings_are_blank_until_configured_by_an_administrator(): void
    {
        $this->getJson('/api/v1/store-settings')
            ->assertOk()
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.whatsapp', null)
            ->assertJsonPath('data.email', null);

        $this->putJson('/api/v1/admin/store-settings', ['phone' => '+77010000000'])
            ->assertUnauthorized();

        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $settings = [
            'phone' => '+77010000000',
            'whatsapp' => 'https://wa.me/77010000000',
            'email' => 'info@aluxtorg.kz',
            'address' => 'Алматы',
            'working_hours' => 'Пн–Пт, 09:00–18:00',
            'instagram_url' => 'https://instagram.com/aluxtorg',
            'telegram_url' => 'https://t.me/aluxtorg',
        ];

        $this->actingAs($administrator)
            ->putJson('/api/v1/admin/store-settings', $settings)
            ->assertOk()
            ->assertJsonPath('data.email', 'info@aluxtorg.kz');

        $this->getJson('/api/v1/store-settings')
            ->assertOk()
            ->assertJsonPath('data.phone', '+77010000000')
            ->assertJsonPath('data.address', 'Алматы');
    }

    public function test_storefront_views_are_aggregated_without_storing_visitor_identifiers(): void
    {
        $category = Category::query()->create([
            'name' => 'Тестовая категория',
            'slug' => 'test-category',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'slug' => 'test-product',
            'name' => 'Тестовый товар',
            'is_published' => true,
        ]);
        $payload = ['page_type' => 'product', 'resource_key' => $product->slug];

        $this->postJson('/api/v1/storefront/views', $payload)->assertCreated();
        $this->postJson('/api/v1/storefront/views', $payload)->assertCreated();

        $this->assertDatabaseHas('storefront_daily_views', [
            'view_date' => now()->toDateString(),
            'page_type' => 'product',
            'resource_key' => 'test-product',
            'views' => 2,
        ]);
        $this->assertNotContains('ip_address', Schema::getColumnListing('storefront_daily_views'));
        $this->assertNotContains('visitor_id', Schema::getColumnListing('storefront_daily_views'));

        $this->postJson('/api/v1/storefront/views', ['page_type' => 'product', 'resource_key' => 'not-published'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.resource_key.0', 'Товар не найден.');
    }

    public function test_analytics_are_restricted_and_include_order_and_view_summaries(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Manager]))
            ->getJson('/api/v1/admin/analytics')
            ->assertForbidden();

        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        Order::query()->create([
            'number' => 'ALT-STAT-001',
            'order_status' => OrderStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
            'fulfillment_method' => 'delivery',
            'customer_name' => 'Тестовый покупатель',
            'customer_phone' => '+77010000000',
            'delivery_city' => 'Алматы',
            'subtotal_kzt' => 10000,
            'delivery_fee_kzt' => 500,
            'total_kzt' => 10500,
        ]);
        $this->postJson('/api/v1/storefront/views', ['page_type' => 'home', 'resource_key' => 'home']);

        $this->actingAs($administrator)
            ->getJson('/api/v1/admin/analytics')
            ->assertOk()
            ->assertJsonPath('data.summary.orders_count', 1)
            ->assertJsonPath('data.summary.order_value_kzt', 10500)
            ->assertJsonPath('data.summary.paid_orders_count', 1)
            ->assertJsonPath('data.summary.buyers_count', 1)
            ->assertJsonPath('data.summary.page_views', 1)
            ->assertJsonPath('data.recent_orders.0.customer_name', 'Тестовый покупатель')
            ->assertJsonPath('data.orders_by_city.0.city', 'Алматы');
    }
}
