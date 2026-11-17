<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReturnRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'condition_confirmed_at' => $this->condition_confirmed_at?->toISOString(),
            'order' => $this->whenLoaded('order', fn (): array => [
                'id' => $this->order->id,
                'number' => $this->order->number,
                'customer_name' => $this->order->customer_name,
                'customer_phone' => $this->order->customer_phone,
                'customer_email' => $this->order->customer_email,
            ]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'order_item_id' => $item->order_item_id,
                'product_name' => $item->orderItem->product_name_snapshot,
                'variant_name' => $item->orderItem->variant_name_snapshot,
                'sku' => $item->orderItem->sku_snapshot,
                'sale_unit' => $item->orderItem->sale_unit_snapshot,
                'quantity' => $item->quantity,
            ])->values()),
            'status_history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($entry): array => [
                'from_status' => $entry->from_status?->value,
                'to_status' => $entry->to_status->value,
                'note' => $request->user()?->can('manage-crm') ? $entry->note : null,
                'actor' => $request->user()?->can('manage-crm') && $entry->actor
                    ? ['id' => $entry->actor->id, 'name' => $entry->actor->name]
                    : null,
                'created_at' => $entry->created_at?->toISOString(),
            ])->values()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
