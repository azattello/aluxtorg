<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'phone',
    'whatsapp',
    'email',
    'address',
    'working_hours',
    'instagram_url',
    'telegram_url',
])]
class StoreSetting extends Model
{
    protected $table = 'store_settings';
}
