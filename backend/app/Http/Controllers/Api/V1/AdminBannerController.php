<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreBannerRequest;
use App\Http\Requests\Catalog\UpdateBannerRequest;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use App\Services\CloudinaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AdminBannerController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return BannerResource::collection(Banner::query()->orderBy('placement')->orderBy('sort_order')->paginate(30));
    }

    public function store(StoreBannerRequest $request, CloudinaryService $cloudinary): BannerResource
    {
        $data = $request->validated();
        $asset = $cloudinary->upload($data['image'], 'banners');
        unset($data['image']);
        $banner = Banner::query()->create([
            ...$data,
            'cloudinary_public_id' => $asset['public_id'],
            'secure_url' => $asset['secure_url'],
            'format' => $asset['format'],
            'width' => $asset['width'],
            'height' => $asset['height'],
            'bytes' => $asset['bytes'],
        ]);

        return new BannerResource($banner);
    }

    public function update(UpdateBannerRequest $request, Banner $banner, CloudinaryService $cloudinary): BannerResource
    {
        $data = $request->validated();
        $startsAt = array_key_exists('starts_at', $data) ? $data['starts_at'] : $banner->starts_at;
        $endsAt = array_key_exists('ends_at', $data) ? $data['ends_at'] : $banner->ends_at;
        if ($startsAt && $endsAt && Carbon::parse($endsAt)->lessThanOrEqualTo(Carbon::parse($startsAt))) {
            throw ValidationException::withMessages([
                'ends_at' => ['Дата окончания должна быть позже даты начала.'],
            ]);
        }

        $asset = isset($data['image']) ? $cloudinary->upload($data['image'], 'banners') : null;
        unset($data['image']);
        $oldPublicId = $asset ? $banner->cloudinary_public_id : null;

        $banner->update([
            ...$data,
            ...($asset ? [
                'cloudinary_public_id' => $asset['public_id'],
                'secure_url' => $asset['secure_url'],
                'format' => $asset['format'],
                'width' => $asset['width'],
                'height' => $asset['height'],
                'bytes' => $asset['bytes'],
            ] : []),
        ]);

        if ($oldPublicId) {
            $cloudinary->destroy($oldPublicId);
        }

        return new BannerResource($banner->refresh());
    }

    public function destroy(Banner $banner, CloudinaryService $cloudinary): JsonResponse
    {
        $cloudinary->destroy($banner->cloudinary_public_id);
        $banner->delete();

        return response()->json(['message' => 'Баннер удалён.']);
    }
}
