<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Jobs\SendOrderNotification;
use App\Mail\OrderNotificationMail;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class OrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_queues_order_receipt_and_configured_telegram_alert_once(): void
    {
        Queue::fake();
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => 'test-chat',
        ]);
        $this->actingAs(User::factory()->create());
        $variant = $this->availableVariant();
        $payload = $this->orderData($variant->id) + [
            'customer_email' => 'customer@example.com',
            'customer_notifications_opted_in' => true,
        ];
        $key = 'c0ddfa13-5cf3-4f82-aa25-940d193d5de0';

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/orders', $payload)
            ->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertNotNull($order->customer_notifications_opted_in_at);
        Queue::assertPushedTimes(SendOrderNotification::class, 2);
        Queue::assertPushed(SendOrderNotification::class, fn (SendOrderNotification $job): bool => $job->orderId === $order->id
            && $job->event === SendOrderNotification::ORDER_RECEIVED
            && $job->channel === SendOrderNotification::MAIL
        );
        Queue::assertPushed(SendOrderNotification::class, fn (SendOrderNotification $job): bool => $job->orderId === $order->id
            && $job->event === SendOrderNotification::MANAGER_NEW_ORDER
            && $job->channel === SendOrderNotification::TELEGRAM
        );

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/orders', $payload)
            ->assertOk();
        Queue::assertPushedTimes(SendOrderNotification::class, 2);
    }

    public function test_status_notifications_require_checkout_opt_in_and_can_be_revoked(): void
    {
        Queue::fake();
        $consented = Order::factory()->create([
            'order_status' => OrderStatus::Confirmed,
            'customer_email' => 'consented@example.com',
            'customer_notifications_opted_in_at' => now(),
        ]);
        $unsubscribed = Order::factory()->create([
            'order_status' => OrderStatus::Confirmed,
            'customer_email' => 'unsubscribed@example.com',
            'customer_notifications_opted_in_at' => now(),
            'customer_notifications_opted_out_at' => now(),
        ]);
        $manager = User::factory()->create(['role' => UserRole::Manager]);

        $this->actingAs($manager)
            ->patchJson("/api/v1/admin/orders/{$consented->id}/status", ['order_status' => OrderStatus::Preparing->value])
            ->assertOk();
        $this->patchJson("/api/v1/admin/orders/{$unsubscribed->id}/status", ['order_status' => OrderStatus::Preparing->value])
            ->assertOk();

        Queue::assertPushedTimes(SendOrderNotification::class, 1);
        Queue::assertPushed(SendOrderNotification::class, fn (SendOrderNotification $job): bool => $job->orderId === $consented->id
            && $job->event === SendOrderNotification::STATUS_CHANGED
            && $job->channel === SendOrderNotification::MAIL
        );
    }

    public function test_checkout_cannot_record_status_email_consent_without_an_email(): void
    {
        $this->actingAs(User::factory()->create());
        $variant = $this->availableVariant();

        $this->withHeader('Idempotency-Key', 'edb52554-4bfc-4ed1-8c11-fdd117d43355')
            ->postJson('/api/v1/orders', [
                ...$this->orderData($variant->id),
                'customer_notifications_opted_in' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_notifications_opted_in']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_queue_outage_does_not_roll_back_a_created_order(): void
    {
        config(['queue.default' => 'unavailable']);
        $this->actingAs(User::factory()->create());
        $variant = $this->availableVariant();

        $this->withHeader('Idempotency-Key', '6eb3c67d-7fb7-43fb-b688-2f19111d611c')
            ->postJson('/api/v1/orders', [
                ...$this->orderData($variant->id),
                'customer_email' => 'customer@example.com',
            ])
            ->assertCreated();

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_status_email_contains_only_public_order_status_and_signed_unsubscribe_link(): void
    {
        Mail::fake();
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Preparing,
            'customer_email' => 'customer@example.com',
            'customer_notifications_opted_in_at' => now(),
        ]);

        (new SendOrderNotification(
            $order->id,
            SendOrderNotification::STATUS_CHANGED,
            SendOrderNotification::MAIL,
        ))->handle();

        Mail::assertSent(OrderNotificationMail::class, function (OrderNotificationMail $mail) use ($order): bool {
            $rendered = $mail->render();

            return $mail->orderNumber === $order->number
                && str_contains($rendered, 'Готовится')
                && str_contains($rendered, 'signature=')
                && ! str_contains($rendered, $order->customer_phone);
        });
    }

    public function test_order_receipt_is_sent_without_status_notification_consent(): void
    {
        Mail::fake();
        $order = Order::factory()->create([
            'customer_email' => 'customer@example.com',
            'customer_notifications_opted_in_at' => null,
        ]);

        (new SendOrderNotification(
            $order->id,
            SendOrderNotification::ORDER_RECEIVED,
            SendOrderNotification::MAIL,
        ))->handle();

        Mail::assertSent(OrderNotificationMail::class, fn (OrderNotificationMail $mail): bool => $mail->orderNumber === $order->number
            && str_contains($mail->bodyText, 'Ваш заказ получен')
            && $mail->unsubscribeUrl === null
        );
    }

    public function test_signed_unsubscribe_requires_confirmation_and_records_opt_out(): void
    {
        $order = Order::factory()->create([
            'customer_email' => 'customer@example.com',
            'customer_notifications_opted_in_at' => now(),
        ]);
        $url = URL::signedRoute('orders.notifications.unsubscribe', ['order' => $order->id]);

        $this->get($url)->assertOk()->assertSee('Отписаться');
        $this->assertNull($order->fresh()->customer_notifications_opted_out_at);

        $this->post($url)->assertOk()->assertSee('Вы отписались');

        $this->assertNotNull($order->fresh()->customer_notifications_opted_out_at);
        $this->assertDatabaseHas('audit_logs', [
            'event_key' => 'customer.order_notifications_unsubscribed',
            'subject_id' => $order->id,
        ]);
    }

    public function test_telegram_alert_contains_no_customer_contact_or_delivery_data(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => 'test-chat',
        ]);
        $order = Order::factory()->delivery()->create([
            'customer_name' => 'Sensitive Name',
            'customer_phone' => '+77010000000',
            'delivery_city' => 'Sensitive City',
            'delivery_street' => 'Sensitive Street',
        ]);

        (new SendOrderNotification(
            $order->id,
            SendOrderNotification::MANAGER_NEW_ORDER,
            SendOrderNotification::TELEGRAM,
        ))->handle();

        Http::assertSent(fn (ClientRequest $request): bool => $request->data()['chat_id'] === 'test-chat'
            && $request->data()['text'] === "Новый заказ {$order->number} создан. Откройте CRM → Заказы."
            && ! str_contains($request->data()['text'], $order->customer_phone)
            && ! str_contains($request->data()['text'], $order->customer_name)
        );
    }

    public function test_telegram_api_failure_is_reported_without_deleting_the_order(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => false], 500)]);
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => 'test-chat',
        ]);
        $order = Order::factory()->create();

        try {
            (new SendOrderNotification(
                $order->id,
                SendOrderNotification::MANAGER_NEW_ORDER,
                SendOrderNotification::TELEGRAM,
            ))->handle();
            $this->fail('Telegram API errors must reach the queue retry handler.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Telegram API rejected the order notification.', $exception->getMessage());
        }

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    private function availableVariant(): ProductVariant
    {
        $category = Category::factory()->create(['is_active' => true]);
        $product = Product::factory()->for($category)->create(['is_published' => true]);
        $variant = ProductVariant::factory()->for($product)->create(['is_active' => true, 'price_kzt' => 1200]);
        $warehouse = Warehouse::query()->firstOrCreate(
            ['code' => 'main'],
            ['name' => 'Main warehouse', 'is_active' => true],
        );
        StockBalance::factory()->for($warehouse)->for($variant, 'variant')->create([
            'on_hand' => 5,
            'reserved' => 0,
        ]);

        return $variant;
    }

    private function orderData(int $variantId): array
    {
        return [
            'fulfillment_method' => 'pickup',
            'customer_name' => 'Test Customer',
            'customer_phone' => '+77010000000',
            'items' => [['variant_id' => $variantId, 'quantity' => 1]],
        ];
    }
}
