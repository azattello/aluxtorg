<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_name' => $this->product_name_snapshot,
            'variant_name' => $this->variant_name_snapshot,
            'sku' => $this->sku_snapshot,
            'attributes' => $this->variant_attributes_snapshot,
            'sale_unit' => $this->sale_unit_snapshot,
            'is_returnable' => $this->is_returnable_snapshot,
            'remaining_returnable_quantity' => $this->when(
                array_key_exists('remaining_returnable_quantity', $this->resource->getAttributes()),
                $this->remaining_returnable_quantity,
            ),
            'unit_price_kzt' => $this->unit_price_kzt,
            'quantity' => $this->quantity,
            'line_total_kzt' => $this->line_total_kzt,
        ];
    }
}
