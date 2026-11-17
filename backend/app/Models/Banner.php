<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'title',
    'placement',
    'cloudinary_public_id',
    'secure_url',
    'alt_text',
    'format',
    'width',
    'height',
    'bytes',
    'destination_url',
    'starts_at',
    'ends_at',
    'is_active',
    'sort_order',
])]
class Banner extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
        ];
    }
}
