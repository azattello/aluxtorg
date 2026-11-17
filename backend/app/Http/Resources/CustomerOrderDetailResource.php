<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerOrderDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'order_status' => $this->order_status->value,
            'payment_status' => $this->payment_status->value,
            'fulfillment_method' => $this->fulfillment_method,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'customer_email' => $this->customer_email,
            'delivery_city' => $this->delivery_city,
            'delivery_street' => $this->delivery_street,
            'delivery_building' => $this->delivery_building,
            'delivery_apartment' => $this->delivery_apartment,
            'delivery_instructions' => $this->delivery_instructions,
            'subtotal_kzt' => $this->subtotal_kzt,
            'delivery_fee_kzt' => $this->delivery_fee_kzt,
            'total_kzt' => $this->total_kzt,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'can_request_return' => (bool) $this->can_request_return,
            'return_deadline_at' => $this->return_deadline_at,
            'return_requests' => ReturnRequestResource::collection($this->whenLoaded('returnRequests')),
            'status_history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($entry): array => [
                'from_status' => $entry->from_status?->value,
                'to_status' => $entry->to_status->value,
                'created_at' => $entry->created_at?->toISOString(),
            ])->values()),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
