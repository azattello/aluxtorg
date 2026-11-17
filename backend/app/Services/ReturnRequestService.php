<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\ReturnRequestStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnRequest;
use App\Models\ReturnStatusHistory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnRequestService
{
    public const RETURN_WINDOW_DAYS = 14;

    /**
     * @param  array{reason: string, condition_confirmed: bool, items: list<array{order_item_id: int, quantity: int}>}  $data
     */
    public function create(Order $order, User $user, array $data): ReturnRequest
    {
        return DB::transaction(function () use ($order, $user, $data): ReturnRequest {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($lockedOrder->user_id === $user->id, 404);
            $this->assertReturnWindowOpen($lockedOrder);

            $requestedItems = collect($data['items'])->keyBy('order_item_id');
            $orderItems = OrderItem::query()
                ->where('order_id', $lockedOrder->id)
                ->whereIn('id', $requestedItems->keys())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($orderItems->count() !== $requestedItems->count()) {
                $this->reject('items', 'Выберите товары из этого заказа.');
            }

            $previouslyRequested = $lockedOrder->returnRequests()
                ->whereNotIn('status', [ReturnRequestStatus::Rejected->value])
                ->with('items')
                ->get()
                ->flatMap(fn (ReturnRequest $request) => $request->items)
                ->groupBy('order_item_id')
                ->map(fn (Collection $items): int => (int) $items->sum('quantity'));

            foreach ($requestedItems as $orderItemId => $requested) {
                /** @var OrderItem $orderItem */
                $orderItem = $orderItems->get($orderItemId);

                if (! $this->isEligibleReturnItem($orderItem)) {
                    $message = $this->isCutMaterialUnit($orderItem->sale_unit_snapshot)
                        ? "Отрезной материал «{$orderItem->product_name_snapshot}» не подлежит возврату."
                        : "Товар «{$orderItem->product_name_snapshot}» не разрешён к возврату.";
                    $this->reject('items', $message);
                }

                if (($previouslyRequested->get($orderItem->id, 0) + $requested['quantity']) > $orderItem->quantity) {
                    $this->reject('items', "Количество возврата для «{$orderItem->product_name_snapshot}» превышает число купленных упаковок.");
                }
            }

            $returnRequest = $lockedOrder->returnRequests()->create([
                'user_id' => $user->id,
                'status' => ReturnRequestStatus::Submitted,
                'reason' => $data['reason'],
                'condition_confirmed_at' => now(),
            ]);

            foreach ($requestedItems as $orderItemId => $requested) {
                $returnRequest->items()->create([
                    'order_item_id' => $orderItemId,
                    'quantity' => $requested['quantity'],
                ]);
            }

            ReturnStatusHistory::query()->create([
                'return_request_id' => $returnRequest->id,
                'actor_user_id' => $user->id,
                'from_status' => null,
                'to_status' => ReturnRequestStatus::Submitted,
            ]);
            AuditLog::query()->create([
                'actor_user_id' => $user->id,
                'event_key' => 'returns.request_created',
                'subject_type' => ReturnRequest::class,
                'subject_id' => $returnRequest->id,
                'metadata' => [
                    'order_id' => $lockedOrder->id,
                    'item_count' => $requestedItems->count(),
                ],
            ]);

            return $this->loadReturnRequest($returnRequest->id);
        });
    }

    public function transition(ReturnRequest $returnRequest, ReturnRequestStatus $next, User $actor, ?string $note): ReturnRequest
    {
        return DB::transaction(function () use ($returnRequest, $next, $actor, $note): ReturnRequest {
            $lockedRequest = ReturnRequest::query()->lockForUpdate()->findOrFail($returnRequest->id);
            $current = $lockedRequest->status;

            if (! $current->canTransitionTo($next)) {
                throw ValidationException::withMessages([
                    'status' => "Нельзя перевести заявку из статуса «{$current->value}» в «{$next->value}».",
                ]);
            }

            $lockedRequest->update(['status' => $next]);
            ReturnStatusHistory::query()->create([
                'return_request_id' => $lockedRequest->id,
                'actor_user_id' => $actor->id,
                'from_status' => $current,
                'to_status' => $next,
                'note' => $note,
            ]);
            AuditLog::query()->create([
                'actor_user_id' => $actor->id,
                'event_key' => 'returns.status_changed',
                'subject_type' => ReturnRequest::class,
                'subject_id' => $lockedRequest->id,
                'metadata' => [
                    'from_status' => $current->value,
                    'to_status' => $next->value,
                ],
            ]);

            return $this->loadReturnRequest($lockedRequest->id);
        });
    }

    public function completedAt(Order $order): ?Carbon
    {
        $completed = $order->statusHistory()
            ->where('to_status', OrderStatus::Completed->value)
            ->latest('id')
            ->value('created_at');

        return $completed ? Carbon::parse($completed) : null;
    }

    public function returnDeadline(Order $order): ?Carbon
    {
        return $this->completedAt($order)?->copy()->addDays(self::RETURN_WINDOW_DAYS);
    }

    public function isReturnWindowOpen(Order $order): bool
    {
        if ($order->order_status !== OrderStatus::Completed) {
            return false;
        }

        $deadline = $this->returnDeadline($order);

        return $deadline !== null && ! now()->greaterThan($deadline);
    }

    /**
     * @return array<int, int>
     */
    public function remainingReturnableQuantities(Order $order): array
    {
        $requested = $order->returnRequests()
            ->whereNotIn('status', [ReturnRequestStatus::Rejected->value])
            ->with('items')
            ->get()
            ->flatMap(fn (ReturnRequest $request) => $request->items)
            ->groupBy('order_item_id')
            ->map(fn (Collection $items): int => (int) $items->sum('quantity'));

        return $order->items
            ->filter(fn (OrderItem $item): bool => $this->isEligibleReturnItem($item))
            ->mapWithKeys(fn (OrderItem $item): array => [
                $item->id => max(0, $item->quantity - $requested->get($item->id, 0)),
            ])
            ->filter(fn (int $quantity): bool => $quantity > 0)
            ->all();
    }

    public function assertReturnWindowOpen(Order $order): void
    {
        if (! $this->isReturnWindowOpen($order)) {
            $this->reject('order', 'Заявку можно подать только в течение 14 дней после завершения заказа.');
        }
    }

    private function loadReturnRequest(int $id): ReturnRequest
    {
        return ReturnRequest::query()
            ->with([
                'order.items',
                'user',
                'items.orderItem',
                'statusHistory.actor',
            ])
            ->findOrFail($id);
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function isEligibleReturnItem(OrderItem $item): bool
    {
        return (bool) $item->is_returnable_snapshot
            && ! $this->isCutMaterialUnit($item->sale_unit_snapshot);
    }

    private function isCutMaterialUnit(?string $saleUnit): bool
    {
        return preg_match('/^(?:м|метр(?:а|ов)?|пог\.?\s*м|погонн(?:ый|ого)\s+метр(?:а|ов)?)$/iu', trim($saleUnit ?? '')) === 1;
    }
}
