<?php

namespace App\Jobs;

use App\Models\LowStockAlert;
use App\Models\StockBalance;
use App\Services\LowStockAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class SendLowStockTelegramAlert implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 20;

    public function __construct(
        public readonly int $alertId,
        public readonly string $activationKey,
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
        $alert = LowStockAlert::query()
            ->with(['warehouse', 'variant.product'])
            ->findOrFail($this->alertId);

        if (! $alert->is_active || ! hash_equals($alert->activation_key, $this->activationKey)) {
            return;
        }

        $balance = StockBalance::query()
            ->where('warehouse_id', $alert->warehouse_id)
            ->where('product_variant_id', $alert->product_variant_id)
            ->firstOrFail();
        $available = $balance->on_hand - $balance->reserved;

        if ($available > LowStockAlertService::THRESHOLD) {
            return;
        }

        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');
        if (! filled($token) || ! filled($chatId)) {
            throw new InvalidArgumentException('Telegram low-stock alert credentials are missing.');
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => "Низкий остаток: {$alert->variant->product->name} · SKU {$alert->variant->sku}. Доступно {$available}; порог ".LowStockAlertService::THRESHOLD.". Склад: {$alert->warehouse->name}.",
            ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Telegram API connection failed for a low-stock alert.');
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException('Telegram API rejected the low-stock alert.');
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Low-stock Telegram alert exhausted its retries.', [
            'alert_id' => $this->alertId,
            'exception' => $exception::class,
        ]);
    }
}
