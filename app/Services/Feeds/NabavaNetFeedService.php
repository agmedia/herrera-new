<?php

namespace App\Services\Feeds;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Services\Catalog\ActionResolverService;
use App\Services\Pricing\TaxPricingService;
use App\Support\Media\MediaUrl;
use Illuminate\Support\Collection;
use XMLWriter;

class NabavaNetFeedService
{
    public function __construct(
        private readonly ActionResolverService $actionResolver,
        private readonly TaxPricingService $taxPricing,
    ) {}

    public function stream(string $locale = 'hr'): void
    {
        $fallbackLocale = trim((string) config('app.fallback_locale', config('app.locale', 'hr'))) ?: 'hr';
        $categoryPaths = $this->categoryPaths($locale, $fallbackLocale);
        $publicActions = $this->publicActions();
        $defaultTaxRate = $this->taxPricing->resolveRateForProduct();
        $pricesIncludeTax = $this->taxPricing->pricesIncludeTax();

        $writer = new XMLWriter;
        if (! $writer->openUri('php://output')) {
            throw new \RuntimeException('Nabava.net XML output could not be opened.');
        }

        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('artikli');

        Product::query()
            ->select([
                'id',
                'code',
                'sku',
                'base_price',
                'stock_qty',
                'tax_rate_id',
                'manufacturer_id',
                'is_active',
                'payload',
            ])
            ->visibleOnStorefront(true)
            ->where('base_price', '>', 0)
            ->with([
                'taxRate:id,rate,rate_type,is_active',
                'translations:id,product_id,locale,name,slug,excerpt,description',
                'categories' => fn ($query) => $query
                    ->select(['categories.id'])
                    ->where('categories.scope', Category::SCOPE_CATALOG)
                    ->orderByDesc('category_product.is_primary')
                    ->orderBy('category_product.sort_order')
                    ->orderBy('categories.id'),
                'manufacturer.translations:id,manufacturer_id,locale,name',
                'media' => fn ($query) => $query
                    ->whereIn('collection_name', ['product_main', 'product_gallery'])
                    ->orderByRaw("CASE WHEN collection_name = 'product_main' THEN 0 ELSE 1 END")
                    ->orderBy('order_column')
                    ->orderBy('id'),
            ])
            ->chunkById(250, function (Collection $products) use (
                $writer,
                $locale,
                $fallbackLocale,
                $categoryPaths,
                $publicActions,
                $defaultTaxRate,
                $pricesIncludeTax,
            ): void {
                foreach ($products as $product) {
                    $translation = $this->localizedTranslation($product->translations, $locale, $fallbackLocale);
                    $name = trim((string) ($translation?->name ?? ''));
                    $slug = trim((string) ($translation?->slug ?? ''));

                    if ($name === '' || $slug === '') {
                        continue;
                    }

                    $storedPrice = $this->publicStoredPrice($product, $publicActions);
                    $taxRate = $product->taxRate && (bool) $product->taxRate->is_active
                        ? $product->taxRate
                        : $defaultTaxRate;
                    $grossPrice = $pricesIncludeTax
                        ? round(max(0.0, $storedPrice), 2)
                        : $this->taxPricing->grossFromNet($storedPrice, $product, $taxRate);

                    if ($grossPrice <= 0) {
                        continue;
                    }

                    $writer->startElement('artikl');
                    $this->writeElement($writer, 'sifra', $this->identifier($product));
                    $this->writeElement(
                        $writer,
                        'kategorija',
                        $this->productCategoryPath($product, $categoryPaths, $locale, $fallbackLocale),
                    );
                    $this->writeElement($writer, 'naziv_artikla', $name);
                    $this->writeElement($writer, 'cijena', number_format($grossPrice, 2, ',', '').' €');
                    $this->writeElement($writer, 'raspolozivost', 'Raspoloživo');
                    $this->writeElement(
                        $writer,
                        'link_na_artikl',
                        $this->canonicalUrl(route('products.show', ['slug' => $slug], false)),
                    );

                    $imageUrl = $this->imageUrl($product);
                    if ($imageUrl !== '') {
                        $this->writeElement($writer, 'link_na_sliku_artikla', $imageUrl);
                    }

                    $description = $this->plainTextDescription((string) ($translation?->description ?: $translation?->excerpt));
                    if ($description !== '') {
                        $this->writeElement($writer, 'detaljni_opis', $description);
                    }

                    $writer->endElement();
                }

                $writer->flush();
            });

        $writer->endElement();
        $writer->endDocument();
        $writer->flush();
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function categoryPaths(string $locale, string $fallbackLocale): array
    {
        $categories = Category::query()
            ->select(['id', 'parent_id', '_lft'])
            ->where('scope', Category::SCOPE_CATALOG)
            ->with('translations:id,category_id,locale,name')
            ->orderBy('_lft')
            ->get();

        $paths = [];

        foreach ($categories as $category) {
            $parentPath = $category->parent_id ? ($paths[(int) $category->parent_id] ?? []) : [];
            $translation = $this->localizedTranslation($category->translations, $locale, $fallbackLocale);
            $name = trim((string) ($translation?->name ?? ''));

            $paths[(int) $category->id] = $name === ''
                ? $parentPath
                : [...$parentPath, $name];
        }

        return $paths;
    }

    /**
     * @return Collection<int, CatalogAction>
     */
    private function publicActions(): Collection
    {
        return CatalogAction::query()
            ->active()
            ->where('scope', CatalogAction::SCOPE_PRODUCT)
            ->where('audience_type', CatalogAction::AUDIENCE_ALL)
            ->whereIn('type', [CatalogAction::TYPE_PERCENTAGE, CatalogAction::TYPE_FIXED])
            ->where(function ($query): void {
                $query->whereNull('coupon_code')->orWhere('coupon_code', '');
            })
            ->with('targets:id,action_id,target_type,target_id')
            ->orderByDesc('is_exclusive')
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  Collection<int, CatalogAction>  $actions
     */
    private function publicStoredPrice(Product $product, Collection $actions): float
    {
        $basePrice = max(0.0, (float) $product->base_price);
        $bestPrice = $basePrice;
        $categoryIds = $product->categories->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        foreach ($actions as $action) {
            if (! $this->actionAppliesToProduct($action, $product, $categoryIds)) {
                continue;
            }

            $candidate = $this->actionResolver->applyToPrice($basePrice, $action);
            if ($candidate < $bestPrice) {
                $bestPrice = $candidate;
            }
        }

        return $bestPrice;
    }

    /**
     * @param  array<int, int>  $categoryIds
     */
    private function actionAppliesToProduct(CatalogAction $action, Product $product, array $categoryIds): bool
    {
        if ($action->target_type === CatalogAction::TARGET_ALL) {
            return true;
        }

        $targetIds = $action->targets
            ->where('target_type', $action->target_type)
            ->pluck('target_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return match ($action->target_type) {
            CatalogAction::TARGET_PRODUCT => in_array((int) $product->id, $targetIds, true),
            CatalogAction::TARGET_CATEGORY => array_intersect($categoryIds, $targetIds) !== [],
            CatalogAction::TARGET_MANUFACTURER => $product->manufacturer_id !== null
                && in_array((int) $product->manufacturer_id, $targetIds, true),
            default => false,
        };
    }

    /**
     * @param  array<int, array<int, string>>  $categoryPaths
     */
    private function productCategoryPath(
        Product $product,
        array $categoryPaths,
        string $locale,
        string $fallbackLocale,
    ): string {
        $primaryCategory = $product->categories->first();
        $parts = $primaryCategory ? ($categoryPaths[(int) $primaryCategory->id] ?? []) : [];

        $manufacturerTranslation = $product->manufacturer
            ? $this->localizedTranslation($product->manufacturer->translations, $locale, $fallbackLocale)
            : null;
        $manufacturerName = trim((string) ($manufacturerTranslation?->name ?? ''));
        $alreadyContainsManufacturer = collect($parts)->contains(
            static fn (string $part): bool => mb_strtolower(trim($part)) === mb_strtolower($manufacturerName),
        );

        if (count($parts) < 3 && $manufacturerName !== '' && ! $alreadyContainsManufacturer) {
            $parts[] = $manufacturerName;
        }

        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== ''));

        return $parts === [] ? 'Ostalo' : implode(' > ', $parts);
    }

    private function identifier(Product $product): string
    {
        foreach ([$product->sku, $product->code] as $identifier) {
            $identifier = trim((string) $identifier);
            if ($identifier !== '') {
                return $identifier;
            }
        }

        throw new \RuntimeException("Product {$product->id} has no stable Nabava.net identifier.");
    }

    private function imageUrl(Product $product): string
    {
        $media = $product->media
            ->first(static fn ($item): bool => $item->collection_name === 'product_main' && MediaUrl::hasUsableOriginal($item))
            ?? $product->media
                ->first(static fn ($item): bool => $item->collection_name === 'product_gallery' && MediaUrl::hasUsableOriginal($item));

        if (! $media) {
            return '';
        }

        $url = trim((string) $media->getUrl());
        $urlHost = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        $appHost = mb_strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($urlHost === '' || ($appHost !== '' && $urlHost === $appHost)) {
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);

            return $this->canonicalUrl($path);
        }

        return $url;
    }

    private function canonicalUrl(string $path): string
    {
        $baseUrl = rtrim((string) config('services.nabava_net.storefront_url', config('app.url')), '/');
        if (! preg_match('#^https?://#i', $baseUrl)) {
            throw new \RuntimeException('Nabava.net storefront URL must be an absolute HTTP(S) URL.');
        }

        return $baseUrl.'/'.ltrim($path, '/');
    }

    private function localizedTranslation(Collection $translations, string $locale, string $fallbackLocale): mixed
    {
        return $translations->firstWhere('locale', $locale)
            ?? $translations->firstWhere('locale', $fallbackLocale)
            ?? $translations->first();
    }

    private function plainTextDescription(string $value): string
    {
        $decoded = $value;
        for ($i = 0; $i < 2; $i++) {
            $next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        $decoded = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $decoded) ?? $decoded;
        $decoded = preg_replace('#<br\s*/?>#i', "\n", $decoded) ?? $decoded;
        $decoded = preg_replace('#</(?:p|div|li|h[1-6]|tr)>#i', "\n", $decoded) ?? $decoded;
        $decoded = preg_replace('#<li\b[^>]*>#i', '- ', $decoded) ?? $decoded;
        $decoded = strip_tags($decoded);
        $decoded = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $decoded);

        $lines = preg_split('/\n/u', $decoded) ?: [];
        $normalized = [];
        $previousWasBlank = true;

        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/[\t ]+/u', ' ', $line));
            $isBlank = $line === '';

            if ($isBlank && $previousWasBlank) {
                continue;
            }

            $normalized[] = $line;
            $previousWasBlank = $isBlank;
        }

        return trim($this->sanitizeXml(implode("\n", $normalized)));
    }

    private function writeElement(XMLWriter $writer, string $name, string $value): void
    {
        $writer->writeElement($name, $this->sanitizeXml($value));
    }

    private function sanitizeXml(string $value): string
    {
        return preg_replace(
            '/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
            '',
            $value,
        ) ?? '';
    }
}
