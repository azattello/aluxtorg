<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StorefrontViewController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page_type' => ['required', Rule::in(['home', 'category', 'product'])],
            'resource_key' => ['required_unless:page_type,home', 'nullable', 'string', 'max:191'],
        ]);

        if ($validated['page_type'] === 'product' && ! Product::query()->where('slug', $validated['resource_key'])->where('is_published', true)->exists()) {
            throw ValidationException::withMessages(['resource_key' => ['Товар не найден.']]);
        }

        if ($validated['page_type'] === 'category' && ! Category::query()->where('slug', $validated['resource_key'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['resource_key' => ['Категория не найдена.']]);
        }

        $now = now();
        DB::table('storefront_daily_views')->upsert(
            [[
                'view_date' => $now->toDateString(),
                'page_type' => $validated['page_type'],
                'resource_key' => $validated['page_type'] === 'home' ? 'home' : $validated['resource_key'],
                'views' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['view_date', 'page_type', 'resource_key'],
            [
                'views' => DB::raw('storefront_daily_views.views + 1'),
                'updated_at',
            ],
        );

        return response()->json(['data' => ['recorded' => true]], 201);
    }
}
