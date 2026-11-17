<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\SendLowStockTelegramAlert;
use App\Models\Category;
use App\Models\LowStockAlert;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ManagerOrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_confirm_and_reserve_stock_atomically(): void
    {
        [$order, $variant, $balance] = $this->orderWithStock(quantity: 5, ordered: 2);
        $manager = $this->userWithRole(UserRole::Manager);

        $this->actingAs($manager)
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", [
                'order_status' => OrderStatus::Confirmed->value,
                'note' => 'Наличие проверено',
            ])
            ->assertOk()
            ->assertJsonPath('data.order_status', OrderStatus::Confirmed->value)
            ->assertJsonPath('data.status_history.0.note', 'Наличие проверено')
            ->assertJsonPath('data.status_history.0.actor.id', $manager->id);

        $this->assertSame(2, $balance->fresh()->reserved);
        $this->assertDatabaseHas('stock_reservations', [
            'order_id' => $order->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
            'status' => 'active',
            'created_by' => $manager->id,
        ]);
        $this->assertNotNull($order->statusHistory()->where('to_status', OrderStatus::Confirmed->value)->value('created_at'));
    }

    public function test_insufficient_stock_rolls_back_order_transition_and_reservation(): void
    {
        [$order, , $balance] = $this->orderWithStock(quantity: 1, ordered: 2);
        $manager = $this->userWithRole(UserRole::Manager);

        $this->actingAs($manager)
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", ['order_status' => OrderStatus::Confirmed->value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order_status']);

        $this->assertSame(OrderStatus::New, $order->fresh()->order_status);
        $this->assertSame(0, $balance->fresh()->reserved);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('order_status_history', 0);
    }

    public function test_cancellation_releases_a_reservation_once_and_dispatch_consumes_it(): void
    {
        [$cancelledOrder, , $cancelledBalance] = $this->orderWithStock(quantity: 5, ordered: 2);
        [$shippedOrder, , $shippedBalance] = $this->orderWithStock(quantity: 5, ordered: 3);
        $manager = $this->userWithRole(UserRole::Manager);

        $this->actingAs($manager)->patchJson("/api/v1/admin/orders/{$cancelledOrder->id}/status", ['order_status' => 'confirmed'])->assertOk();
        $this->patchJson("/api/v1/admin/orders/{$cancelledOrder->id}/status", ['order_status' => 'cancelled'])->assertOk();
        $this->assertSame(0, $cancelledBalance->fresh()->reserved);
        $this->assertDatabaseHas('stock_reservations', ['order_id' => $cancelledOrder->id, 'status' => 'released']);
        $this->patchJson("/api/v1/admin/orders/{$cancelledOrder->id}/status", ['order_status' => 'cancelled'])->assertUnprocessable();
        $this->assertSame(0, $cancelledBalance->fresh()->reserved);

        $this->patchJson("/api/v1/admin/orders/{$shippedOrder->id}/status", ['order_status' => 'confirmed'])->assertOk();
        $this->patchJson("/api/v1/admin/orders/{$shippedOrder->id}/status", ['order_status' => 'preparing'])->assertOk();
        $this->patchJson("/api/v1/admin/orders/{$shippedOrder->id}/status", ['order_status' => 'shipped'])->assertOk();
        $this->assertSame(2, $shippedBalance->fresh()->on_hand);
        $this->assertSame(0, $shippedBalance->fresh()->reserved);
        $this->assertDatabaseHas('stock_reservations', ['order_id' => $shippedOrder->id, 'status' => 'consumed']);
        $this->assertDatabaseHas('stock_movements', [
            'order_id' => $shippedOrder->id,
            'type' => 'dispatch',
            'quantity_delta' => -3,
            'actor_user_id' => $manager->id,
        ]);

        $this->patchJson("/api/v1/admin/orders/{$shippedOrder->id}/status", ['order_status' => 'cancelled'])->assertOk();
        $this->assertSame(2, $shippedBalance->fresh()->on_hand);
        $this->assertSame(0, $shippedBalance->fresh()->reserved);
        $this->assertSame(1, StockMovement::query()->where('order_id', $shippedOrder->id)->count());
    }

    public function test_pickup_completion_consumes_the_active_reservation(): void
    {
        [$order, , $balance] = $this->orderWithStock(quantity: 4, ordered: 2);
        $manager = $this->userWithRole(UserRole::Manager);

        $this->actingAs($manager)->patchJson("/api/v1/admin/orders/{$order->id}/status", ['order_status' => 'confirmed'])->assertOk();
        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", ['order_status' => 'preparing'])->assertOk();
        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", ['order_status' => 'ready_for_pickup'])->assertOk();
        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", ['order_status' => 'completed'])->assertOk();

        $this->assertSame(2, $balance->fresh()->on_hand);
        $this->assertSame(0, $balance->fresh()->reserved);
        $this->assertDatabaseHas('stock_reservations', ['order_id' => $order->id, 'status' => 'consumed']);
        $this->assertDatabaseHas('stock_movements', ['order_id' => $order->id, 'type' => 'dispatch', 'quantity_delta' => -2]);
    }

    public function test_reservation_crosses_low_stock_threshold_and_cancellation_rearms_alert(): void
    {
        Queue::fake();
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => 'test-chat',
        ]);
        [$order, , $balance] = $this->orderWithStock(quantity: 3, ordered: 1);
        $manager = $this->userWithRole(UserRole::Manager);

        $this->actingAs($manager)
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", ['order_status' => OrderStatus::Confirmed->value])
            ->assertOk();

        $alert = LowStockAlert::query()->firstOrFail();
        $this->assertSame(2, $balance->fresh()->on_hand - $balance->fresh()->reserved);
        $this->assertTrue($alert->is_active);
        Queue::assertPushedTimes(SendLowStockTelegramAlert::class, 1);

        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", ['order_status' => OrderStatus::Cancelled->value])
            ->assertOk();

        $this->assertSame(3, $balance->fresh()->on_hand - $balance->fresh()->reserved);
        $this->assertFalse($alert->fresh()->is_active);
        Queue::assertPushedTimes(SendLowStockTelegramAlert::class, 1);
    }

    public function test_manager_can_assign_add_private_notes_and_set_delivery_fee(): void
    {
        [$order] = $this->orderWithStock(quantity: 4, ordered: 1, delivery: true);
        $manager = $this->userWithRole(UserRole::Manager);
        $assignee = $this->userWithRole(UserRole::Administrator);

        $this->actingAs($manager)
            ->patchJson("/api/v1/admin/orders/{$order->id}/assignment", ['assigned_to_id' => $assignee->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_to.id', $assignee->id);
        $this->postJson("/api/v1/admin/orders/{$order->id}/notes", ['body' => 'Согласовать время доставки'])
            ->assertOk()
            ->assertJsonPath('data.internal_notes.0.body', 'Согласовать время доставки');
        $this->patchJson("/api/v1/admin/orders/{$order->id}/delivery-fee", ['delivery_fee_kzt' => 1500])
            ->assertOk()
            ->assertJsonPath('data.total_kzt', $order->subtotal_kzt + 1500);

        $customer = $this->userWithRole(UserRole::Customer);
        $order->update(['user_id' => $customer->id]);
        $this->actingAs($customer)
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.internal_notes')
            ->assertJsonMissingPath('data.assigned_to');

        $this->actingAs($customer)
            ->postJson("/api/v1/admin/orders/{$order->id}/notes", ['body' => 'Не должно сохраниться'])
            ->assertForbidden();
        $this->assertDatabaseCount('order_internal_notes', 1);
    }

    public function test_delivery_must_be_priced_before_marking_offline_payment_paid(): void
    {
        [$order] = $this->orderWithStock(quantity: 4, ordered: 1, delivery: true);
        $manager = $this->userWithRole(UserRole::Manager);

        $this->actingAs($manager)
            ->patchJson("/api/v1/admin/orders/{$order->id}/payment-status", ['payment_status' => PaymentStatus::Paid->value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_status']);
        $this->patchJson("/api/v1/admin/orders/{$order->id}/delivery-fee", ['delivery_fee_kzt' => 1000])->assertOk();
        $this->patchJson("/api/v1/admin/orders/{$order->id}/payment-status", ['payment_status' => PaymentStatus::Paid->value])->assertOk();
        $this->assertDatabaseHas('payment_status_history', [
            'order_id' => $order->id,
            'actor_user_id' => $manager->id,
            'from_status' => PaymentStatus::Unpaid->value,
            'to_status' => PaymentStatus::Paid->value,
        ]);
        $this->assertNotNull($order->paymentStatusHistory()->where('to_status', PaymentStatus::Paid->value)->value('created_at'));
        $this->patchJson("/api/v1/admin/orders/{$order->id}/payment-status", [
            'payment_status' => PaymentStatus::PartiallyRefunded->value,
            'amount_kzt' => $order->subtotal_kzt + 1000,
        ])->assertUnprocessable();
    }

    public function test_crm_endpoints_require_a_privileged_role_and_support_order_filters(): void
    {
        [$first] = $this->orderWithStock(quantity: 5, ordered: 1);
        $second = Order::factory()->create(['order_status' => OrderStatus::Confirmed]);
        $customer = $this->userWithRole(UserRole::Customer);
        $manager = $this->userWithRole(UserRole::Manager);

        $this->getJson('/api/v1/admin/orders')->assertUnauthorized();
        $this->actingAs($customer)->getJson('/api/v1/admin/orders')->assertForbidden();
        $this->actingAs($manager)
            ->getJson('/api/v1/admin/orders?status=confirmed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second->id);
        $this->getJson('/api/v1/admin/orders?search='.$first->number)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->id);
        $this->getJson('/api/v1/admin/orders?sort=oldest')
            ->assertOk()
            ->assertJsonPath('data.0.id', $first->id);
        $this->getJson('/api/v1/admin/orders?sort=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort']);
    }

    /**
     * @return array{Order, ProductVariant, StockBalance}
     */
    private function orderWithStock(int $quantity, int $ordered, bool $delivery = false): array
    {
        $category = Category::factory()->create(['is_active' => true]);
        $product = Product::factory()->for($category)->create(['is_published' => true]);
        $variant = ProductVariant::factory()->for($product)->create(['is_active' => true]);
        $warehouse = Warehouse::query()->firstOrCreate(
            ['code' => 'main'],
            ['name' => 'Main warehouse', 'is_active' => true],
        );
        $balance = StockBalance::factory()->for($warehouse)->for($variant, 'variant')->create([
            'on_hand' => $quantity,
            'reserved' => 0,
        ]);
        $orderFactory = Order::factory();
        if ($delivery) {
            $orderFactory = $orderFactory->delivery();
        }
        $order = $orderFactory->create([
            'subtotal_kzt' => $variant->price_kzt * $ordered,
            'delivery_fee_kzt' => $delivery ? null : 0,
            'total_kzt' => $delivery ? null : $variant->price_kzt * $ordered,
        ]);
        $order->items()->create([
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => $product->name,
            'variant_name_snapshot' => $variant->name,
            'sku_snapshot' => $variant->sku,
            'variant_attributes_snapshot' => $variant->attributes,
            'unit_price_kzt' => $variant->price_kzt,
            'quantity' => $ordered,
            'line_total_kzt' => $variant->price_kzt * $ordered,
        ]);

        return [$order, $variant, $balance];
    }

    private function userWithRole(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }
}
