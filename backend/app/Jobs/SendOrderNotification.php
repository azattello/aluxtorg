<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Mail\OrderNotificationMail;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class SendOrderNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const ORDER_RECEIVED = 'order_received';

    public const STATUS_CHANGED = 'status_changed';

    public const MANAGER_NEW_ORDER = 'manager_new_order';

    public const MAIL = 'mail';

    public const TELEGRAM = 'telegram';

    public int $tries = 3;

    public int $timeout = 20;

    public function __construct(
        public readonly int $orderId,
        public readonly string $event,
        public readonly string $channel,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $order = Order::query()->with('items')->findOrFail($this->orderId);

        match ($this->channel) {
            self::MAIL => $this->sendMail($order),
            self::TELEGRAM => $this->sendTelegram($order),
            default => throw new InvalidArgumentException('Unsupported order notification channel.'),
        };
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Order notification exhausted its retries.', [
            'order_id' => $this->orderId,
            'event' => $this->event,
            'channel' => $this->channel,
            'exception' => $exception::class,
        ]);
    }

    private function sendMail(Order $order): void
    {
        if (! $order->customer_email) {
            return;
        }

        if ($this->event === self::STATUS_CHANGED) {
            if (! $order->customer_notifications_opted_in_at || $order->customer_notifications_opted_out_at) {
                return;
            }

            $statusLabel = $this->statusLabel($order->order_status);
            $message = "Статус вашего заказа изменён: {$statusLabel}.";
            $unsubscribeUrl = URL::signedRoute('orders.notifications.unsubscribe', ['order' => $order->id]);
            $subject = "Статус заказа {$order->number} изменён";
        } elseif ($this->event === self::ORDER_RECEIVED) {
            $statusLabel = null;
            $message = 'Ваш заказ получен. Менеджер свяжется с вами для уточнения деталей получения и оплаты.';
            $unsubscribeUrl = null;
            $subject = "Заказ {$order->number} получен";
        } else {
            throw new InvalidArgumentException('Unsupported email order notification event.');
        }

        Mail::to($order->customer_email)->send(new OrderNotificationMail(
            orderNumber: $order->number,
            subjectLine: $subject,
            bodyText: $message,
            statusLabel: $statusLabel,
            unsubscribeUrl: $unsubscribeUrl,
        ));
    }

    private function sendTelegram(Order $order): void
    {
        if ($this->event !== self::MANAGER_NEW_ORDER) {
            throw new InvalidArgumentException('Unsupported Telegram order notification event.');
        }

        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');
        if (! filled($token) || ! filled($chatId)) {
            throw new RuntimeException('Telegram order notification credentials are missing.');
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => "Новый заказ {$order->number} создан. Откройте CRM → Заказы.",
            ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Telegram API connection failed.');
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException('Telegram API rejected the order notification.');
        }
    }

    private function statusLabel(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::New => 'Новый',
            OrderStatus::Confirmed => 'Подтверждён',
            OrderStatus::Preparing => 'Готовится',
            OrderStatus::ReadyForPickup => 'Готов к самовывозу',
            OrderStatus::Shipped => 'Отправлен',
            OrderStatus::Completed => 'Завершён',
            OrderStatus::Cancelled => 'Отменён',
        };
    }
}
