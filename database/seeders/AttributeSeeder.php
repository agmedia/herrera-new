<?php

namespace Database\Seeders;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AttributeSeeder extends Seeder
{
    public function run(): void
    {
        $userId = User::query()->value('id');

        $records = [
            [
                'code' => 'material-bamboo',
                'group_code' => 'material',
                'type' => Attribute::TYPE_SELECT,
                'sort_order' => 10,
                'translations' => [
                    'en' => ['group_name' => 'Material', 'name' => 'Bamboo', 'slug' => 'material-bamboo'],
                    'hr' => ['group_name' => 'Materijal', 'name' => 'Bambus', 'slug' => 'materijal-bambus'],
                ],
            ],
            [
                'code' => 'material-stainless-steel',
                'group_code' => 'material',
                'type' => Attribute::TYPE_SELECT,
                'sort_order' => 20,
                'translations' => [
                    'en' => ['group_name' => 'Material', 'name' => 'Stainless Steel', 'slug' => 'material-stainless-steel'],
                    'hr' => ['group_name' => 'Materijal', 'name' => 'Nehrdajuci celik', 'slug' => 'materijal-nehrdajuci-celik'],
                ],
            ],
            [
                'code' => 'origin-japan',
                'group_code' => 'origin',
                'type' => Attribute::TYPE_SELECT,
                'sort_order' => 30,
                'translations' => [
                    'en' => ['group_name' => 'Origin', 'name' => 'Japan', 'slug' => 'origin-japan'],
                    'hr' => ['group_name' => 'Podrijetlo', 'name' => 'Japan', 'slug' => 'podrijetlo-japan'],
                ],
            ],
            [
                'code' => 'origin-thailand',
                'group_code' => 'origin',
                'type' => Attribute::TYPE_SELECT,
                'sort_order' => 40,
                'translations' => [
                    'en' => ['group_name' => 'Origin', 'name' => 'Thailand', 'slug' => 'origin-thailand'],
                    'hr' => ['group_name' => 'Podrijetlo', 'name' => 'Tajland', 'slug' => 'podrijetlo-tajland'],
                ],
            ],
            [
                'code' => 'diet-vegan',
                'group_code' => 'diet',
                'type' => Attribute::TYPE_MULTI,
                'sort_order' => 50,
                'translations' => [
                    'en' => ['group_name' => 'Diet', 'name' => 'Vegan', 'slug' => 'diet-vegan'],
                    'hr' => ['group_name' => 'Prehrana', 'name' => 'Vegansko', 'slug' => 'prehrana-vegansko'],
                ],
            ],
            [
                'code' => 'diet-gluten-free',
                'group_code' => 'diet',
                'type' => Attribute::TYPE_MULTI,
                'sort_order' => 60,
                'translations' => [
                    'en' => ['group_name' => 'Diet', 'name' => 'Gluten Free', 'slug' => 'diet-gluten-free'],
                    'hr' => ['group_name' => 'Prehrana', 'name' => 'Bez glutena', 'slug' => 'prehrana-bez-glutena'],
                ],
            ],
        ];

        foreach ($records as $record) {
            $attribute = Attribute::query()->updateOrCreate(
                ['code' => $record['code']],
                [
                    'group_code' => $record['group_code'],
                    'type' => $record['type'],
                    'is_active' => true,
                    'sort_order' => $record['sort_order'],
                    'payload' => null,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]
            );

            foreach ($record['translations'] as $locale => $translation) {
                $groupName = $translation['group_name'];
                $name = $translation['name'];
                $slug = $translation['slug'] ?? Str::slug($name);

                $attribute->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'group_name' => $groupName,
                        'name' => $name,
                        'slug' => $slug,
                        'description' => null,
                        'payload' => null,
                    ]
                );
            }
        }

        $this->assignToProduct('rice-premium-jasmine-1kg', [
            'origin-thailand',
            'diet-vegan',
            'diet-gluten-free',
        ]);

        $this->assignToProduct('green-tea-sencha-100g', [
            'origin-japan',
            'diet-vegan',
            'diet-gluten-free',
        ]);

        $this->assignToProduct('bamboo-spatula-set', [
            'material-bamboo',
        ]);
    }

    /**
     * @param array<int, string> $attributeCodes
     */
    private function assignToProduct(string $productCode, array $attributeCodes): void
    {
        $product = Product::query()->where('code', $productCode)->first();
        if (!$product) {
            return;
        }

        $attributes = Attribute::query()
            ->whereIn('code', $attributeCodes)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $syncPayload = [];
        foreach ($attributes as $index => $attribute) {
            $syncPayload[$attribute->id] = ['sort_order' => $index];
        }

        if (!empty($syncPayload)) {
            $product->attributes()->sync($syncPayload);
        }
    }
}
