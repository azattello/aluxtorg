<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SendLowStockTelegramAlert;
use App\Models\Category;
use App\Models\LowStockAlert;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WarehouseOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_operator_can_receive_and_reconcile_stock_with_a_movement_log(): void
    {
        [$variant, $balance, $warehouse] = $this->variantAndBalance(onHand: 5, reserved: 2);
        $operator = $this->userWithRole(UserRole::WarehouseOperator);

        $this->actingAs($operator)
            ->postJson('/api/v1/admin/warehouse/receipts', [
                'variant_id' => $variant->id,
                'quantity' => 3,
                'note' => 'Накладная № 42',
            ])
            ->assertCreated()
            ->assertJsonPath('data.on_hand', 8);

        $this->assertSame(8, $balance->fresh()->on_hand);
        $this->assertDatabaseHas('stock_movements', [
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'type' => 'receipt',
            'quantity_delta' => 3,
            'actor_user_id' => $operator->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event_key' => 'warehouse.stock_received',
            'subject_id' => $balance->id,
        ]);

        $this->postJson('/api/v1/admin/warehouse/reconciliations', [
            'variant_id' => $variant->id,
            'counted_on_hand' => 1,
            'note' => 'Пересчёт',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['counted_on_hand']);

        $this->postJson('/api/v1/admin/warehouse/reconciliations', [
            'variant_id' => $variant->id,
            'counted_on_hand' => 7,
            'note' => 'Инвентаризация',
        ])->assertOk()
            ->assertJsonPath('data.quantity_delta', -1);

        $this->assertSame(7, $balance->fresh()->on_hand);
        $this->assertSame(2, $balance->fresh()->reserved);
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variant->id,
            'type' => 'adjustment',
            'quantity_delta' => -1,
            'actor_user_id' => $operator->id,
            'note' => 'Инвентаризация',
        ]);
    }

    public function test_warehouse_routes_allow_warehouse_roles_and_reject_customers(): void
    {
        $this->variantAndBalance();
        $operator = $this->userWithRole(UserRole::WarehouseOperator);
        $customer = $this->userWithRole(UserRole::Customer);
        $this->getJson('/api/v1/admin/warehouse/balances')->assertUnauthorized();

        $this->actingAs($customer)
            ->getJson('/api/v1/admin/warehouse/balances')
            ->assertForbidden();
        $this->actingAs($operator)
            ->getJson('/api/v1/admin/warehouse/balances')
            ->assertOk()
            ->assertJsonPath('data.0.on_hand', 4);
    }

    public function test_reconciliation_creates_balance_for_a_new_sku_and_movement_filter_works(): void
    {
        [$variant] = $this->variantAndBalance(createBalance: false);
        $manager = $this->userWithRole(UserRole::Manager);

        $this->actingAs($manager)
            ->postJson('/api/v1/admin/warehouse/reconciliations', [
                'variant_id' => $variant->id,
                'counted_on_hand' => 6,
                'note' => 'Первичная инвентаризация',
            ])
            ->assertOk()
            ->assertJsonPath('data.quantity_delta', 6);

        $this->getJson('/api/v1/admin/warehouse/balances?variant_id='.$variant->id)
            ->assertOk()
            ->assertJsonPath('data.0.on_hand', 6)
            ->assertJsonPath('data.0.available', 6);
        $this->getJson('/api/v1/admin/warehouse/movements?type=receipt')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/warehouse/movements?type=adjustment')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_low_stock_alerts_trigger_only_on_downward_crossing_and_rearm_after_restock(): void
    {
        Queue::fake();
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => 'test-chat',
        ]);
        [$variant] = $this->variantAndBalance(onHand: 5);
        $operator = $this->userWithRole(UserRole::WarehouseOperator);
        $this->actingAs($operator);

        $this->reconcile($variant->id, 2)->assertOk();
        $alert = LowStockAlert::query()->firstOrFail();
        $firstActivation = $alert->activation_key;
        $this->assertTrue($alert->is_active);
        Queue::assertPushedTimes(SendLowStockTelegramAlert::class, 1);
        $this->getJson("/api/v1/admin/warehouse/balances?variant_id={$variant->id}&low_stock=1")
            ->assertOk()
            ->assertJsonPath('meta.low_stock_threshold', 2)
            ->assertJsonPath('meta.low_stock_count', 1)
            ->assertJsonPath('data.0.available', 2);

        $this->reconcile($variant->id, 1)->assertOk();
        $this->assertSame($firstActivation, $alert->fresh()->activation_key);
        Queue::assertPushedTimes(SendLowStockTelegramAlert::class, 1);

        $this->reconcile($variant->id, 3)->assertOk();
        $this->assertFalse($alert->fresh()->is_active);
        $this->getJson("/api/v1/admin/warehouse/balances?variant_id={$variant->id}")
            ->assertOk()
            ->assertJsonPath('meta.low_stock_count', 0);

        $this->reconcile($variant->id, 2)->assertOk();
        $this->assertTrue($alert->fresh()->is_active);
        $this->assertNotSame($firstActivation, $alert->fresh()->activation_key);
        Queue::assertPushedTimes(SendLowStockTelegramAlert::class, 2);
    }

    public function test_low_stock_telegram_sends_only_the_current_alert_activation(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => 'test-chat',
        ]);
        [$variant, $balance, $warehouse] = $this->variantAndBalance(onHand: 2);
        $activationKey = (string) Str::uuid();
        $alert = LowStockAlert::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'is_active' => true,
            'activation_key' => $activationKey,
            'triggered_at' => now(),
        ]);

        (new SendLowStockTelegramAlert($alert->id, 'outdated-activation'))->handle();
        Http::assertNothingSent();

        (new SendLowStockTelegramAlert($alert->id, $activationKey))->handle();
        Http::assertSent(fn (ClientRequest $request): bool => $request->data()['chat_id'] === 'test-chat'
            && str_contains($request->data()['text'], $variant->sku)
            && str_contains($request->data()['text'], 'Доступно 2')
            && str_contains($request->data()['text'], 'порог 2')
        );
        $this->assertSame(2, $balance->fresh()->on_hand - $balance->fresh()->reserved);
    }

    /**
     * @return array{ProductVariant, ?StockBalance, Warehouse}
     */
    private function variantAndBalance(int $onHand = 4, int $reserved = 0, bool $createBalance = true): array
    {
        $category = Category::factory()->create(['is_active' => true]);
        $product = Product::factory()->for($category)->create();
        $variant = ProductVariant::factory()->for($product)->create();
        $warehouse = Warehouse::query()->firstOrCreate(
            ['code' => 'main'],
            ['name' => 'Main warehouse', 'is_active' => true],
        );
        $balance = $createBalance
            ? StockBalance::factory()->for($warehouse)->for($variant, 'variant')->create([
                'on_hand' => $onHand,
                'reserved' => $reserved,
            ])
            : null;

        return [$variant, $balance, $warehouse];
    }

    private function userWithRole(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function reconcile(int $variantId, int $countedOnHand): TestResponse
    {
        return $this->postJson('/api/v1/admin/warehouse/reconciliations', [
            'variant_id' => $variantId,
            'counted_on_hand' => $countedOnHand,
            'note' => 'Low stock threshold test',
        ]);
    }
}
