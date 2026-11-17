<?php

namespace App\Services;

use App\Jobs\SendOrderNotification;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderNotificationDispatcher
{
    public function orderCreated(Order $order): void
    {
        if ($order->customer_email) {
            $this->queue($order, SendOrderNotification::ORDER_RECEIVED, SendOrderNotification::MAIL);
        }

        if (filled(config('services.telegram.bot_token')) && filled(config('services.telegram.chat_id'))) {
            $this->queue($order, SendOrderNotification::MANAGER_NEW_ORDER, SendOrderNotification::TELEGRAM);
        } else {
            Log::warning('Telegram order notification is not configured.', ['order_id' => $order->id]);
        }
    }

    public function orderStatusChanged(Order $order): void
    {
        if (
            $order->customer_email
            && $order->customer_notifications_opted_in_at
            && ! $order->customer_notifications_opted_out_at
        ) {
            $this->queue($order, SendOrderNotification::STATUS_CHANGED, SendOrderNotification::MAIL);
        }
    }

    private function queue(Order $order, string $event, string $channel): void
    {
        try {
            SendOrderNotification::dispatch($order->id, $event, $channel);
        } catch (Throwable $exception) {
            Log::error('Could not queue an order notification; the order remains saved.', [
                'order_id' => $order->id,
                'event' => $event,
                'channel' => $channel,
                'exception' => $exception::class,
            ]);
        }
    }
}
