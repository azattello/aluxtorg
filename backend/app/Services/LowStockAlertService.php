<?php

namespace App\Services;

use App\Jobs\SendLowStockTelegramAlert;
use App\Models\LowStockAlert;
use App\Models\StockBalance;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class LowStockAlertService
{
    public const THRESHOLD = 2;

    public function recordAvailabilityChange(
        StockBalance $balance,
        int $previousAvailable,
        int $currentAvailable,
    ): void {
        $alert = LowStockAlert::query()
            ->where('warehouse_id', $balance->warehouse_id)
            ->where('product_variant_id', $balance->product_variant_id)
            ->lockForUpdate()
            ->first();

        if ($currentAvailable > self::THRESHOLD) {
            if ($alert?->is_active) {
                $alert->update([
                    'is_active' => false,
                    'resolved_at' => now(),
                ]);
            }

            return;
        }

        if ($previousAvailable <= self::THRESHOLD || $alert?->is_active) {
            return;
        }

        $activationKey = (string) Str::uuid();
        $alert ??= new LowStockAlert([
            'warehouse_id' => $balance->warehouse_id,
            'product_variant_id' => $balance->product_variant_id,
        ]);
        $alert->fill([
            'is_active' => true,
            'activation_key' => $activationKey,
            'triggered_at' => now(),
            'resolved_at' => null,
        ])->save();

        if (! filled(config('services.telegram.bot_token')) || ! filled(config('services.telegram.chat_id'))) {
            Log::warning('Telegram low-stock alert is not configured.', ['alert_id' => $alert->id]);

            return;
        }

        try {
            SendLowStockTelegramAlert::dispatch($alert->id, $activationKey);
        } catch (Throwable $exception) {
            Log::error('Could not queue a low-stock Telegram alert.', [
                'alert_id' => $alert->id,
                'exception' => $exception::class,
            ]);
        }
    }
}
