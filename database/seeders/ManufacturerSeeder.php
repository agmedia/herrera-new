<?php

namespace Database\Seeders;

use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ManufacturerSeeder extends Seeder
{
    public function run(): void
    {
        $userId = User::query()->value('id');

        $records = [
            [
                'code' => 'ag-nature',
                'is_featured' => true,
                'sort_order' => 10,
                'translations' => [
                    'en' => ['name' => 'AG Nature', 'slug' => 'ag-nature'],
                    'hr' => ['name' => 'AG Nature', 'slug' => 'ag-nature'],
                ],
                'product_codes' => ['rice-premium-jasmine-1kg'],
            ],
            [
                'code' => 'tea-valley',
                'is_featured' => true,
                'sort_order' => 20,
                'translations' => [
                    'en' => ['name' => 'Tea Valley', 'slug' => 'tea-valley'],
                    'hr' => ['name' => 'Tea Valley', 'slug' => 'tea-valley'],
                ],
                'product_codes' => ['green-tea-sencha-100g'],
            ],
            [
                'code' => 'bamboo-craft',
                'is_featured' => false,
                'sort_order' => 30,
                'translations' => [
                    'en' => ['name' => 'Bamboo Craft', 'slug' => 'bamboo-craft'],
                    'hr' => ['name' => 'Bamboo Craft', 'slug' => 'bamboo-craft'],
                ],
                'product_codes' => ['bamboo-spatula-set'],
            ],
        ];

        foreach ($records as $record) {
            $manufacturer = Manufacturer::query()->updateOrCreate(
                ['code' => $record['code']],
                [
                    'is_active' => true,
                    'is_featured' => (bool) $record['is_featured'],
                    'sort_order' => (int) $record['sort_order'],
                    'payload' => null,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]
            );

            foreach ($record['translations'] as $locale => $translation) {
                $name = $translation['name'];
                $slug = $translation['slug'] ?? Str::slug($name);

                $manufacturer->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'name' => $name,
                        'slug' => $slug,
                        'description' => null,
                        'meta_title' => $name,
                        'meta_description' => null,
                        'payload' => null,
                    ]
                );
            }

            Product::query()
                ->whereIn('code', $record['product_codes'])
                ->update(['manufacturer_id' => $manufacturer->id]);
        }
    }
}
