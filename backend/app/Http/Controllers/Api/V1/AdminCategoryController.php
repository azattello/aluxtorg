<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreCategoryRequest;
use App\Http\Requests\Catalog\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class AdminCategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(Category::query()->orderBy('sort_order')->orderBy('name')->paginate(50));
    }

    public function store(StoreCategoryRequest $request): CategoryResource
    {
        $data = $request->validated();
        $data['slug'] = $data['slug'] ?: $this->uniqueSlug($data['name']);
        $category = Category::query()->create($data);

        return new CategoryResource($category);
    }

    public function update(UpdateCategoryRequest $request, Category $category): CategoryResource|JsonResponse
    {
        $parentId = $request->validated('parent_id');
        while ($parentId) {
            if ((int) $parentId === $category->id) {
                return response()->json(['message' => 'Нельзя переместить категорию внутрь её дочерней категории.'], 422);
            }

            $parentId = Category::query()->whereKey($parentId)->value('parent_id');
        }

        $data = $request->validated();
        if (array_key_exists('slug', $data) && ! $data['slug']) {
            unset($data['slug']);
        }
        $category->update($data);

        return new CategoryResource($category->refresh());
    }

    public function destroy(Category $category): JsonResponse
    {
        if ($category->products()->exists() || $category->children()->exists()) {
            return response()->json([
                'message' => 'Категорию с товарами или дочерними категориями нельзя удалить. Сначала переместите связанные записи.',
            ], 409);
        }

        $category->delete();

        return response()->json(['message' => 'Категория удалена.']);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name, '-', 'ru') ?: 'category';
        $slug = $base;
        $suffix = 2;

        while (Category::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
