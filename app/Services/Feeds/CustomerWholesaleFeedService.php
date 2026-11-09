<?php

namespace App\Services\Feeds;

use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\User\CustomerCatalogFeed;
use App\Services\Pricing\PriceCatalogResolver;
use App\Services\Pricing\TaxPricingService;
use App\Services\Sales\ReceivedReturnQuantities;
use App\Services\Settings\SystemSettingsService;
use App\Support\Media\MediaUrl;
use App\Support\OrderStatusClassification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use XMLWriter;

class CustomerWholesaleFeedService
{
    public function products(CustomerCatalogFeed $feed): Builder
    {
        return Product::query()->visibleOnStorefront()
            ->where(function (Builder $scope) use ($feed): void {
                $scope->whereExists(fn (QueryBuilder $grant) => $grant->selectRaw('1')->from('customer_catalog_feed_products')
                    ->where('feed_id', $feed->id)->whereColumn('product_id', 'products.id'));
                if ($feed->include_purchased_products) {
                    $scope->orWhereExists(function (QueryBuilder $purchases) use ($feed): void {
                        $purchases->selectRaw('1')->from('order_items as purchased_items')
                            ->join('orders as purchased_orders', 'purchased_orders.id', '=', 'purchased_items.order_id')
                            ->leftJoin('order_statuses as purchased_statuses', 'purchased_statuses.id', '=', 'purchased_orders.status_id')
                            ->leftJoinSub(app(ReceivedReturnQuantities::class)->query(), 'purchased_returns', 'purchased_returns.order_item_id', '=', 'purchased_items.id')
                            ->where('purchased_orders.user_id', $feed->user_id)->where('purchased_orders.customer_type', 'B2B')
                            ->whereColumn('purchased_items.product_id', 'products.id')
                            ->whereRaw('purchased_items.quantity > COALESCE(purchased_returns.returned_quantity, 0)')
                            ->whereNot(fn (QueryBuilder $status) => OrderStatusClassification::applyCancelledQuery($status, 'purchased_statuses.'));
                    });
                }
            });
    }

    public function stream(CustomerCatalogFeed $feed): void
    {
        $catalog = app(PriceCatalogResolver::class)->activeCatalog();
        $currency = $catalog?->currency_code ?: app(SystemSettingsService::class)->get('store_schema_product_currency', 'EUR');
        $locale = 'hr';
        $fallback = (string) config('app.fallback_locale', 'hr');
        $tax = app(TaxPricingService::class);
        $defaultTax = $tax->resolveRateForProduct();
        $includesTax = $tax->pricesIncludeTax();
        $products = $this->products($feed)->select('products.*');
        if ($catalog) {
            $products->selectSub(DB::table('catalog_price_entries as wholesale_base')->select('wholesale_base.price')
                ->where('wholesale_base.price_catalog_id', $catalog->id)->whereColumn('wholesale_base.product_id', 'products.id')
                ->where('wholesale_base.kind', PriceCatalogEntry::BASE)->where('wholesale_base.is_active', true)
                ->whereNull('wholesale_base.user_id')->whereNull('wholesale_base.customer_group_id')->where('wholesale_base.minimum_quantity', '<=', 1)
                ->where(fn (QueryBuilder $query) => $query->whereNull('wholesale_base.starts_at')->orWhere('wholesale_base.starts_at', '<=', now()))
                ->where(fn (QueryBuilder $query) => $query->whereNull('wholesale_base.ends_at')->orWhere('wholesale_base.ends_at', '>', now()))
                ->orderBy('wholesale_base.priority')->orderBy('wholesale_base.price')->orderBy('wholesale_base.id')->limit(1), 'full_wholesale_price');
        }
        $writer = new XMLWriter;
        if (! $writer->openUri('php://output')) {
            throw new \RuntimeException('XML izvoz nije moguće otvoriti.');
        }
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('catalogue');
        $writer->writeAttribute('version', '1');
        $writer->writeAttribute('currency', strtoupper((string) $currency));
        $writer->writeAttribute('price_basis', 'full_wholesale');
        $writer->writeAttribute('tax_included', 'false');
        $this->element($writer, 'generated_at', now()->toIso8601String());
        $writer->startElement('products');

        $products->with(['translations', 'manufacturer.translations', 'categories.translations', 'taxRate', 'media'])
            ->chunkById(200, function ($batch) use ($writer, $locale, $fallback, $includesTax, $defaultTax): void {
                foreach ($batch as $product) {
                    // Deliberately bypass customer/group/quantity prices and all promotions.
                    $stored = (float) ($product->full_wholesale_price ?? $product->getRawOriginal('base_price'));
                    $rate = $product->taxRate?->is_active ? $product->taxRate : $defaultTax;
                    $rateValue = max(0.0, (float) $rate?->rate);
                    $net = $includesTax && $rate
                        ? max(0.0, $rate->rate_type === 'fixed' ? $stored - $rateValue : $stored / (1 + $rateValue / 100))
                        : $stored;
                    if ($net <= 0) {
                        continue;
                    }
                    $translation = $product->translations->firstWhere('locale', $locale)
                        ?? $product->translations->firstWhere('locale', $fallback) ?? $product->translations->first();
                    $writer->startElement('product');
                    $writer->writeAttribute('id', (string) $product->id);
                    foreach ([
                        'sku' => $product->sku, 'code' => $product->code, 'barcode' => $product->barcode,
                        'name' => $translation?->name ?: $product->sku ?: $product->code,
                        'description' => html_entity_decode(strip_tags((string) ($translation?->description ?: $translation?->excerpt)), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                        'price' => number_format($net, 4, '.', ''),
                        'unit' => $product->unit_of_measure ?: 'kom',
                        'minimum_order_quantity' => (string) max(1, (int) $product->minimum_order_quantity),
                        'order_quantity_step' => (string) max(1, (int) $product->order_quantity_step),
                        'availability' => $product->stock_qty > 0 ? 'warehouse' : ($product->supplier_stock_qty > 0 ? 'supplier' : 'on_request'),
                        'manufacturer' => $product->manufacturer?->translations->firstWhere('locale', $locale)?->name ?: $product->manufacturer?->translations->first()?->name,
                        'energy_label_url' => $product->energy_label_url,
                        'product_information_sheet_url' => $product->product_information_sheet_url,
                    ] as $key => $value) {
                        $this->element($writer, $key, (string) $value);
                    }
                    if ($translation?->slug) {
                        $this->element($writer, 'url', route('products.show', ['slug' => $translation->slug]));
                    }
                    $writer->startElement('categories');
                    foreach ($product->categories as $category) {
                        $name = $category->translations->firstWhere('locale', $locale)?->name ?: $category->translations->first()?->name;
                        $writer->startElement('category');
                        $writer->writeAttribute('id', (string) $category->id);
                        $writer->text($this->sanitize((string) $name));
                        $writer->endElement();
                    }
                    $writer->endElement();
                    $writer->startElement('images');
                    foreach ($product->media->whereIn('collection_name', ['product_main', 'product_gallery']) as $media) {
                        if (MediaUrl::hasUsableOriginal($media)) {
                            $this->element($writer, 'image', $media->getUrl());
                        }
                    }
                    $writer->endElement();
                    $writer->endElement();
                }
                $writer->flush();
            }, 'products.id', 'id');
        $writer->endElement();
        $writer->endElement();
        $writer->endDocument();
        $writer->flush();
    }

    private function element(XMLWriter $writer, string $name, string $value): void
    {
        $writer->writeElement($name, $this->sanitize($value));
    }

    private function sanitize(string $value): string
    {
        return preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
    }
}
