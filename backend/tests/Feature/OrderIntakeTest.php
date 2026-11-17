<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderIntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_customer_can_create_delivery_order_with_server_calculated_prices_and_snapshots(): void
    {
        $customer = User::factory()->create();
        $variant = $this->availableVariant(price: 3200, quantity: 5);

        $response = $this->actingAs($customer)
            ->withHeader('Idempotency-Key', '6f80b64d-fc6e-4e31-bd86-4adbe9416f8f')
            ->postJson('/api/v1/orders', [
                ...$this->orderData($variant->id, 2, 'delivery'),
                'unit_price_kzt' => 1,
                'subtotal_kzt' => 1,
                'total_kzt' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.order_status', OrderStatus::New->value)
            ->assertJsonPath('data.payment_status', PaymentStatus::Unpaid->value)
            ->assertJsonPath('data.subtotal_kzt', 6400)
            ->assertJsonPath('data.delivery_fee_kzt', null)
            ->assertJsonPath('data.total_kzt', null)
            ->assertJsonPath('data.items.0.unit_price_kzt', 3200)
            ->assertJsonPath('data.items.0.line_total_kzt', 6400);

        $orderId = $response->json('data.id');
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'user_id' => $customer->id,
            'delivery_city' => 'Алматы',
            'delivery_street' => 'Абая',
            'delivery_building' => '10',
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'product_name_snapshot' => $variant->product->name,
            'sku_snapshot' => $variant->sku,
            'unit_price_kzt' => 3200,
            'quantity' => 2,
            'line_total_kzt' => 6400,
        ]);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $orderId,
            'from_status' => null,
            'to_status' => OrderStatus::New->value,
        ]);
        $this->assertSame(0, $variant->stockBalances()->firstOrFail()->reserved);
    }

    public function test_pickup_order_has_zero_delivery_fee_and_server_calculated_total(): void
    {
        $this->actingAs(User::factory()->create());
        $variant = $this->availableVariant(price: 2500, quantity: 3);

        $this->withHeader('Idempotency-Key', 'e6018703-5b13-46a1-9ae0-cf6411c4be7f')
            ->postJson('/api/v1/orders', $this->orderData($variant->id, 2, 'pickup'))
            ->assertCreated()
            ->assertJsonPath('data.fulfillment_method', 'pickup')
            ->assertJsonPath('data.subtotal_kzt', 5000)
            ->assertJsonPath('data.delivery_fee_kzt', 0)
            ->assertJsonPath('data.total_kzt', 5000);
    }

    public function test_checkout_rejects_unavailable_or_unpublished_items_without_creating_an_order(): void
    {
        $this->actingAs(User::factory()->create());
        $variant = $this->availableVariant(price: 1200, quantity: 1);
        $payload = $this->orderData($variant->id, 2, 'pickup');

        $this->withHeader('Idempotency-Key', '0e776cec-ad7e-4772-bfa3-1b2ce9b430d5')
            ->postJson('/api/v1/orders', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        $variant->product->update(['is_published' => false]);
        $this->withHeader('Idempotency-Key', '6a68c5d3-c344-452f-b9b4-68181507798a')
            ->postJson('/api/v1/orders', $this->orderData($variant->id, 1, 'pickup'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        $this->assertSame(0, Order::query()->count());
    }

    public function test_idempotency_returns_the_same_order_and_rejects_key_reuse_with_different_data(): void
    {
        $this->actingAs(User::factory()->create());
        $variant = $this->availableVariant(price: 700, quantity: 10);
        $key = 'f7de56a9-fb1b-47aa-aa8b-1e1a3da0a4af';
        $payload = $this->orderData($variant->id, 1, 'pickup');

        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/orders', $payload)
            ->assertCreated();
        $number = $first->json('data.number');

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/orders', $payload)
            ->assertOk()
            ->assertJsonPath('data.number', $number);

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/orders', $this->orderData($variant->id, 2, 'pickup'))
            ->assertStatus(409);

        $this->assertSame(1, Order::query()->count());
    }

    public function test_guest_cannot_create_an_order(): void
    {
        $variant = $this->availableVariant(price: 900, quantity: 2);

        $this->withHeader('Idempotency-Key', '0e776cec-ad7e-4772-bfa3-1b2ce9b430d5')
            ->postJson('/api/v1/orders', $this->orderData($variant->id, 1, 'pickup'))
            ->assertUnauthorized();

        $this->assertSame(0, Order::query()->count());
    }

    public function test_customers_can_only_list_and_read_their_own_orders(): void
    {
        $variant = $this->availableVariant(price: 1700, quantity: 5);
        $owner = User::factory()->create();
        $otherCustomer = User::factory()->create();
        $this->getJson('/api/v1/orders')->assertUnauthorized();

        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', '93a1acb4-1ccd-4bbc-bb88-9a7e6aefde56')
            ->postJson('/api/v1/orders', $this->orderData($variant->id, 1, 'pickup'))
            ->assertCreated();
        $ownerOrder = Order::query()->where('user_id', $owner->id)->firstOrFail();
        $otherOrder = Order::factory()->create(['user_id' => $otherCustomer->id]);
        OrderStatusHistory::query()->create([
            'order_id' => $ownerOrder->id,
            'actor_user_id' => null,
            'from_status' => OrderStatus::New,
            'to_status' => OrderStatus::Confirmed,
            'note' => 'Internal-only transition note',
        ]);
        $ownerOrder->internalNotes()->create(['author_user_id' => null, 'body' => 'Internal-only note']);

        $this->actingAs($owner)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownerOrder->id);

        $this->getJson("/api/v1/orders/{$ownerOrder->id}")
            ->assertOk()
            ->assertJsonPath('data.status_history.1.to_status', OrderStatus::Confirmed->value)
            ->assertJsonMissing(['Internal-only transition note'])
            ->assertJsonMissing(['Internal-only note'])
            ->assertJsonMissingPath('data.internal_notes');
        $this->getJson("/api/v1/orders/{$otherOrder->id}")->assertNotFound();
    }

    private function availableVariant(int $price, int $quantity): ProductVariant
    {
        $category = Category::factory()->create(['is_active' => true]);
        $product = Product::factory()->for($category)->create(['is_published' => true]);
        $variant = ProductVariant::factory()->for($product)->create([
            'is_active' => true,
            'is_returnable' => true,
            'price_kzt' => $price,
        ]);
        $warehouse = Warehouse::query()->firstOrCreate(
            ['code' => 'main'],
            ['name' => 'Main warehouse', 'is_active' => true],
        );
        StockBalance::factory()->for($warehouse)->for($variant, 'variant')->create([
            'on_hand' => $quantity,
            'reserved' => 0,
        ]);

        return $variant->load('product');
    }

    private function orderData(int $variantId, int $quantity, string $fulfillmentMethod): array
    {
        return [
            'fulfillment_method' => $fulfillmentMethod,
            'customer_name' => 'Test Customer',
            'customer_phone' => '+77010000000',
            'customer_email' => null,
            'delivery_city' => $fulfillmentMethod === 'delivery' ? 'Алматы' : null,
            'delivery_street' => $fulfillmentMethod === 'delivery' ? 'Абая' : null,
            'delivery_building' => $fulfillmentMethod === 'delivery' ? '10' : null,
            'items' => [['variant_id' => $variantId, 'quantity' => $quantity]],
        ];
    }
}
