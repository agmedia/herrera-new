<?php

namespace Database\Seeders;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $userId = User::query()->value('id');

        $records = [
            [
                'code' => 'rice-premium-jasmine-1kg',
                'sku' => 'RPJ-1KG',
                'price' => 5.49,
                'stock' => 120,
                'category_codes' => ['rice-noodles'],
                'translations' => [
                    'en' => ['name' => 'Premium Jasmine Rice 1kg', 'slug' => 'premium-jasmine-rice-1kg'],
                    'hr' => ['name' => 'Premium Jasmine riža 1kg', 'slug' => 'premium-jasmine-riza-1kg'],
                ],
            ],
            [
                'code' => 'green-tea-sencha-100g',
                'sku' => 'GTS-100',
                'price' => 4.20,
                'stock' => 60,
                'category_codes' => ['tea-drinks'],
                'translations' => [
                    'en' => ['name' => 'Sencha Green Tea 100g', 'slug' => 'sencha-green-tea-100g'],
                    'hr' => ['name' => 'Sencha zeleni čaj 100g', 'slug' => 'sencha-zeleni-caj-100g'],
                ],
            ],
            [
                'code' => 'bamboo-spatula-set',
                'sku' => 'BSP-SET',
                'price' => 12.90,
                'stock' => 35,
                'category_codes' => ['kitchen-tools'],
                'translations' => [
                    'en' => ['name' => 'Bamboo Spatula Set', 'slug' => 'bamboo-spatula-set'],
                    'hr' => ['name' => 'Set bambus kuhača', 'slug' => 'set-bambus-kuhaca'],
                ],
            ],
        ];

        foreach ($records as $record) {
            $product = Product::query()->updateOrCreate(
                ['code' => $record['code']],
                [
                    'sku' => $record['sku'],
                    'is_active' => true,
                    'base_price' => $record['price'],
                    'stock_qty' => $record['stock'],
                    'payload' => null,
                    'updated_by' => $userId,
                    'created_by' => $userId,
                ]
            );

            foreach ($record['translations'] as $locale => $translation) {
                $name = $translation['name'];
                $slug = $translation['slug'] ?? Str::slug($name);

                $product->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'name' => $name,
                        'slug' => $slug,
                        'excerpt' => null,
                        'description' => null,
                        'meta_title' => $name,
                        'meta_description' => null,
                        'payload' => null,
                    ]
                );
            }

            $categoryIds = Category::query()
                ->whereIn('code', $record['category_codes'])
                ->pluck('id')
                ->all();

            $syncPayload = [];
            foreach ($categoryIds as $index => $categoryId) {
                $syncPayload[$categoryId] = [
                    'sort_order' => $index,
                    'is_primary' => $index === 0,
                ];
            }

            if (!empty($syncPayload)) {
                $product->categories()->sync($syncPayload);
            }
        }
    }
}
