<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\ReturnRequestStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_request_a_return_of_whole_returnable_packages_within_14_days(): void
    {
        $owner = User::factory()->create();
        [$order, $item] = $this->completedOrder($owner, returnable: true, quantity: 3);

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/orders/{$order->id}/returns", $this->requestData($item->id, 2))
            ->assertCreated()
            ->assertJsonPath('data.status', ReturnRequestStatus::Submitted->value)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.sale_unit', 'упаковка');

        $this->assertDatabaseHas('return_status_history', [
            'return_request_id' => $response->json('data.id'),
            'actor_user_id' => $owner->id,
            'from_status' => null,
            'to_status' => ReturnRequestStatus::Submitted->value,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $owner->id,
            'event_key' => 'returns.request_created',
            'subject_type' => ReturnRequest::class,
        ]);

        $this->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.can_request_return', true)
            ->assertJsonPath('data.items.0.remaining_returnable_quantity', 1)
            ->assertJsonPath('data.return_requests.0.status', ReturnRequestStatus::Submitted->value);
    }

    public function test_guest_and_non_owners_cannot_submit_a_return_request(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        [$order, $item] = $this->completedOrder($owner, returnable: true);

        $this->postJson("/api/v1/orders/{$order->id}/returns", $this->requestData($item->id))
            ->assertUnauthorized();

        $this->actingAs($otherUser)
            ->postJson("/api/v1/orders/{$order->id}/returns", $this->requestData($item->id))
            ->assertNotFound();

        $this->assertSame(0, ReturnRequest::query()->count());
    }

    public function test_request_rejects_cut_or_nonreturnable_products_and_expired_orders(): void
    {
        $owner = User::factory()->create();
        [$nonreturnableOrder, $nonreturnableItem] = $this->completedOrder($owner, returnable: false);
        [$expiredOrder, $expiredItem] = $this->completedOrder(
            $owner,
            returnable: true,
            completedAt: now()->subDays(15),
        );

        $this->actingAs($owner)
            ->postJson("/api/v1/orders/{$nonreturnableOrder->id}/returns", $this->requestData($nonreturnableItem->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        $this->postJson("/api/v1/orders/{$expiredOrder->id}/returns", $this->requestData($expiredItem->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order']);

        $this->assertSame(0, ReturnRequest::query()->count());
    }

    public function test_return_window_is_open_at_the_exact_14_day_deadline(): void
    {
        $owner = User::factory()->create();
        $completedAt = now()->startOfSecond();
        [$order, $item] = $this->completedOrder($owner, returnable: true, completedAt: $completedAt);
        $this->travelTo($completedAt->copy()->addDays(14));

        $this->actingAs($owner)
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.can_request_return', true);

        $this->postJson("/api/v1/orders/{$order->id}/returns", $this->requestData($item->id))
            ->assertCreated();
    }

    public function test_cut_length_material_is_not_returnable_even_if_catalog_flag_is_misconfigured(): void
    {
        $owner = User::factory()->create();
        [$order, $item] = $this->completedOrder($owner, returnable: true, saleUnit: 'м');

        $this->actingAs($owner)
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.can_request_return', false)
            ->assertJsonPath('data.items.0.remaining_returnable_quantity', 0);

        $this->postJson("/api/v1/orders/{$order->id}/returns", $this->requestData($item->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_requested_quantities_cannot_exceed_purchased_full_packages_across_requests(): void
    {
        $owner = User::factory()->create();
        [$order, $item] = $this->completedOrder($owner, returnable: true, quantity: 2);
        $this->actingAs($owner);

        $this->postJson("/api/v1/orders/{$order->id}/returns", $this->requestData($item->id, 1))
            ->assertCreated();

        $this->postJson("/api/v1/orders/{$order->id}/returns", $this->requestData($item->id, 2))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        $this->assertSame(1, ReturnRequest::query()->count());
    }

    public function test_crm_can_move_a_return_through_valid_statuses_and_invalid_transitions_are_rejected(): void
    {
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $manager->role = UserRole::Manager;
        $manager->save();
        [$order, $item] = $this->completedOrder($owner, returnable: true);
        $this->actingAs($owner)
            ->postJson("/api/v1/orders/{$order->id}/returns", $this->requestData($item->id))
            ->assertCreated();
        $returnRequest = ReturnRequest::query()->firstOrFail();
        [$newerOrder, $newerItem] = $this->completedOrder($owner, returnable: true);
        $this->postJson("/api/v1/orders/{$newerOrder->id}/returns", $this->requestData($newerItem->id))
            ->assertCreated();

        $this->actingAs($manager)
            ->getJson('/api/v1/admin/returns?sort=oldest')
            ->assertOk()
            ->assertJsonPath('data.0.order.number', $order->number)
            ->assertJsonPath('data.1.order.number', $newerOrder->number);

        $this->getJson('/api/v1/admin/returns?sort=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort']);

        $this->patchJson("/api/v1/admin/returns/{$returnRequest->id}/status", [
            'status' => ReturnRequestStatus::Reviewing->value,
            'note' => 'Связались в WhatsApp',
        ])->assertOk()
            ->assertJsonPath('data.status', ReturnRequestStatus::Reviewing->value)
            ->assertJsonPath('data.status_history.1.note', 'Связались в WhatsApp');

        $this->patchJson("/api/v1/admin/returns/{$returnRequest->id}/status", [
            'status' => ReturnRequestStatus::Closed->value,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $manager->id,
            'event_key' => 'returns.status_changed',
            'subject_id' => $returnRequest->id,
        ]);
    }

    public function test_return_request_requires_customer_confirmation_of_original_package_condition(): void
    {
        $owner = User::factory()->create();
        [$order, $item] = $this->completedOrder($owner, returnable: true);

        $this->actingAs($owner)
            ->postJson("/api/v1/orders/{$order->id}/returns", [
                ...$this->requestData($item->id),
                'condition_confirmed' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['condition_confirmed']);
    }

    /**
     * @return array{Order, OrderItem}
     */
    private function completedOrder(
        User $owner,
        bool $returnable,
        int $quantity = 2,
        mixed $completedAt = null,
        string $saleUnit = 'упаковка',
    ): array {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create();
        $variant = ProductVariant::factory()->for($product)->create([
            'sale_unit' => $saleUnit,
            'is_returnable' => $returnable,
        ]);
        $order = Order::factory()->create([
            'user_id' => $owner->id,
            'order_status' => OrderStatus::Completed,
        ]);
        $item = OrderItem::factory()->for($order)->for($variant, 'variant')->create([
            'product_name_snapshot' => $product->name,
            'sale_unit_snapshot' => $saleUnit,
            'is_returnable_snapshot' => $returnable,
            'quantity' => $quantity,
            'line_total_kzt' => fn (array $attributes): int => $attributes['unit_price_kzt'] * $quantity,
        ]);
        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'actor_user_id' => null,
            'from_status' => OrderStatus::Shipped,
            'to_status' => OrderStatus::Completed,
            'created_at' => $completedAt ?? now(),
        ]);

        return [$order, $item];
    }

    private function requestData(int $orderItemId, int $quantity = 1): array
    {
        return [
            'reason' => 'Не подошёл товар.',
            'condition_confirmed' => true,
            'items' => [['order_item_id' => $orderItemId, 'quantity' => $quantity]],
        ];
    }
}
