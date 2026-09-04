<?php

namespace Database\Seeders;

use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class OptionSeeder extends Seeder
{
    public function run(): void
    {
        $userId = User::query()->value('id');

        $size = $this->upsertOption(
            code: 'size',
            type: Option::TYPE_SELECT,
            sortOrder: 10,
            userId: $userId,
            translations: [
                'en' => ['name' => 'Size', 'slug' => 'size'],
                'hr' => ['name' => 'Veličina', 'slug' => 'velicina'],
            ]
        );

        $this->upsertOptionValue(
            option: $size,
            code: 's',
            sortOrder: 10,
            userId: $userId,
            translations: [
                'en' => ['name' => 'S', 'slug' => 's'],
                'hr' => ['name' => 'S', 'slug' => 's'],
            ]
        );
        $this->upsertOptionValue(
            option: $size,
            code: 'm',
            sortOrder: 20,
            userId: $userId,
            translations: [
                'en' => ['name' => 'M', 'slug' => 'm'],
                'hr' => ['name' => 'M', 'slug' => 'm'],
            ]
        );
        $this->upsertOptionValue(
            option: $size,
            code: 'l',
            sortOrder: 30,
            userId: $userId,
            translations: [
                'en' => ['name' => 'L', 'slug' => 'l'],
                'hr' => ['name' => 'L', 'slug' => 'l'],
            ]
        );

        $color = $this->upsertOption(
            code: 'color',
            type: Option::TYPE_SELECT,
            sortOrder: 20,
            userId: $userId,
            translations: [
                'en' => ['name' => 'Color', 'slug' => 'color'],
                'hr' => ['name' => 'Boja', 'slug' => 'boja'],
            ]
        );

        $this->upsertOptionValue(
            option: $color,
            code: 'black',
            sortOrder: 10,
            userId: $userId,
            translations: [
                'en' => ['name' => 'Black', 'slug' => 'black'],
                'hr' => ['name' => 'Crna', 'slug' => 'crna'],
            ]
        );
        $this->upsertOptionValue(
            option: $color,
            code: 'red',
            sortOrder: 20,
            userId: $userId,
            translations: [
                'en' => ['name' => 'Red', 'slug' => 'red'],
                'hr' => ['name' => 'Crvena', 'slug' => 'crvena'],
            ]
        );

        OptionValue::query()
            ->where('option_id', $color->id)
            ->whereNotIn('code', ['black', 'red'])
            ->delete();

        $pack = $this->upsertOption(
            code: 'pack',
            type: Option::TYPE_RADIO,
            sortOrder: 30,
            userId: $userId,
            translations: [
                'en' => ['name' => 'Pack', 'slug' => 'pack'],
                'hr' => ['name' => 'Pakiranje', 'slug' => 'pakiranje'],
            ]
        );

        $this->upsertOptionValue(
            option: $pack,
            code: 'single',
            sortOrder: 10,
            userId: $userId,
            translations: [
                'en' => ['name' => 'Single', 'slug' => 'single'],
                'hr' => ['name' => 'Komad', 'slug' => 'komad'],
            ]
        );
        $this->upsertOptionValue(
            option: $pack,
            code: 'family',
            sortOrder: 20,
            userId: $userId,
            translations: [
                'en' => ['name' => 'Family Pack', 'slug' => 'family-pack'],
                'hr' => ['name' => 'Obiteljsko pakiranje', 'slug' => 'obiteljsko-pakiranje'],
            ]
        );

        $this->assignOptionsToProduct(
            productCode: 'bamboo-spatula-set',
            assignments: [
                ['option' => $size, 'sort_order' => 0, 'is_required' => true],
                ['option' => $color, 'sort_order' => 1, 'is_required' => false],
            ]
        );

        $this->assignOptionsToProduct(
            productCode: 'green-tea-sencha-100g',
            assignments: [
                ['option' => $pack, 'sort_order' => 0, 'is_required' => true],
            ]
        );
    }

    /**
     * @param array<string, array{name:string,slug?:string,description?:string}> $translations
     */
    private function upsertOption(
        string $code,
        string $type,
        int $sortOrder,
        ?int $userId,
        array $translations
    ): Option {
        $option = Option::query()->updateOrCreate(
            ['code' => $code],
            [
                'type' => $type,
                'is_active' => true,
                'sort_order' => $sortOrder,
                'payload' => null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]
        );

        foreach ($translations as $locale => $data) {
            $name = $data['name'];
            $slug = $data['slug'] ?? Str::slug($name);

            $option->translations()->updateOrCreate(
                ['locale' => $locale],
                [
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $data['description'] ?? null,
                    'payload' => null,
                ]
            );
        }

        return $option->refresh();
    }

    /**
     * @param array<string, array{name:string,slug?:string}> $translations
     */
    private function upsertOptionValue(
        Option $option,
        string $code,
        int $sortOrder,
        ?int $userId,
        array $translations
    ): OptionValue {
        $value = OptionValue::query()->updateOrCreate(
            [
                'option_id' => $option->id,
                'code' => $code,
            ],
            [
                'is_active' => true,
                'sort_order' => $sortOrder,
                'payload' => null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]
        );

        foreach ($translations as $locale => $data) {
            $name = $data['name'];
            $slug = $data['slug'] ?? Str::slug($name);

            $value->translations()->updateOrCreate(
                ['locale' => $locale],
                [
                    'name' => $name,
                    'slug' => $slug,
                    'payload' => null,
                ]
            );
        }

        return $value->refresh();
    }

    /**
     * @param array<int, array{option:Option,sort_order:int,is_required:bool}> $assignments
     */
    private function assignOptionsToProduct(string $productCode, array $assignments): void
    {
        $product = Product::query()->where('code', $productCode)->first();
        if (!$product) {
            return;
        }

        $syncPayload = [];
        foreach ($assignments as $assignment) {
            $syncPayload[$assignment['option']->id] = [
                'sort_order' => $assignment['sort_order'],
                'is_required' => $assignment['is_required'],
            ];
        }

        if (!empty($syncPayload)) {
            $product->options()->sync($syncPayload);
        }
    }
}
