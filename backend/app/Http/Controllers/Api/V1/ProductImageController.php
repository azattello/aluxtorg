<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\UploadProductImageRequest;
use App\Http\Resources\ProductImageResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\CloudinaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductImageController extends Controller
{
    public function store(UploadProductImageRequest $request, Product $product, CloudinaryService $cloudinary): ProductImageResource
    {
        $data = $request->validated();
        if (isset($data['product_variant_id']) && ! $product->variants()->whereKey($data['product_variant_id'])->exists()) {
            abort(422, 'Выбранный вариант не принадлежит этому товару.');
        }

        $asset = $cloudinary->upload($data['image'], 'products');
        $image = $product->images()->create([
            'product_variant_id' => $data['product_variant_id'] ?? null,
            'cloudinary_public_id' => $asset['public_id'],
            'secure_url' => $asset['secure_url'],
            'alt_text' => $data['alt_text'],
            'sort_order' => (int) $product->images()->max('sort_order') + 1,
            'format' => $asset['format'],
            'width' => $asset['width'],
            'height' => $asset['height'],
            'bytes' => $asset['bytes'],
        ]);

        return new ProductImageResource($image);
    }

    public function update(Request $request, Product $product, ProductImage $image): ProductImageResource|JsonResponse
    {
        abort_unless($image->product_id === $product->id, 404);
        $data = $request->validate([
            'alt_text' => ['sometimes', 'required', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000'],
        ]);
        $image->update($data);

        return new ProductImageResource($image->refresh());
    }

    public function destroy(Product $product, ProductImage $image, CloudinaryService $cloudinary): JsonResponse
    {
        abort_unless($image->product_id === $product->id, 404);
        $cloudinary->destroy($image->cloudinary_public_id);
        $image->delete();

        return response()->json(['message' => 'Изображение удалено.']);
    }
}
