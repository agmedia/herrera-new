<?php

namespace Database\Seeders;

use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProductOptionValueSeeder extends Seeder
{
    public function run(): void
    {
        $userId = User::query()->value('id');

        $this->seedBambooSpatula($userId);
        $this->seedGreenTeaPack($userId);
    }

    private function seedBambooSpatula(?int $userId): void
    {
        $product = Product::query()->where('code', 'bamboo-spatula-set')->first();
        $color = Option::query()->where('code', 'color')->first();
        $size = Option::query()->where('code', 'size')->first();

        if (!$product || !$color || !$size) {
            return;
        }

        $colorValues = $color->values()->pluck('id', 'code');
        $sizeValues = $size->values()->pluck('id', 'code');

        $rows = [
            ['parent_code' => 'black', 'value_code' => 's', 'sku' => 'BSP-SET-BLACK-S', 'stock' => 8, 'price' => 12.90],
            ['parent_code' => 'black', 'value_code' => 'm', 'sku' => 'BSP-SET-BLACK-M', 'stock' => 5, 'price' => 13.40],
            ['parent_code' => 'black', 'value_code' => 'l', 'sku' => 'BSP-SET-BLACK-L', 'stock' => 4, 'price' => 13.90],
            ['parent_code' => 'red', 'value_code' => 's', 'sku' => 'BSP-SET-RED-S', 'stock' => 6, 'price' => 13.20],
            ['parent_code' => 'red', 'value_code' => 'l', 'sku' => 'BSP-SET-RED-L', 'stock' => 3, 'price' => 14.10],
        ];

        ProductOptionValue::query()->where('product_id', $product->id)->delete();

        foreach ($rows as $index => $row) {
            $parentId = (int) ($colorValues[$row['parent_code']] ?? 0);
            $valueId = (int) ($sizeValues[$row['value_code']] ?? 0);

            if ($parentId <= 0 || $valueId <= 0) {
                continue;
            }

            ProductOptionValue::query()->create([
                'product_id' => $product->id,
                'option_value_id' => $valueId,
                'parent_option_value_id' => $parentId,
                'mode' => 'linked',
                'sku' => $row['sku'],
                'stock_qty' => $row['stock'],
                'price_override' => $row['price'],
                'sort_order' => $index,
                'is_active' => true,
                'combination_hash' => hash('sha256', 'l:'.$parentId.':'.$valueId),
                'payload' => null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
        }
    }

    private function seedGreenTeaPack(?int $userId): void
    {
        $product = Product::query()->where('code', 'green-tea-sencha-100g')->first();
        $pack = Option::query()->where('code', 'pack')->first();

        if (!$product || !$pack) {
            return;
        }

        $values = $pack->values()->pluck('id', 'code');
        ProductOptionValue::query()->where('product_id', $product->id)->delete();

        $rows = [
            ['value_code' => 'single', 'sku' => 'GTS-100-SINGLE', 'stock' => 60, 'price' => 4.20],
            ['value_code' => 'family', 'sku' => 'GTS-100-FAMILY', 'stock' => 22, 'price' => 7.80],
        ];

        foreach ($rows as $offset => $row) {
            $valueId = (int) ($values[$row['value_code']] ?? 0);

            if ($valueId <= 0) {
                continue;
            }

            ProductOptionValue::query()->create([
                'product_id' => $product->id,
                'option_value_id' => $valueId,
                'parent_option_value_id' => null,
                'mode' => 'single',
                'sku' => $row['sku'],
                'stock_qty' => $row['stock'],
                'price_override' => $row['price'],
                'sort_order' => 100 + $offset,
                'is_active' => true,
                'combination_hash' => hash('sha256', 's:'.$valueId),
                'payload' => null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
        }
    }
}
