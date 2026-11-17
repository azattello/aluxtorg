<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'warehouse_id',
    'product_variant_id',
    'order_id',
    'order_item_id',
    'actor_user_id',
    'correction_of_movement_id',
    'type',
    'quantity_delta',
    'note',
    'created_at',
])]
class StockMovement extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function correctedMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'correction_of_movement_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'correction_of_movement_id');
    }
}
