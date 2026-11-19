<?php

namespace App\Services\Analytics;

use App\Support\Export\XlsxWorkbook;
use Illuminate\Database\Eloquent\Builder;

class SalesOpportunityExport
{
    public function create(Builder $products): string
    {
        return (new XlsxWorkbook)->create([
            'Prodajne prilike' => [
                'headers' => ['Rang', 'Šifra', 'SKU', 'EAN', 'Naziv artikla', 'Kategorije', 'Poznati kupci · 90 dana', 'Neto količina · 90 dana'],
                'widths' => [8, 22, 24, 24, 60, 45, 22, 26],
                'decimals' => [7],
                'rows' => $this->rows($products),
            ],
        ]);
    }

    private function rows(Builder $products): iterable
    {
        $rank = 0;
        foreach ($products->lazy(100) as $product) {
            $translation = $product->translations->firstWhere('locale', 'hr') ?: $product->translations->first();
            $categories = $product->categories->map(fn ($category) => ($category->translations->firstWhere('locale', 'hr') ?: $category->translations->first())?->name ?: $category->code)->implode(', ');
            yield [++$rank, (string) $product->code, (string) $product->sku, (string) $product->barcode,
                (string) ($translation?->name ?: $product->code), $categories, (int) $product->opportunity_buyers, (float) $product->opportunity_quantity];
        }
    }
}
