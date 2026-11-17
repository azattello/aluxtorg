<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StoreSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreSettingsController extends Controller
{
    private const SETTINGS_ID = 1;

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->settings()]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'max:32'],
            'whatsapp' => ['nullable', 'string', 'max:128'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:254'],
            'address' => ['nullable', 'string', 'max:255'],
            'working_hours' => ['nullable', 'string', 'max:255'],
            'instagram_url' => ['nullable', 'url', 'max:2048'],
            'telegram_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $settings = StoreSetting::query()->findOrFail(self::SETTINGS_ID);
        $settings->fill($validated)->save();

        return response()->json([
            'message' => 'Контактные данные магазина сохранены.',
            'data' => $this->present($settings->refresh()),
        ]);
    }

    private function settings(): array
    {
        return $this->present(StoreSetting::query()->findOrFail(self::SETTINGS_ID));
    }

    private function present(StoreSetting $settings): array
    {
        return $settings->only([
            'phone',
            'whatsapp',
            'email',
            'address',
            'working_hours',
            'instagram_url',
            'telegram_url',
        ]);
    }
}
