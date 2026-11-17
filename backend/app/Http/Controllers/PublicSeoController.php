<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;

class PublicSeoController extends Controller
{
    public function robots(): Response
    {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $content = implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /account',
            'Disallow: /admin',
            'Disallow: /api/',
            'Disallow: /cart',
            'Disallow: /checkout',
            'Disallow: /order-confirmation/',
            'Disallow: /reset-password',
            'Disallow: /sanctum/',
            "Sitemap: {$frontendUrl}/sitemap.xml",
            '',
        ]);

        return response($content)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function sitemap(): Response
    {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $urls = collect([
            ['loc' => "{$frontendUrl}/", 'lastmod' => null],
        ]);

        Category::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['slug', 'updated_at'])
            ->each(fn (Category $category) => $urls->push([
                'loc' => "{$frontendUrl}/categories/".rawurlencode($category->slug),
                'lastmod' => $category->updated_at?->toAtomString(),
            ]));

        Product::query()
            ->where('is_published', true)
            ->whereHas('category', fn (Builder $query): Builder => $query->where('is_active', true))
            ->orderBy('id')
            ->get(['slug', 'updated_at'])
            ->each(fn (Product $product) => $urls->push([
                'loc' => "{$frontendUrl}/products/".rawurlencode($product->slug),
                'lastmod' => $product->updated_at?->toAtomString(),
            ]));

        return response()
            ->view('seo.sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=900');
    }
}
