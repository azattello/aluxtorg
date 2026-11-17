<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

class CloudinaryService
{
    /**
     * @return array{public_id: string, secure_url: string, format: string|null, width: int|null, height: int|null, bytes: int|null}
     */
    public function upload(UploadedFile $file, string $subfolder): array
    {
        $configuration = $this->configuration();
        $timestamp = now()->timestamp;
        $folder = trim($configuration['folder'].'/'.$subfolder, '/');
        $signature = $this->signature([
            'folder' => $folder,
            'timestamp' => $timestamp,
        ], $configuration['api_secret']);

        $response = $this->client()
            ->attach('file', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
            ->post($this->endpoint($configuration['cloud_name'], 'image/upload'), [
                'api_key' => $configuration['api_key'],
                'folder' => $folder,
                'timestamp' => $timestamp,
                'signature' => $signature,
            ]);

        if (! $response->successful()) {
            abort(502, 'Cloudinary не принял изображение. Проверьте конфигурацию и повторите попытку.');
        }

        $asset = $response->json();
        if (! is_array($asset) || ! isset($asset['public_id'], $asset['secure_url'])) {
            abort(502, 'Cloudinary вернул некорректный ответ при загрузке изображения.');
        }

        return [
            'public_id' => (string) $asset['public_id'],
            'secure_url' => (string) $asset['secure_url'],
            'format' => isset($asset['format']) ? (string) $asset['format'] : null,
            'width' => isset($asset['width']) ? (int) $asset['width'] : null,
            'height' => isset($asset['height']) ? (int) $asset['height'] : null,
            'bytes' => isset($asset['bytes']) ? (int) $asset['bytes'] : null,
        ];
    }

    public function destroy(string $publicId): void
    {
        $configuration = $this->configuration();
        $timestamp = now()->timestamp;
        $signature = $this->signature([
            'public_id' => $publicId,
            'timestamp' => $timestamp,
        ], $configuration['api_secret']);

        $response = $this->client()->post($this->endpoint($configuration['cloud_name'], 'image/destroy'), [
            'api_key' => $configuration['api_key'],
            'public_id' => $publicId,
            'timestamp' => $timestamp,
            'signature' => $signature,
        ]);

        if (! $response->successful() || ! in_array($response->json('result'), ['ok', 'not found'], true)) {
            abort(502, 'Не удалось удалить изображение из Cloudinary.');
        }
    }

    /**
     * @return array{cloud_name: string, api_key: string, api_secret: string, folder: string}
     */
    private function configuration(): array
    {
        $configuration = config('services.cloudinary');
        foreach (['cloud_name', 'api_key', 'api_secret'] as $key) {
            if (! is_string($configuration[$key] ?? null) || $configuration[$key] === '') {
                abort(503, 'Загрузка изображений временно недоступна: Cloudinary не настроен.');
            }
        }

        return [
            'cloud_name' => $configuration['cloud_name'],
            'api_key' => $configuration['api_key'],
            'api_secret' => $configuration['api_secret'],
            'folder' => (string) ($configuration['folder'] ?? 'ecommerce'),
        ];
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()->asMultipart()->timeout(25);
    }

    private function endpoint(string $cloudName, string $action): string
    {
        return "https://api.cloudinary.com/v1_1/{$cloudName}/{$action}";
    }

    /**
     * @param  array<string, int|string>  $parameters
     */
    private function signature(array $parameters, string $secret): string
    {
        ksort($parameters);
        $payload = collect($parameters)
            ->map(fn (int|string $value, string $key): string => "{$key}={$value}")
            ->implode('&');

        return sha1($payload.$secret);
    }
}
