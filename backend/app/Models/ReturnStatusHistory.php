<?php

namespace App\Models;

use App\Enums\ReturnRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['return_request_id', 'actor_user_id', 'from_status', 'to_status', 'note', 'created_at'])]
class ReturnStatusHistory extends Model
{
    protected $table = 'return_status_history';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'from_status' => ReturnRequestStatus::class,
            'to_status' => ReturnRequestStatus::class,
        ];
    }

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
