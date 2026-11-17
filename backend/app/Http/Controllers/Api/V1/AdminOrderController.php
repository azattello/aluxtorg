<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderInternalNote;
use App\Models\PaymentStatusHistory;
use App\Models\User;
use App\Services\OrderNotificationDispatcher;
use App\Services\OrderWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'search' => ['nullable', 'string', 'max:120'],
            'assigned_to' => ['nullable', 'string', 'max:20'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'amount_asc', 'amount_desc', 'customer_asc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $sort = $filters['sort'] ?? 'newest';

        $orders = Order::query()
            ->with(['items', 'assignee'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('order_status', $status))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): Builder {
                return $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery->where('number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_phone', 'like', "%{$search}%");
                });
            })
            ->when(($filters['assigned_to'] ?? null) === 'unassigned', fn (Builder $query): Builder => $query->whereNull('assigned_to_id'))
            ->when(is_numeric($filters['assigned_to'] ?? null), fn (Builder $query): Builder => $query->where('assigned_to_id', (int) $filters['assigned_to']));

        $orders = match ($sort) {
            'oldest' => $orders->orderBy('created_at')->orderBy('id'),
            'amount_asc' => $orders->orderBy('subtotal_kzt')->orderByDesc('id'),
            'amount_desc' => $orders->orderByDesc('subtotal_kzt')->orderByDesc('id'),
            'customer_asc' => $orders->orderBy('customer_name')->orderByDesc('id'),
            default => $orders->orderByDesc('created_at')->orderByDesc('id'),
        };
        $orders = $orders->paginate($filters['per_page'] ?? 20);

        return OrderResource::collection($orders)->response();
    }

    public function assignees(): JsonResponse
    {
        $users = User::query()
            ->whereIn('role', [
                UserRole::Manager->value,
                UserRole::Administrator->value,
                UserRole::SuperAdministrator->value,
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        return response()->json(['data' => $users]);
    }

    public function show(Order $order): OrderResource
    {
        return new OrderResource($this->loadOrderDetails($order));
    }

    public function assign(Request $request, Order $order): OrderResource
    {
        $data = $request->validate([
            'assigned_to_id' => ['present', 'nullable', 'integer', 'exists:users,id'],
        ]);

        $assigneeId = $data['assigned_to_id'];
        $assignee = $assigneeId === null ? null : User::query()->findOrFail($assigneeId);

        if ($assignee && ! $assignee->hasRole([
            UserRole::Manager,
            UserRole::Administrator,
            UserRole::SuperAdministrator,
        ])) {
            throw ValidationException::withMessages([
                'assigned_to_id' => 'Назначить заказ можно только сотруднику CRM.',
            ]);
        }

        $updated = DB::transaction(function () use ($order, $request, $assigneeId): Order {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $previousId = $lockedOrder->assigned_to_id;
            $lockedOrder->update(['assigned_to_id' => $assigneeId]);
            AuditLog::query()->create([
                'actor_user_id' => $request->user()->id,
                'event_key' => 'crm.order_assigned',
                'subject_type' => Order::class,
                'subject_id' => $lockedOrder->id,
                'metadata' => ['previous_assignee_id' => $previousId, 'assigned_to_id' => $assigneeId],
            ]);

            return $lockedOrder;
        });

        return new OrderResource($this->loadOrderDetails($updated));
    }

    public function updateStatus(
        Request $request,
        Order $order,
        OrderWorkflow $workflow,
        OrderNotificationDispatcher $notifications,
    ): OrderResource {
        $data = $request->validate([
            'order_status' => ['required', Rule::enum(OrderStatus::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = DB::transaction(function () use ($order, $workflow, $request, $data): Order {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $updatedOrder = $workflow->transition(
                $lockedOrder,
                OrderStatus::from($data['order_status']),
                $request->user(),
                $data['note'] ?? null,
            );
            AuditLog::query()->create([
                'actor_user_id' => $request->user()->id,
                'event_key' => 'crm.order_status_changed',
                'subject_type' => Order::class,
                'subject_id' => $lockedOrder->id,
                'metadata' => ['order_status' => $updatedOrder->order_status->value],
            ]);

            return $updatedOrder;
        });

        $notifications->orderStatusChanged($updated);

        return new OrderResource($this->loadOrderDetails($updated));
    }

    public function updatePaymentStatus(Request $request, Order $order): OrderResource
    {
        $data = $request->validate([
            'payment_status' => ['required', Rule::enum(PaymentStatus::class)],
            'amount_kzt' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = DB::transaction(function () use ($request, $order, $data): Order {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $current = $lockedOrder->payment_status;
            $next = PaymentStatus::from($data['payment_status']);
            if (! $current->canTransitionTo($next)) {
                throw ValidationException::withMessages([
                    'payment_status' => "Нельзя перевести оплату из статуса «{$current->value}» в «{$next->value}».",
                ]);
            }
            if ($next === PaymentStatus::Paid && $lockedOrder->total_kzt === null) {
                throw ValidationException::withMessages([
                    'payment_status' => 'Сначала согласуйте стоимость доставки и итоговую сумму заказа.',
                ]);
            }
            if ($next === PaymentStatus::PartiallyRefunded
                && (! isset($data['amount_kzt']) || $lockedOrder->total_kzt === null || $data['amount_kzt'] >= $lockedOrder->total_kzt)) {
                throw ValidationException::withMessages([
                    'amount_kzt' => 'Для частичного возврата укажите сумму меньше итоговой суммы заказа.',
                ]);
            }

            $lockedOrder->payment_status = $next;
            $lockedOrder->save();
            PaymentStatusHistory::query()->create([
                'order_id' => $lockedOrder->id,
                'actor_user_id' => $request->user()->id,
                'from_status' => $current,
                'to_status' => $next,
                'amount_kzt' => $data['amount_kzt'] ?? null,
                'note' => $data['note'] ?? null,
            ]);
            AuditLog::query()->create([
                'actor_user_id' => $request->user()->id,
                'event_key' => 'crm.order_payment_status_changed',
                'subject_type' => Order::class,
                'subject_id' => $lockedOrder->id,
                'metadata' => [
                    'from_status' => $current->value,
                    'to_status' => $next->value,
                    'amount_kzt' => $data['amount_kzt'] ?? null,
                ],
            ]);

            return $lockedOrder;
        });

        return new OrderResource($this->loadOrderDetails($updated));
    }

    public function updateDeliveryFee(Request $request, Order $order): OrderResource
    {
        $data = $request->validate([
            'delivery_fee_kzt' => ['required', 'integer', 'min:0'],
        ]);
        $updated = DB::transaction(function () use ($request, $order, $data): Order {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($lockedOrder->fulfillment_method !== 'delivery') {
                throw ValidationException::withMessages([
                    'delivery_fee_kzt' => 'Стоимость доставки можно задать только для доставки.',
                ]);
            }
            if (! in_array($lockedOrder->order_status, [OrderStatus::New, OrderStatus::Confirmed], true)
                || $lockedOrder->payment_status !== PaymentStatus::Unpaid) {
                throw ValidationException::withMessages([
                    'delivery_fee_kzt' => 'Стоимость можно изменить только для неоплаченного нового или подтверждённого заказа.',
                ]);
            }
            if ($lockedOrder->subtotal_kzt > PHP_INT_MAX - $data['delivery_fee_kzt']) {
                throw ValidationException::withMessages([
                    'delivery_fee_kzt' => 'Итоговая сумма заказа превышает допустимое значение.',
                ]);
            }

            $lockedOrder->update([
                'delivery_fee_kzt' => $data['delivery_fee_kzt'],
                'total_kzt' => $lockedOrder->subtotal_kzt + $data['delivery_fee_kzt'],
            ]);
            AuditLog::query()->create([
                'actor_user_id' => $request->user()->id,
                'event_key' => 'crm.order_delivery_fee_set',
                'subject_type' => Order::class,
                'subject_id' => $lockedOrder->id,
                'metadata' => ['delivery_fee_kzt' => $data['delivery_fee_kzt']],
            ]);

            return $lockedOrder;
        });

        return new OrderResource($this->loadOrderDetails($updated));
    }

    public function addNote(Request $request, Order $order): OrderResource
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $updated = DB::transaction(function () use ($request, $order, $data): Order {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            OrderInternalNote::query()->create([
                'order_id' => $lockedOrder->id,
                'author_user_id' => $request->user()->id,
                'body' => trim($data['body']),
            ]);
            AuditLog::query()->create([
                'actor_user_id' => $request->user()->id,
                'event_key' => 'crm.order_internal_note_added',
                'subject_type' => Order::class,
                'subject_id' => $lockedOrder->id,
                'metadata' => [],
            ]);

            return $lockedOrder;
        });

        return new OrderResource($this->loadOrderDetails($updated));
    }

    private function loadOrderDetails(Order $order): Order
    {
        return $order->load([
            'items',
            'assignee',
            'statusHistory.actor',
            'paymentStatusHistory.actor',
            'internalNotes.author',
        ]);
    }
}
