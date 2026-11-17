<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReturnRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReturnRequestResource;
use App\Models\ReturnRequest;
use App\Services\ReturnRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AdminReturnRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ReturnRequestStatus::class)],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $requests = ReturnRequest::query()
            ->with(['order', 'items.orderItem', 'statusHistory.actor'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): Builder {
                return $query->whereHas('order', fn (Builder $orders): Builder => $orders
                    ->where('number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%"));
            })
            ->when(
                ($filters['sort'] ?? 'newest') === 'oldest',
                fn (Builder $query): Builder => $query->orderBy('created_at')->orderBy('id'),
                fn (Builder $query): Builder => $query->orderByDesc('created_at')->orderByDesc('id'),
            )
            ->paginate($filters['per_page'] ?? 25);

        return ReturnRequestResource::collection($requests);
    }

    public function show(ReturnRequest $returnRequest): ReturnRequestResource
    {
        return new ReturnRequestResource($returnRequest->load([
            'order',
            'items.orderItem',
            'statusHistory.actor',
        ]));
    }

    public function updateStatus(Request $request, ReturnRequest $returnRequest, ReturnRequestService $returns): ReturnRequestResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(ReturnRequestStatus::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return new ReturnRequestResource($returns->transition(
            $returnRequest,
            ReturnRequestStatus::from($data['status']),
            $request->user(),
            $data['note'] ?? null,
        ));
    }
}
