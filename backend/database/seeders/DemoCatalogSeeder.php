<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Banner;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use LogicException;

class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new LogicException('Demo catalog data cannot be seeded in production.');
        }

        $this->call(WarehouseSeeder::class);
        $warehouse = Warehouse::query()->where('code', 'main')->firstOrFail();

        $categories = [
            'tkani' => Category::query()->firstOrCreate(
                ['slug' => 'tkani'],
                ['name' => 'Ткани', 'is_active' => true, 'sort_order' => 1],
            ),
            'domashniy-tekstil' => Category::query()->firstOrCreate(
                ['slug' => 'domashniy-tekstil'],
                ['name' => 'Домашний текстиль', 'is_active' => true, 'sort_order' => 2],
            ),
        ];

        $products = [
            [
                'category' => 'tkani',
                'slug' => 'demo-hlopok-naturalnyy',
                'name' => 'Хлопок натуральный',
                'description' => 'Демонстрационная хлопковая ткань спокойного натурального оттенка. Подходит для шитья и декора.',
                'image' => 'https://images.unsplash.com/photo-1523381210434-271e8be1f52b?auto=format&fit=crop&w=1000&q=80',
                'alt' => 'Ткань натурального оттенка',
                'variants' => [
                    ['sku' => 'DEMO-COTTON-NAT-01', 'name' => 'Натуральный', 'price' => 4200, 'color' => 'Натуральный', 'stock' => 24],
                    ['sku' => 'DEMO-COTTON-WHT-01', 'name' => 'Молочный', 'price' => 4400, 'color' => 'Молочный', 'stock' => 12],
                ],
            ],
            [
                'category' => 'tkani',
                'slug' => 'demo-poplin-tsvetochnyy',
                'name' => 'Поплин «Полевые цветы»',
                'description' => 'Демонстрационный хлопковый поплин с цветочным принтом. Цена указана за один погонный метр.',
                'image' => 'https://images.unsplash.com/photo-1512436991641-6745cdb1723f?auto=format&fit=crop&w=1000&q=80',
                'alt' => 'Текстиль с цветочным рисунком',
                'variants' => [
                    ['sku' => 'DEMO-POPLIN-FLW-01', 'name' => 'Полевые цветы', 'price' => 3600, 'color' => 'Разноцветный', 'stock' => 18],
                ],
            ],
            [
                'category' => 'domashniy-tekstil',
                'slug' => 'demo-pled-hlopkovyy',
                'name' => 'Плед хлопковый',
                'description' => 'Демонстрационный мягкий плед для дома. Товар и остаток добавлены только для локального просмотра.',
                'image' => 'https://images.unsplash.com/photo-1600210492486-724fe5c67fb0?auto=format&fit=crop&w=1000&q=80',
                'alt' => 'Уютный домашний текстиль',
                'variants' => [
                    ['sku' => 'DEMO-THROW-CRM-01', 'name' => 'Кремовый', 'price' => 8900, 'color' => 'Кремовый', 'stock' => 5],
                    ['sku' => 'DEMO-THROW-GRN-01', 'name' => 'Оливковый', 'price' => 9200, 'color' => 'Оливковый', 'stock' => 0],
                ],
            ],
        ];

        foreach ($products as $sortOrder => $attributes) {
            $product = Product::query()->firstOrCreate(
                ['slug' => $attributes['slug']],
                [
                    'category_id' => $categories[$attributes['category']]->id,
                    'name' => $attributes['name'],
                    'description' => $attributes['description'],
                    'is_published' => true,
                    'sort_order' => $sortOrder,
                    'meta_title' => $attributes['name'],
                    'meta_description' => $attributes['description'],
                ],
            );

            ProductImage::query()->firstOrCreate(
                ['cloudinary_public_id' => 'demo/'.$attributes['slug']],
                [
                    'product_id' => $product->id,
                    'secure_url' => $attributes['image'],
                    'alt_text' => $attributes['alt'],
                    'sort_order' => 0,
                    'format' => 'jpg',
                ],
            );

            foreach ($attributes['variants'] as $variantOrder => $variantAttributes) {
                $variant = ProductVariant::query()->firstOrCreate(
                    ['sku' => $variantAttributes['sku']],
                    [
                        'product_id' => $product->id,
                        'name' => $variantAttributes['name'],
                        'sale_unit' => $attributes['category'] === 'tkani' ? 'м' : 'шт.',
                        'attributes' => ['Цвет' => $variantAttributes['color']],
                        'price_kzt' => $variantAttributes['price'],
                        'is_active' => true,
                        'sort_order' => $variantOrder,
                    ],
                );

                StockBalance::query()->firstOrCreate(
                    ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id],
                    ['on_hand' => $variantAttributes['stock'], 'reserved' => 0],
                );
            }
        }

        Banner::query()->firstOrCreate(
            ['cloudinary_public_id' => 'demo/banner/home-hero'],
            [
                'title' => 'Текстиль для ваших идей',
                'placement' => 'home_hero',
                'secure_url' => 'https://images.unsplash.com/photo-1523381210434-271e8be1f52b?auto=format&fit=crop&w=1800&q=85',
                'alt_text' => 'Подборка тканей и текстиля для дома',
                'format' => 'jpg',
                'width' => 1800,
                'height' => 900,
                'destination_url' => '/categories/tkani',
                'is_active' => true,
                'sort_order' => 0,
            ],
        );

        Banner::query()->firstOrCreate(
            ['cloudinary_public_id' => 'demo/banner/home-promo'],
            [
                'title' => 'Домашний текстиль для уютных деталей',
                'placement' => 'home_promo',
                'secure_url' => 'https://images.unsplash.com/photo-1600210492486-724fe5c67fb0?auto=format&fit=crop&w=500&q=80',
                'alt_text' => 'Мягкий домашний текстиль в интерьере',
                'format' => 'jpg',
                'width' => 500,
                'height' => 500,
                'destination_url' => '/categories/domashniy-tekstil',
                'is_active' => true,
                'sort_order' => 0,
            ],
        );
    }
}
