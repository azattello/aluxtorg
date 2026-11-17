<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderWorkflow
{
    public function __construct(private readonly LowStockAlertService $lowStockAlerts) {}

    public function transition(Order $order, OrderStatus $next, User $actor, ?string $note): Order
    {
        return DB::transaction(function () use ($order, $next, $actor, $note): Order {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $current = $lockedOrder->order_status;

            if (! $current->canTransitionTo($next)) {
                throw ValidationException::withMessages([
                    'order_status' => "Нельзя перевести заказ из статуса «{$current->value}» в «{$next->value}».",
                ]);
            }

            if ($next === OrderStatus::Confirmed) {
                $this->reserveOrder($lockedOrder, $actor);
            } elseif ($next === OrderStatus::Cancelled) {
                $this->releaseReservations($lockedOrder, $actor);
            } elseif (
                $next === OrderStatus::Shipped
                || ($next === OrderStatus::Completed && $current === OrderStatus::ReadyForPickup)
            ) {
                $this->consumeReservations($lockedOrder, $actor);
            }

            $lockedOrder->update(['order_status' => $next]);
            OrderStatusHistory::query()->create([
                'order_id' => $lockedOrder->id,
                'actor_user_id' => $actor->id,
                'from_status' => $current,
                'to_status' => $next,
                'note' => $note,
            ]);

            return $lockedOrder;
        });
    }

    private function reserveOrder(Order $order, User $actor): void
    {
        $warehouseIds = Warehouse::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id');

        if ($warehouseIds->isEmpty()) {
            throw ValidationException::withMessages([
                'order_status' => 'Нет активного склада для резервирования товара.',
            ]);
        }

        $items = $order->items()
            ->orderBy('product_variant_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($items as $item) {
            if (! $item->product_variant_id) {
                throw ValidationException::withMessages([
                    'order_status' => "В заказе «{$item->product_name_snapshot}» больше нельзя зарезервировать.",
                ]);
            }

            $balances = StockBalance::query()
                ->whereIn('warehouse_id', $warehouseIds)
                ->where('product_variant_id', $item->product_variant_id)
                ->orderBy('warehouse_id')
                ->lockForUpdate()
                ->get();
            $available = $balances->sum(fn (StockBalance $balance): int => $balance->on_hand - $balance->reserved);

            if ($available < $item->quantity) {
                throw ValidationException::withMessages([
                    'order_status' => "Недостаточно остатка для товара «{$item->product_name_snapshot}».",
                ]);
            }

            $this->createReservations($balances, $item, $actor, $warehouseIds);
        }
    }

    private function createReservations(Collection $balances, OrderItem $item, User $actor, Collection $warehouseIds): void
    {
        $remaining = $item->quantity;

        foreach ($warehouseIds as $warehouseId) {
            if ($remaining === 0) {
                break;
            }

            $balance = $balances->firstWhere('warehouse_id', $warehouseId);
            if (! $balance) {
                continue;
            }

            $quantity = min($remaining, $balance->on_hand - $balance->reserved);
            if ($quantity < 1) {
                continue;
            }

            $previousAvailable = $balance->on_hand - $balance->reserved;
            $balance->increment('reserved', $quantity);
            $balance->refresh();
            $this->lowStockAlerts->recordAvailabilityChange(
                $balance,
                $previousAvailable,
                $balance->on_hand - $balance->reserved,
            );
            StockReservation::query()->create([
                'order_id' => $item->order_id,
                'order_item_id' => $item->id,
                'warehouse_id' => $warehouseId,
                'product_variant_id' => $item->product_variant_id,
                'created_by' => $actor->id,
                'quantity' => $quantity,
                'status' => 'active',
            ]);
            $remaining -= $quantity;
        }
    }

    private function releaseReservations(Order $order, User $actor): void
    {
        $reservations = $order->stockReservations()
            ->where('status', 'active')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($reservations as $reservation) {
            $balance = StockBalance::query()
                ->where('warehouse_id', $reservation->warehouse_id)
                ->where('product_variant_id', $reservation->product_variant_id)
                ->lockForUpdate()
                ->firstOrFail();
            $previousAvailable = $balance->on_hand - $balance->reserved;
            $balance->decrement('reserved', $reservation->quantity);
            $balance->refresh();
            $this->lowStockAlerts->recordAvailabilityChange(
                $balance,
                $previousAvailable,
                $balance->on_hand - $balance->reserved,
            );
            $reservation->update([
                'status' => 'released',
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ]);
        }
    }

    private function consumeReservations(Order $order, User $actor): void
    {
        $reservations = $order->stockReservations()
            ->where('status', 'active')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $reservedByItem = $reservations->groupBy('order_item_id')
            ->map(fn (Collection $rows): int => $rows->sum('quantity'));

        foreach ($order->items as $item) {
            if ($reservedByItem->get($item->id, 0) !== $item->quantity) {
                throw ValidationException::withMessages([
                    'order_status' => 'Для отгрузки нужно активное полное резервирование всех товаров.',
                ]);
            }
        }

        foreach ($reservations as $reservation) {
            $balance = StockBalance::query()
                ->where('warehouse_id', $reservation->warehouse_id)
                ->where('product_variant_id', $reservation->product_variant_id)
                ->lockForUpdate()
                ->firstOrFail();

            $previousAvailable = $balance->on_hand - $balance->reserved;
            $balance->decrement('reserved', $reservation->quantity);
            $balance->decrement('on_hand', $reservation->quantity);
            $balance->refresh();
            $this->lowStockAlerts->recordAvailabilityChange(
                $balance,
                $previousAvailable,
                $balance->on_hand - $balance->reserved,
            );
            StockMovement::query()->create([
                'warehouse_id' => $reservation->warehouse_id,
                'product_variant_id' => $reservation->product_variant_id,
                'order_id' => $order->id,
                'order_item_id' => $reservation->order_item_id,
                'actor_user_id' => $actor->id,
                'type' => 'dispatch',
                'quantity_delta' => -$reservation->quantity,
                'note' => 'Отгрузка заказа '.$order->number,
            ]);
            $reservation->update([
                'status' => 'consumed',
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ]);
        }
    }
}
