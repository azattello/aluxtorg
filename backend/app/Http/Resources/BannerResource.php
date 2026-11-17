<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BannerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'placement' => $this->placement,
            'secure_url' => $this->secure_url,
            'alt_text' => $this->alt_text,
            'format' => $this->format,
            'width' => $this->width,
            'height' => $this->height,
            'bytes' => $this->bytes,
            'destination_url' => $this->destination_url,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
