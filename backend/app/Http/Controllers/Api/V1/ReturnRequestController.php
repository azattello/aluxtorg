<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\StoreReturnRequest;
use App\Http\Resources\ReturnRequestResource;
use App\Models\Order;
use App\Services\ReturnRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ReturnRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ReturnRequestResource::collection(
            $request->user()->returnRequests()
                ->with(['items.orderItem', 'statusHistory'])
                ->latest('id')
                ->paginate(25),
        );
    }

    public function store(StoreReturnRequest $request, Order $order, ReturnRequestService $returns): JsonResponse
    {
        $returnRequest = $returns->create($order, $request->user(), $request->validated());

        return (new ReturnRequestResource($returnRequest))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, \App\Models\ReturnRequest $returnRequest): ReturnRequestResource
    {
        abort_unless($returnRequest->user_id === $request->user()->id, 404);

        return new ReturnRequestResource($returnRequest->load(['items.orderItem', 'statusHistory']));
    }
}
