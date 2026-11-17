<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'warehouse_id',
    'product_variant_id',
    'is_active',
    'activation_key',
    'triggered_at',
    'resolved_at',
])]
class LowStockAlert extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'triggered_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
