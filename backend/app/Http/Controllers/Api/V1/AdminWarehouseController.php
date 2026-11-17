<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\LowStockAlertService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminWarehouseController extends Controller
{
    public function balances(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'low_stock' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $threshold = LowStockAlertService::THRESHOLD;
        $warehouseIds = Warehouse::query()->where('is_active', true)->pluck('id');
        $balances = StockBalance::query()
            ->with(['warehouse', 'variant.product'])
            ->whereIn('warehouse_id', $warehouseIds)
            ->when(
                filter_var($filters['low_stock'] ?? false, FILTER_VALIDATE_BOOLEAN),
                fn (Builder $query): Builder => $query->whereRaw('on_hand - reserved <= ?', [$threshold]),
            )
            ->when($filters['variant_id'] ?? null, fn (Builder $query, int $variantId): Builder => $query->where('product_variant_id', $variantId))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): Builder {
                return $query->whereHas('variant', fn (Builder $variant): Builder => $variant
                    ->where('sku', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhereHas('product', fn (Builder $product): Builder => $product->where('name', 'like', "%{$search}%")));
            })
            ->orderBy('product_variant_id')
            ->paginate($filters['per_page'] ?? 50);

        return response()->json([
            'data' => $balances->getCollection()->map(fn (StockBalance $balance): array => [
                'id' => $balance->id,
                'warehouse_id' => $balance->warehouse_id,
                'warehouse_name' => $balance->warehouse->name,
                'variant_id' => $balance->product_variant_id,
                'sku' => $balance->variant->sku,
                'variant_name' => $balance->variant->name,
                'product_name' => $balance->variant->product->name,
                'on_hand' => $balance->on_hand,
                'reserved' => $balance->reserved,
                'available' => $balance->on_hand - $balance->reserved,
            ])->values(),
            'meta' => [
                'current_page' => $balances->currentPage(),
                'last_page' => $balances->lastPage(),
                'per_page' => $balances->perPage(),
                'total' => $balances->total(),
                'low_stock_threshold' => $threshold,
                'low_stock_count' => StockBalance::query()
                    ->whereIn('warehouse_id', $warehouseIds)
                    ->whereRaw('on_hand - reserved <= ?', [$threshold])
                    ->count(),
            ],
        ]);
    }

    public function variants(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $variants = ProductVariant::query()
            ->with('product:id,name')
            ->when($filters['search'] ?? null, function (Builder $query, string $search): Builder {
                return $query->where(function (Builder $variantQuery) use ($search): void {
                    $variantQuery->where('sku', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhereHas('product', fn (Builder $product): Builder => $product->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderBy('sku')
            ->paginate($filters['per_page'] ?? 50);

        return response()->json([
            'data' => $variants->getCollection()->map(fn (ProductVariant $variant): array => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'name' => $variant->name,
                'product_name' => $variant->product->name,
            ])->values(),
            'meta' => [
                'current_page' => $variants->currentPage(),
                'last_page' => $variants->lastPage(),
                'per_page' => $variants->perPage(),
                'total' => $variants->total(),
            ],
        ]);
    }

    public function movements(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', 'in:receipt,dispatch,adjustment,correction'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $movements = StockMovement::query()
            ->with(['warehouse', 'variant.product', 'actor'])
            ->when($filters['type'] ?? null, fn (Builder $query, string $type): Builder => $query->where('type', $type))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): Builder {
                return $query->whereHas('variant', fn (Builder $variant): Builder => $variant
                    ->where('sku', 'like', "%{$search}%")
                    ->orWhereHas('product', fn (Builder $product): Builder => $product->where('name', 'like', "%{$search}%")));
            })
            ->latest('id')
            ->paginate($filters['per_page'] ?? 30);

        return response()->json([
            'data' => $movements->getCollection()->map(fn (StockMovement $movement): array => [
                'id' => $movement->id,
                'type' => $movement->type,
                'quantity_delta' => $movement->quantity_delta,
                'note' => $movement->note,
                'sku' => $movement->variant->sku,
                'product_name' => $movement->variant->product->name,
                'warehouse_name' => $movement->warehouse->name,
                'actor_name' => $movement->actor?->name,
                'created_at' => $movement->created_at?->toISOString(),
            ])->values(),
            'meta' => [
                'current_page' => $movements->currentPage(),
                'last_page' => $movements->lastPage(),
                'per_page' => $movements->perPage(),
                'total' => $movements->total(),
            ],
        ]);
    }

    public function receive(Request $request, LowStockAlertService $lowStockAlerts): JsonResponse
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'note' => ['required', 'string', 'max:1000'],
        ]);

        $movement = $this->recordStockChange(
            $data['variant_id'],
            $data['quantity'],
            'receipt',
            trim($data['note']),
            $request,
            $lowStockAlerts,
        );

        return response()->json(['data' => $movement], 201);
    }

    public function reconcile(Request $request, LowStockAlertService $lowStockAlerts): JsonResponse
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'counted_on_hand' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'note' => ['required', 'string', 'max:1000'],
        ]);

        $warehouse = $this->activeWarehouse();
        $result = DB::transaction(function () use ($data, $request, $warehouse, $lowStockAlerts): array {
            $this->ensureBalance($warehouse->id, $data['variant_id']);
            $balance = StockBalance::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('product_variant_id', $data['variant_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $delta = $data['counted_on_hand'] - $balance->on_hand;

            if ($data['counted_on_hand'] < $balance->reserved) {
                throw ValidationException::withMessages([
                    'counted_on_hand' => 'Фактический остаток не может быть меньше уже зарезервированного.',
                ]);
            }

            if ($delta === 0) {
                return ['message' => 'Фактический остаток уже совпадает.', 'movement' => null];
            }

            $previousOnHand = $balance->on_hand;
            $previousAvailable = $balance->on_hand - $balance->reserved;
            $balance->update(['on_hand' => $data['counted_on_hand']]);
            $balance->refresh();
            $lowStockAlerts->recordAvailabilityChange(
                $balance,
                $previousAvailable,
                $balance->on_hand - $balance->reserved,
            );
            $movement = StockMovement::query()->create([
                'warehouse_id' => $warehouse->id,
                'product_variant_id' => $data['variant_id'],
                'actor_user_id' => $request->user()->id,
                'type' => 'adjustment',
                'quantity_delta' => $delta,
                'note' => trim($data['note']),
            ]);
            AuditLog::query()->create([
                'actor_user_id' => $request->user()->id,
                'event_key' => 'warehouse.stock_reconciled',
                'subject_type' => StockBalance::class,
                'subject_id' => $balance->id,
                'metadata' => [
                    'variant_id' => $data['variant_id'],
                    'previous_on_hand' => $previousOnHand,
                    'counted_on_hand' => $data['counted_on_hand'],
                    'quantity_delta' => $delta,
                ],
            ]);

            return ['message' => 'Остаток сверён и движение записано.', 'movement' => $movement];
        });

        return response()->json([
            'message' => $result['message'],
            'data' => $result['movement'],
        ]);
    }

    private function recordStockChange(
        int $variantId,
        int $quantity,
        string $type,
        string $note,
        Request $request,
        LowStockAlertService $lowStockAlerts,
    ): array {
        $warehouse = $this->activeWarehouse();

        return DB::transaction(function () use ($variantId, $quantity, $type, $note, $request, $warehouse, $lowStockAlerts): array {
            $this->ensureBalance($warehouse->id, $variantId);
            $balance = StockBalance::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('product_variant_id', $variantId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($balance->on_hand > 2147483647 - $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => 'Остаток превышает допустимое значение.',
                ]);
            }

            $previousAvailable = $balance->on_hand - $balance->reserved;
            $balance->increment('on_hand', $quantity);
            $balance->refresh();
            $lowStockAlerts->recordAvailabilityChange(
                $balance,
                $previousAvailable,
                $balance->on_hand - $balance->reserved,
            );
            $movement = StockMovement::query()->create([
                'warehouse_id' => $warehouse->id,
                'product_variant_id' => $variantId,
                'actor_user_id' => $request->user()->id,
                'type' => $type,
                'quantity_delta' => $quantity,
                'note' => $note,
            ]);
            AuditLog::query()->create([
                'actor_user_id' => $request->user()->id,
                'event_key' => 'warehouse.stock_received',
                'subject_type' => StockBalance::class,
                'subject_id' => $balance->id,
                'metadata' => ['variant_id' => $variantId, 'quantity_delta' => $quantity],
            ]);

            return [
                'id' => $movement->id,
                'quantity_delta' => $movement->quantity_delta,
                'type' => $movement->type,
                'note' => $movement->note,
                'on_hand' => $balance->on_hand,
            ];
        });
    }

    private function ensureBalance(int $warehouseId, int $variantId): void
    {
        DB::table('stock_balances')->insertOrIgnore([
            'warehouse_id' => $warehouseId,
            'product_variant_id' => $variantId,
            'on_hand' => 0,
            'reserved' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function activeWarehouse(): Warehouse
    {
        return Warehouse::query()->where('is_active', true)->orderBy('id')->first()
            ?? throw ValidationException::withMessages([
                'warehouse' => 'Нет активного склада для операции.',
            ]);
    }
}
