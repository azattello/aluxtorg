<?php

namespace App\Http\Resources;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $variants = $this->whenLoaded('variants');
        $prices = $variants instanceof Collection
            ? $variants->where('is_active', true)->pluck('price_kzt')
            : collect();

        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'is_published' => $this->is_published,
            'sort_order' => $this->sort_order,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'min_price_kzt' => $prices->isNotEmpty() ? $prices->min() : null,
            'max_price_kzt' => $prices->isNotEmpty() ? $prices->max() : null,
            'available' => $variants instanceof Collection
                && $variants->contains(fn ($variant): bool => $variant->is_active && $variant->stockBalances->contains(
                    fn ($balance): bool => $balance->on_hand > $balance->reserved,
                )),
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
        ];
    }
}
