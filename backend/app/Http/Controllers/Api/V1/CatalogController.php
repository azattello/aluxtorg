<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CatalogIndexRequest;
use App\Http\Resources\BannerResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductResource;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CatalogController extends Controller
{
    public function categories(): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            Category::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        );
    }

    public function products(CatalogIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = Product::query()
            ->where('is_published', true)
            ->whereHas('category', fn (Builder $category): Builder => $category->where('is_active', true))
            ->with([
                'category',
                'images' => fn (Relation $images): Relation => $images->orderBy('sort_order')->orderBy('id'),
                'variants' => fn (Relation $variants): Relation => $variants
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->with('stockBalances'),
            ]);

        if (isset($filters['q'])) {
            $term = mb_strtolower(str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filters['q'])));
            $query->where(function (Builder $search) use ($term): void {
                $search->whereRaw("LOWER(products.name) LIKE ? ESCAPE '!'", ["%{$term}%"])
                    ->orWhereHas('variants', fn (Builder $variants): Builder => $variants
                        ->where('is_active', true)
                        ->whereRaw("LOWER(product_variants.sku) LIKE ? ESCAPE '!'", ["%{$term}%"]));
            });
        }

        if (isset($filters['category_id'])) {
            $categoryIds = [(int) $filters['category_id']];
            $frontier = $categoryIds;
            while ($frontier !== []) {
                $children = Category::query()
                    ->where('is_active', true)
                    ->whereIn('parent_id', $frontier)
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->reject(fn (int $id): bool => in_array($id, $categoryIds, true))
                    ->values()
                    ->all();
                $categoryIds = [...$categoryIds, ...$children];
                $frontier = $children;
            }

            $query->whereIn('category_id', $categoryIds);
        }

        if (isset($filters['min_price']) || isset($filters['max_price'])) {
            $query->whereHas('variants', function (Builder $variants) use ($filters): void {
                $variants->where('is_active', true)
                    ->when(isset($filters['min_price']), fn (Builder $builder): Builder => $builder->where('price_kzt', '>=', $filters['min_price']))
                    ->when(isset($filters['max_price']), fn (Builder $builder): Builder => $builder->where('price_kzt', '<=', $filters['max_price']));
            });
        }

        if (array_key_exists('available', $filters)) {
            $availabilityQuery = fn (Builder $variants): Builder => $variants
                ->where('is_active', true)
                ->whereHas('stockBalances', fn (Builder $balances): Builder => $balances->whereColumn('on_hand', '>', 'reserved'));
            if (filter_var($filters['available'], FILTER_VALIDATE_BOOLEAN)) {
                $query->whereHas('variants', $availabilityQuery);
            } else {
                $query->whereDoesntHave('variants', $availabilityQuery);
            }
        }

        match ($filters['sort'] ?? 'relevance') {
            'name' => $query->orderBy('name')->orderBy('id'),
            'price_asc' => $query->withMin(['variants as min_price_kzt' => fn (Builder $variants): Builder => $variants->where('is_active', true)], 'price_kzt')
                ->orderBy('min_price_kzt')->orderBy('id'),
            'price_desc' => $query->withMin(['variants as min_price_kzt' => fn (Builder $variants): Builder => $variants->where('is_active', true)], 'price_kzt')
                ->orderByDesc('min_price_kzt')->orderBy('id'),
            'newest' => $query->orderByDesc('created_at')->orderByDesc('id'),
            default => $query->orderBy('sort_order')->orderBy('name')->orderBy('id'),
        };

        return ProductResource::collection($query->paginate($filters['per_page'] ?? 16)->withQueryString());
    }

    public function product(string $slug): ProductResource
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->whereHas('category', fn (Builder $category): Builder => $category->where('is_active', true))
            ->with([
                'category',
                'images' => fn (Relation $images): Relation => $images->orderBy('sort_order')->orderBy('id'),
                'variants' => fn (Relation $variants): Relation => $variants
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->with('stockBalances'),
            ])
            ->firstOrFail();

        return new ProductResource($product);
    }

    public function banners(): AnonymousResourceCollection
    {
        $now = now();

        return BannerResource::collection(
            Banner::query()
                ->where('is_active', true)
                ->where(fn (Builder $query): Builder => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(fn (Builder $query): Builder => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
        );
    }
}
