<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['published', 'draft'])],
            'sort' => ['nullable', Rule::in(['updated_newest', 'name_asc', 'name_desc', 'price_asc', 'price_desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $sort = $filters['sort'] ?? 'updated_newest';

        $products = Product::query()
            ->withMin('variants', 'price_kzt')
            ->when(($filters['status'] ?? null) === 'published', fn (Builder $query): Builder => $query->where('is_published', true))
            ->when(($filters['status'] ?? null) === 'draft', fn (Builder $query): Builder => $query->where('is_published', false))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): Builder {
                return $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhereHas('variants', fn (Builder $variants): Builder => $variants->where('sku', 'like', "%{$search}%"));
                });
            })
            ->with([
                'category',
                'images' => fn (Relation $images): Relation => $images->orderBy('sort_order')->orderBy('id'),
                'variants' => fn (Relation $variants): Relation => $variants->orderBy('sort_order')->with('stockBalances'),
            ]);

        $products = match ($sort) {
            'name_asc' => $products->orderBy('name')->orderBy('id'),
            'name_desc' => $products->orderByDesc('name')->orderBy('id'),
            'price_asc' => $products->orderBy('variants_min_price_kzt')->orderBy('id'),
            'price_desc' => $products->orderByDesc('variants_min_price_kzt')->orderBy('id'),
            default => $products->orderByDesc('updated_at')->orderByDesc('id'),
        };
        $products = $products->paginate($filters['per_page'] ?? 30)->withQueryString();

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request): ProductResource
    {
        $data = $request->validated();
        $variants = $data['variants'];
        unset($data['variants']);
        $data['slug'] = $data['slug'] ?: $this->uniqueSlug($data['name']);
        $this->assertUniqueVariantSkus($variants);

        if ($data['is_published'] && ! Category::query()->whereKey($data['category_id'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'category_id' => ['Для публикации товара выберите активную категорию.'],
            ]);
        }
        if ($data['is_published'] && ! collect($variants)->contains(fn (array $variant): bool => $variant['is_active'])) {
            throw ValidationException::withMessages([
                'variants' => ['Для публикации товара нужен хотя бы один активный вариант.'],
            ]);
        }

        $product = DB::transaction(function () use ($data, $variants): Product {
            $product = Product::query()->create($data);
            foreach ($variants as $variant) {
                $product->variants()->create($variant);
            }

            return $product;
        });

        return new ProductResource($product->load(['category', 'images', 'variants.stockBalances']));
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $data = $request->validated();
        $variants = $data['variants'] ?? null;
        unset($data['variants']);
        if (array_key_exists('slug', $data) && ! $data['slug']) {
            unset($data['slug']);
        }

        if ($variants !== null) {
            $this->assertUniqueVariantSkus($variants);
            foreach ($variants as $index => $variant) {
                if (isset($variant['id']) && ! $product->variants()->whereKey($variant['id'])->exists()) {
                    throw ValidationException::withMessages([
                        "variants.{$index}.id" => ['Вариант не принадлежит этому товару.'],
                    ]);
                }
            }
        }

        DB::transaction(function () use ($data, $variants, $product): void {
            $categoryId = $data['category_id'] ?? $product->category_id;
            $isPublished = $data['is_published'] ?? $product->is_published;
            if ($isPublished && ! Category::query()->whereKey($categoryId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'category_id' => ['Для публикации товара выберите активную категорию.'],
                ]);
            }
            $hasActiveVariant = $variants === null
                ? $product->variants()->where('is_active', true)->exists()
                : collect($variants)->contains(fn (array $variant): bool => $variant['is_active']);
            if ($isPublished && ! $hasActiveVariant) {
                throw ValidationException::withMessages([
                    'variants' => ['Для публикации товара нужен хотя бы один активный вариант.'],
                ]);
            }

            $product->update($data);
            if ($variants === null) {
                return;
            }

            $keptIds = [];
            foreach ($variants as $variant) {
                $variantId = $variant['id'] ?? null;
                unset($variant['id']);
                if ($variantId) {
                    $product->variants()->whereKey($variantId)->update($variant);
                    $keptIds[] = $variantId;
                } else {
                    $keptIds[] = $product->variants()->create($variant)->id;
                }
            }

            $product->variants()->whereNotIn('id', $keptIds)->update(['is_active' => false]);
        });

        return new ProductResource($product->refresh()->load([
            'category',
            'images' => fn (Relation $images): Relation => $images->orderBy('sort_order')->orderBy('id'),
            'variants' => fn (Relation $query): Relation => $query->orderBy('sort_order')->with('stockBalances'),
        ]));
    }

    /**
     * @param  array<int, array<string, mixed>>  $variants
     */
    private function assertUniqueVariantSkus(array $variants): void
    {
        $seen = [];
        foreach ($variants as $index => $variant) {
            $sku = (string) $variant['sku'];
            if (isset($seen[$sku])) {
                throw ValidationException::withMessages([
                    "variants.{$index}.sku" => ['SKU должен быть уникальным в пределах каталога.'],
                ]);
            }

            $seen[$sku] = true;
            $duplicate = ProductVariant::query()
                ->where('sku', $sku)
                ->when(isset($variant['id']), fn (Builder $query): Builder => $query->where('id', '!=', $variant['id']))
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    "variants.{$index}.sku" => ['Такой SKU уже используется.'],
                ]);
            }
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name, '-', 'ru') ?: 'product';
        $slug = $base;
        $suffix = 2;

        while (Product::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    public function destroy(Product $product): JsonResponse
    {
        DB::transaction(function () use ($product): void {
            $product->update(['is_published' => false]);
            $product->variants()->update(['is_active' => false]);
        });

        return response()->json(['message' => 'Товар снят с публикации, история заказов сохранена.']);
    }
}
