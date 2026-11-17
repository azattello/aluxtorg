<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'assigned_to_id',
    'number',
    'order_status',
    'payment_status',
    'fulfillment_method',
    'customer_name',
    'customer_phone',
    'customer_email',
    'customer_notifications_opted_in_at',
    'customer_notifications_opted_out_at',
    'delivery_city',
    'delivery_street',
    'delivery_building',
    'delivery_apartment',
    'delivery_instructions',
    'subtotal_kzt',
    'delivery_fee_kzt',
    'total_kzt',
    'idempotency_key',
])]
class Order extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'order_status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'customer_notifications_opted_in_at' => 'datetime',
            'customer_notifications_opted_out_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function paymentStatusHistory(): HasMany
    {
        return $this->hasMany(PaymentStatusHistory::class);
    }

    public function stockReservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function internalNotes(): HasMany
    {
        return $this->hasMany(OrderInternalNote::class);
    }

    public function returnRequests(): HasMany
    {
        return $this->hasMany(ReturnRequest::class);
    }
}
