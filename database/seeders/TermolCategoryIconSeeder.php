<?php

namespace Database\Seeders;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use Illuminate\Database\Seeder;

class TermolCategoryIconSeeder extends Seeder
{
    /**
     * Representative products for the root catalog categories.
     *
     * @var array<string, string>
     */
    private const REPRESENTATIVE_PRODUCT_SKUS = [
        'termol-67f66b285792a46d7929fe35' => '3456',
        'termol-4c19cfda6ba580101f545003' => '17000',
        'termol-c3d237d3c86d1d2029ccfe85' => '13710',
        'termol-ced8372364598defa576a8c2' => '17607',
        'termol-5e98a24bad0fc36b0aefbca1' => '16844',
        'termol-2f4570fb1cfadba9d4b39936' => '23078',
        'termol-f044467b24d5fbc179679108' => '19017',
        'termol-2ea07bcf3d5b52ebd3ba0187' => '19778',
        'termol-632d81fc8d3c9ad5eb50a4e9' => '2644',
        'termol-036f9d915302278537a9bbeb' => '24980',
        'termol-a9fbcb35d07ded8760a3de23' => '9741',
        'termol-6d6a48be94d30c23ac4fc73c' => '7288',
        'termol-30422ee024e0b844a95cfc19' => '2599',
        'termol-ad63ee52b03f05982dc070a7' => '26234',
        'termol-c6b1ae3abb095e40b6fa7d68' => '25983',
        'termol-64507b357ae8a3bae18e16b2' => '26106',
    ];

    public function run(): void
    {
        $added = 0;
        $skipped = 0;

        foreach (self::REPRESENTATIVE_PRODUCT_SKUS as $categoryCode => $productSku) {
            $category = Category::query()
                ->where('scope', Category::SCOPE_CATALOG)
                ->where('code', $categoryCode)
                ->with('translations')
                ->first();

            $product = Product::query()
                ->where('sku', $productSku)
                ->with(['translations', 'media'])
                ->first();

            if (! $category || ! $product) {
                $skipped++;
                $this->command?->warn("Category {$categoryCode} or product {$productSku} was not found.");

                continue;
            }

            if ($category->getFirstMedia('category_icon')) {
                $skipped++;

                continue;
            }

            $sourceMedia = $product->getFirstMedia('product_main')
                ?? $product->getFirstMedia('product_gallery');

            if (! $sourceMedia || ! is_file($sourceMedia->getPath())) {
                $skipped++;
                $this->command?->warn("Product {$productSku} does not have a usable image.");

                continue;
            }

            $categoryName = $this->localizedName($category, 'Kategorija');
            $productName = $this->localizedName($product, $productSku);

            $category
                ->copyMedia($sourceMedia->getPath())
                ->usingName($categoryName)
                ->withCustomProperties([
                    'alt' => [
                        'hr' => $categoryName.' – reprezentativni proizvod',
                    ],
                    'caption' => [
                        'hr' => $productName,
                    ],
                    'source_product_id' => (int) $product->id,
                    'source_product_sku' => (string) $product->sku,
                ])
                ->toMediaCollection('category_icon', $sourceMedia->disk);

            $added++;
        }

        $this->command?->info("Category icons added: {$added}; skipped: {$skipped}.");
    }

    private function localizedName(Category|Product $model, string $fallback): string
    {
        $translation = $model->translations->firstWhere('locale', 'hr')
            ?? $model->translations->first();

        return trim((string) ($translation?->name ?? '')) ?: $fallback;
    }
}
