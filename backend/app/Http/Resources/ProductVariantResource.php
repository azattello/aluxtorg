<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $available = $this->stockBalances->sum(fn ($balance): int => max(0, $balance->on_hand - $balance->reserved));

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'sale_unit' => $this->sale_unit,
            'is_returnable' => $this->is_returnable,
            'attributes' => $this->attributes ?? [],
            'price_kzt' => $this->price_kzt,
            'is_active' => $this->is_active,
            'available' => $available > 0,
            'available_quantity' => $request->user()?->can('manage-warehouse') ? $available : null,
            'sort_order' => $this->sort_order,
        ];
    }
}
