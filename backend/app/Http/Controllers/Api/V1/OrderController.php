<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\StoreOrderRequest;
use App\Http\Resources\CustomerOrderDetailResource;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\OrderNotificationDispatcher;
use App\Services\ReturnRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function store(StoreOrderRequest $request, OrderNotificationDispatcher $notifications): JsonResponse
    {
        $data = $request->validated();
        $existing = Order::query()
            ->where('idempotency_key', $data['idempotency_key'])
            ->with('items')
            ->first();

        if ($existing) {
            return $this->replayResponse($existing, $request, $data);
        }

        try {
            $order = DB::transaction(function () use ($request, $data): Order {
                $warehouseIds = Warehouse::query()
                    ->where('is_active', true)
                    ->pluck('id');

                $variantIds = collect($data['items'])->pluck('variant_id')->all();
                $variants = ProductVariant::query()
                    ->whereIn('id', $variantIds)
                    ->where('is_active', true)
                    ->whereHas('product', fn (Builder $product): Builder => $product
                        ->where('is_published', true)
                        ->whereHas('category', fn (Builder $category): Builder => $category->where('is_active', true)))
                    ->with(['product', 'stockBalances' => fn (Relation $balances): Relation => $balances
                        ->whereIn('warehouse_id', $warehouseIds)])
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($variants->count() !== count($variantIds)) {
                    throw ValidationException::withMessages([
                        'items' => 'Один или несколько товаров больше недоступны.',
                    ]);
                }

                $subtotal = 0;
                $items = [];

                foreach ($data['items'] as $requestedItem) {
                    $variant = $variants->get($requestedItem['variant_id']);
                    $quantity = $requestedItem['quantity'];
                    $available = $variant->stockBalances->sum(
                        fn ($balance): int => max(0, $balance->on_hand - $balance->reserved),
                    );

                    if ($available < $quantity) {
                        throw ValidationException::withMessages([
                            'items' => "Недостаточно товара «{$variant->product->name}» для оформления заказа.",
                        ]);
                    }

                    if ($variant->price_kzt > intdiv(PHP_INT_MAX, $quantity)) {
                        throw ValidationException::withMessages([
                            'items' => 'Сумма заказа превышает допустимое значение.',
                        ]);
                    }

                    $lineTotal = $variant->price_kzt * $quantity;
                    if ($subtotal > PHP_INT_MAX - $lineTotal) {
                        throw ValidationException::withMessages([
                            'items' => 'Сумма заказа превышает допустимое значение.',
                        ]);
                    }

                    $subtotal += $lineTotal;
                    $items[] = [
                        'product_variant_id' => $variant->id,
                        'product_name_snapshot' => $variant->product->name,
                        'variant_name_snapshot' => $variant->name,
                        'sku_snapshot' => $variant->sku,
                        'variant_attributes_snapshot' => $variant->attributes,
                        'sale_unit_snapshot' => $variant->sale_unit,
                        'is_returnable_snapshot' => $variant->is_returnable,
                        'unit_price_kzt' => $variant->price_kzt,
                        'quantity' => $quantity,
                        'line_total_kzt' => $lineTotal,
                    ];
                }

                $isPickup = $data['fulfillment_method'] === 'pickup';
                $order = Order::query()->create([
                    'user_id' => $request->user()?->id,
                    'number' => 'ORD-'.Str::upper(Str::random(12)),
                    'order_status' => OrderStatus::New,
                    'payment_status' => PaymentStatus::Unpaid,
                    'fulfillment_method' => $data['fulfillment_method'],
                    'customer_name' => $data['customer_name'],
                    'customer_phone' => $data['customer_phone'],
                    'customer_email' => $data['customer_email'] ?? null,
                    'customer_notifications_opted_in_at' => filter_var(
                        $data['customer_notifications_opted_in'] ?? false,
                        FILTER_VALIDATE_BOOLEAN,
                    ) ? now() : null,
                    'delivery_city' => $data['delivery_city'] ?? null,
                    'delivery_street' => $data['delivery_street'] ?? null,
                    'delivery_building' => $data['delivery_building'] ?? null,
                    'delivery_apartment' => $data['delivery_apartment'] ?? null,
                    'delivery_instructions' => $data['delivery_instructions'] ?? null,
                    'subtotal_kzt' => $subtotal,
                    'delivery_fee_kzt' => $isPickup ? 0 : null,
                    'total_kzt' => $isPickup ? $subtotal : null,
                    'idempotency_key' => $data['idempotency_key'],
                ]);

                $order->items()->createMany($items);
                OrderStatusHistory::query()->create([
                    'order_id' => $order->id,
                    'actor_user_id' => $request->user()?->id,
                    'from_status' => null,
                    'to_status' => OrderStatus::New,
                    'note' => null,
                ]);

                return $order->load('items');
            });
        } catch (QueryException $exception) {
            $existing = Order::query()
                ->where('idempotency_key', $data['idempotency_key'])
                ->with('items')
                ->first();

            if (! $existing) {
                throw $exception;
            }

            return $this->replayResponse($existing, $request, $data);
        }

        $notifications->orderCreated($order);

        return (new OrderResource($order))->response()->setStatusCode(201);
    }

    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->with('items')
            ->latest('id')
            ->paginate(15);

        return OrderResource::collection($orders)->response();
    }

    public function show(Request $request, Order $order, ReturnRequestService $returns): CustomerOrderDetailResource
    {
        abort_unless($order->user_id === $request->user()->id, 404);

        $order->load([
            'items',
            'statusHistory',
            'returnRequests.items.orderItem',
            'returnRequests.statusHistory',
        ]);
        $remainingReturnableQuantities = $returns->remainingReturnableQuantities($order);
        $order->items->each(fn ($item) => $item->setAttribute(
            'remaining_returnable_quantity',
            $remainingReturnableQuantities[$item->id] ?? 0,
        ));
        $order->setAttribute('return_deadline_at', $returns->returnDeadline($order)?->toISOString());
        $order->setAttribute(
            'can_request_return',
            $returns->isReturnWindowOpen($order)
            && $remainingReturnableQuantities !== [],
        );

        return new CustomerOrderDetailResource($order);
    }

    private function replayResponse(Order $order, StoreOrderRequest $request, array $data): JsonResponse
    {
        if (! $this->matchesRequest($order, $request, $data)) {
            return response()->json([
                'message' => 'Этот ключ запроса уже использован для другого заказа.',
            ], 409);
        }

        return (new OrderResource($order))->response()->setStatusCode(200);
    }

    private function matchesRequest(Order $order, StoreOrderRequest $request, array $data): bool
    {
        if (
            $order->user_id !== $request->user()?->id
            || $order->fulfillment_method !== $data['fulfillment_method']
            || $order->customer_name !== $data['customer_name']
            || $order->customer_phone !== $data['customer_phone']
            || $order->customer_email !== ($data['customer_email'] ?? null)
            || ($order->customer_notifications_opted_in_at !== null)
                !== filter_var($data['customer_notifications_opted_in'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || $order->delivery_city !== ($data['delivery_city'] ?? null)
            || $order->delivery_street !== ($data['delivery_street'] ?? null)
            || $order->delivery_building !== ($data['delivery_building'] ?? null)
            || $order->delivery_apartment !== ($data['delivery_apartment'] ?? null)
            || $order->delivery_instructions !== ($data['delivery_instructions'] ?? null)
        ) {
            return false;
        }

        $existingItems = $order->items
            ->mapWithKeys(fn ($item): array => [$item->product_variant_id => $item->quantity])
            ->sortKeys()
            ->all();
        $requestedItems = collect($data['items'])
            ->mapWithKeys(fn (array $item): array => [$item['variant_id'] => $item['quantity']])
            ->sortKeys()
            ->all();

        return $existingItems === $requestedItems;
    }
}
