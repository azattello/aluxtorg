<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'assigned_to' => $this->whenLoaded('assignee', fn (): ?array => $this->assignee ? [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ] : null),
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
            'status_history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($entry): array => [
                'from_status' => $entry->from_status?->value,
                'to_status' => $entry->to_status->value,
                'note' => $entry->note,
                'actor' => $entry->actor ? ['id' => $entry->actor->id, 'name' => $entry->actor->name] : null,
                'created_at' => $entry->created_at?->toISOString(),
            ])),
            'payment_status_history' => $this->whenLoaded('paymentStatusHistory', fn () => $this->paymentStatusHistory->map(fn ($entry): array => [
                'from_status' => $entry->from_status?->value,
                'to_status' => $entry->to_status->value,
                'amount_kzt' => $entry->amount_kzt,
                'note' => $entry->note,
                'actor' => $entry->actor ? ['id' => $entry->actor->id, 'name' => $entry->actor->name] : null,
                'created_at' => $entry->created_at?->toISOString(),
            ])),
            'internal_notes' => $this->whenLoaded('internalNotes', fn () => $this->internalNotes->map(fn ($entry): array => [
                'id' => $entry->id,
                'body' => $entry->body,
                'author' => $entry->author ? ['id' => $entry->author->id, 'name' => $entry->author->name] : null,
                'created_at' => $entry->created_at?->toISOString(),
            ])),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
