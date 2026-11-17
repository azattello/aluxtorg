<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DomainSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_order_and_inventory_relationships_are_available(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create();
        $variant = ProductVariant::factory()->for($product)->create();
        $order = Order::factory()->create();
        $item = OrderItem::factory()
            ->for($order)
            ->for($variant, 'variant')
            ->create(['product_name_snapshot' => $product->name]);
        $warehouse = Warehouse::factory()->create();
        $balance = StockBalance::factory()
            ->for($warehouse)
            ->for($variant, 'variant')
            ->create(['on_hand' => 12, 'reserved' => 3]);

        $this->assertTrue($category->products->contains($product));
        $this->assertTrue($product->variants->contains($variant));
        $this->assertSame($order->id, $item->order->id);
        $this->assertSame($variant->id, $item->variant->id);
        $this->assertSame($warehouse->id, $balance->warehouse->id);
        $this->assertSame($variant->id, $balance->variant->id);
    }

    public function test_order_item_snapshots_do_not_change_with_catalog_edits(): void
    {
        $variant = ProductVariant::factory()->create([
            'name' => 'Blue',
            'sku' => 'SKU-SNAPSHOT-1',
            'price_kzt' => 2500,
            'attributes' => ['color' => 'blue'],
        ]);
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->for($variant, 'variant')->create([
            'product_name_snapshot' => $variant->product->name,
            'variant_name_snapshot' => $variant->name,
            'sku_snapshot' => $variant->sku,
            'variant_attributes_snapshot' => $variant->attributes,
            'unit_price_kzt' => $variant->price_kzt,
            'quantity' => 2,
            'line_total_kzt' => 5000,
        ]);

        $variant->update([
            'name' => 'Red',
            'sku' => 'SKU-SNAPSHOT-2',
            'price_kzt' => 4000,
            'attributes' => ['color' => 'red'],
        ]);

        $item->refresh();
        $this->assertSame('Blue', $item->variant_name_snapshot);
        $this->assertSame('SKU-SNAPSHOT-1', $item->sku_snapshot);
        $this->assertSame(2500, $item->unit_price_kzt);
        $this->assertSame(['color' => 'blue'], $item->variant_attributes_snapshot);
        $this->assertSame(5000, $item->line_total_kzt);
    }

    public function test_deleting_a_catalog_variant_preserves_order_item_snapshots(): void
    {
        $variant = ProductVariant::factory()->create();
        $item = OrderItem::factory()->for($variant, 'variant')->create();

        $variant->delete();

        $item->refresh();
        $this->assertNull($item->product_variant_id);
        $this->assertNotEmpty($item->product_name_snapshot);
        $this->assertNotEmpty($item->sku_snapshot);
    }

    public function test_delivery_orders_can_wait_for_a_manager_confirmed_fee(): void
    {
        $order = Order::factory()->delivery()->create();

        $this->assertSame('delivery', $order->fulfillment_method);
        $this->assertNull($order->delivery_fee_kzt);
        $this->assertNull($order->total_kzt);
        $this->assertNotEmpty($order->delivery_city);
        $this->assertNotEmpty($order->delivery_street);
        $this->assertNotEmpty($order->delivery_building);
    }

    public function test_audit_entries_survive_actor_deletion_without_storing_actor_data(): void
    {
        $actor = User::factory()->create();
        $entry = AuditLog::factory()->for($actor, 'actor')->create([
            'event_key' => 'catalog.product.created',
            'metadata' => ['product_id' => 42],
        ]);

        $actor->delete();

        $entry->refresh();
        $this->assertNull($entry->actor_user_id);
        $this->assertSame(['product_id' => 42], $entry->metadata);
    }

    public function test_order_statuses_follow_the_approved_lifecycle(): void
    {
        $this->assertTrue(OrderStatus::New->canTransitionTo(OrderStatus::Confirmed));
        $this->assertTrue(OrderStatus::Preparing->canTransitionTo(OrderStatus::Shipped));
        $this->assertTrue(OrderStatus::Shipped->canTransitionTo(OrderStatus::Cancelled));
        $this->assertFalse(OrderStatus::Completed->canTransitionTo(OrderStatus::Cancelled));
        $this->assertFalse(OrderStatus::Cancelled->canTransitionTo(OrderStatus::Confirmed));
    }

    public function test_payment_statuses_remain_separate_and_follow_the_approved_lifecycle(): void
    {
        $this->assertTrue(PaymentStatus::Unpaid->canTransitionTo(PaymentStatus::Paid));
        $this->assertTrue(PaymentStatus::Paid->canTransitionTo(PaymentStatus::PartiallyRefunded));
        $this->assertTrue(PaymentStatus::PartiallyRefunded->canTransitionTo(PaymentStatus::Refunded));
        $this->assertFalse(PaymentStatus::Unpaid->canTransitionTo(PaymentStatus::Refunded));
    }

    public function test_postgres_rejects_negative_variant_prices(): void
    {
        $this->requirePostgresConstraints();

        $this->expectException(QueryException::class);
        ProductVariant::factory()->create(['price_kzt' => -1]);
    }

    public function test_postgres_rejects_invalid_order_statuses(): void
    {
        $this->requirePostgresConstraints();

        $this->expectException(QueryException::class);
        DB::table('orders')->insert([
            'number' => 'ORD-INVALID-STATUS',
            'order_status' => 'unknown',
            'payment_status' => 'unpaid',
            'fulfillment_method' => 'pickup',
            'customer_name' => 'Test Customer',
            'customer_phone' => '+77010000000',
            'subtotal_kzt' => 0,
            'delivery_fee_kzt' => 0,
            'total_kzt' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_postgres_rejects_inconsistent_order_item_totals(): void
    {
        $this->requirePostgresConstraints();

        $this->expectException(QueryException::class);
        OrderItem::factory()->create([
            'unit_price_kzt' => 1000,
            'quantity' => 2,
            'line_total_kzt' => 1000,
        ]);
    }

    public function test_postgres_rejects_reserved_stock_above_on_hand(): void
    {
        $this->requirePostgresConstraints();

        $this->expectException(QueryException::class);
        StockBalance::factory()->create([
            'on_hand' => 2,
            'reserved' => 3,
        ]);
    }

    private function requirePostgresConstraints(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL check constraints are exercised in the PostgreSQL test run.');
        }
    }
}
