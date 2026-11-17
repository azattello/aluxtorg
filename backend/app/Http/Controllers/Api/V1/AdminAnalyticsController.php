<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminAnalyticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date', 'before_or_equal:today'],
            'to' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $from = Carbon::parse($validated['from'] ?? now()->subDays(29)->toDateString())->startOfDay();
        $to = Carbon::parse($validated['to'] ?? now()->toDateString())->endOfDay();

        if ($from->greaterThan($to) || $from->diffInDays($to) > 365) {
            throw ValidationException::withMessages([
                'from' => ['Период статистики должен быть не длиннее 366 дней, а дата начала не может быть позже даты окончания.'],
            ]);
        }

        $orders = Order::query()->whereBetween('created_at', [$from, $to]);
        $views = DB::table('storefront_daily_views')
            ->whereBetween('view_date', [$from->toDateString(), $to->toDateString()]);

        $summary = [
            'orders_count' => (clone $orders)->count(),
            'order_value_kzt' => (int) (clone $orders)
                ->selectRaw('COALESCE(SUM(COALESCE(total_kzt, subtotal_kzt)), 0) as total')
                ->value('total'),
            'paid_orders_count' => (clone $orders)->where('payment_status', 'paid')->count(),
            'buyers_count' => (clone $orders)->distinct()->count('customer_phone'),
            'page_views' => (int) (clone $views)->sum('views'),
        ];

        $ordersByDay = (clone $orders)
            ->selectRaw('DATE(created_at) as date')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(COALESCE(total_kzt, subtotal_kzt)), 0) as value_kzt')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get();
        $orderDays = $ordersByDay->keyBy('date');
        $ordersByDay = collect(CarbonPeriod::create($from->toDateString(), $to->toDateString()))
            ->map(fn (Carbon $date): array => [
                'date' => $date->toDateString(),
                'orders_count' => (int) ($orderDays[$date->toDateString()]->orders_count ?? 0),
                'value_kzt' => (int) ($orderDays[$date->toDateString()]->value_kzt ?? 0),
            ]);

        $ordersByStatus = (clone $orders)
            ->select('order_status')
            ->selectRaw('COUNT(*) as orders_count')
            ->groupBy('order_status')
            ->orderBy('order_status')
            ->get();

        $ordersByCity = (clone $orders)
            ->selectRaw("CASE WHEN fulfillment_method = 'pickup' THEN 'Самовывоз' ELSE COALESCE(NULLIF(delivery_city, ''), 'Не указано') END as city")
            ->selectRaw('COUNT(*) as orders_count')
            ->groupByRaw("CASE WHEN fulfillment_method = 'pickup' THEN 'Самовывоз' ELSE COALESCE(NULLIF(delivery_city, ''), 'Не указано') END")
            ->orderByDesc('orders_count')
            ->limit(10)
            ->get();

        $viewsByDay = (clone $views)
            ->select('view_date as date')
            ->selectRaw('SUM(views) as views')
            ->groupBy('view_date')
            ->orderBy('view_date')
            ->get();
        $viewDays = $viewsByDay->keyBy('date');
        $viewsByDay = collect(CarbonPeriod::create($from->toDateString(), $to->toDateString()))
            ->map(fn (Carbon $date): array => [
                'date' => $date->toDateString(),
                'views' => (int) ($viewDays[$date->toDateString()]->views ?? 0),
            ]);

        $viewsByType = (clone $views)
            ->select('page_type')
            ->selectRaw('SUM(views) as views')
            ->groupBy('page_type')
            ->orderBy('page_type')
            ->get();

        $popularRows = (clone $views)
            ->select('page_type', 'resource_key')
            ->selectRaw('SUM(views) as views')
            ->groupBy('page_type', 'resource_key')
            ->orderByDesc('views')
            ->limit(10)
            ->get();

        $productNames = Product::query()
            ->whereIn('slug', $popularRows->where('page_type', 'product')->pluck('resource_key'))
            ->pluck('name', 'slug');
        $categoryNames = Category::query()
            ->whereIn('slug', $popularRows->where('page_type', 'category')->pluck('resource_key'))
            ->pluck('name', 'slug');

        $popularPages = $popularRows->map(function (object $row) use ($productNames, $categoryNames): array {
            $title = match ($row->page_type) {
                'home' => 'Главная страница',
                'product' => $productNames[$row->resource_key] ?? $row->resource_key,
                'category' => $categoryNames[$row->resource_key] ?? $row->resource_key,
                default => $row->resource_key,
            };

            return [
                'page_type' => $row->page_type,
                'resource_key' => $row->resource_key,
                'title' => $title,
                'views' => (int) $row->views,
            ];
        });

        $recentOrders = (clone $orders)
            ->select([
                'id',
                'number',
                'customer_name',
                'customer_phone',
                'delivery_city',
                'fulfillment_method',
                'order_status',
                'payment_status',
                'subtotal_kzt',
                'total_kzt',
                'created_at',
            ])
            ->latest('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'summary' => $summary,
                'orders_by_day' => $ordersByDay,
                'orders_by_status' => $ordersByStatus,
                'orders_by_city' => $ordersByCity,
                'views_by_day' => $viewsByDay,
                'views_by_type' => $viewsByType,
                'popular_pages' => $popularPages,
                'recent_orders' => $recentOrders,
            ],
        ]);
    }
}
