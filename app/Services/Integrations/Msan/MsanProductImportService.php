<?php

namespace App\Services\Integrations\Msan;

use App\Jobs\Integrations\Msan\ImportMsanProductImageJob;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductTranslation;
use App\Models\Import\CatalogSourceMapping;
use App\Models\Integrations\Msan\MsanCategoryMapping;
use App\Models\Integrations\Msan\MsanProduct;
use App\Models\Settings\Local\TaxRate;
use App\Services\Pricing\TaxPricingService;
use App\Services\Settings\SystemSettingsService;
use App\Support\ImportedDescriptionHtmlCleaner;
use DomainException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MsanProductImportService
{
    public function __construct(
        private readonly SystemSettingsService $settings,
        private readonly ImportedDescriptionHtmlCleaner $descriptionCleaner,
        private readonly MsanSettingsService $msanSettings,
        private readonly MsanSpecificationPublisher $specificationPublisher,
        private readonly TaxPricingService $taxPricing,
    ) {}

    /**
     * Import one selected staging row. M SAN remains a non-owning supplier link:
     * no catalog_source_mappings row is written, so the future ERP import can
     * remain the catalog master.
     *
     * @return 'created'|'updated'|'skipped'
     */
    public function import(int $msanProductId, ?int $userId = null): string
    {
        $brand = trim((string) MsanProduct::query()
            ->whereKey($msanProductId)
            ->value('brand'));
        if ($brand === '' || $this->existingManufacturerId($brand) !== null) {
            return $this->importLocked($msanProductId, $userId);
        }

        $lockKey = 'integrations:msan:manufacturer:'.hash(
            'sha256',
            mb_strtolower($brand),
        );

        try {
            return Cache::lock($lockKey, 300)->block(
                60,
                fn (): string => $this->importLocked($msanProductId, $userId),
            );
        } catch (LockTimeoutException) {
            throw new DomainException('M SAN proizvođač trenutačno se obrađuje. Pokušajte ponovno.');
        }
    }

    private function importLocked(int $msanProductId, ?int $userId = null): string
    {
        $result = DB::transaction(function () use ($msanProductId, $userId): array {
            /** @var MsanProduct|null $source */
            $source = MsanProduct::query()
                ->with(['categories.mapping'])
                ->lockForUpdate()
                ->find($msanProductId);

            if (! $source || ! $source->selected || $source->is_stale) {
                return ['status' => 'skipped', 'image' => false];
            }

            if (mb_strlen((string) $source->external_code) > 120) {
                $message = 'M SAN šifra artikla duža je od dopuštenih 120 znakova.';
                $source->forceFill([
                    'match_status' => MsanProduct::MATCH_CONFLICT,
                    'import_status' => MsanProduct::IMPORT_FAILED,
                    'last_error' => $message,
                ])->save();

                return ['status' => 'conflict', 'image' => false, 'message' => $message];
            }

            $categoryIds = $source->categories
                ->map(fn ($category) => $category->mapping)
                ->filter(fn ($mapping) => $mapping
                    && $mapping->status === MsanCategoryMapping::STATUS_MAPPED
                    && $mapping->local_category_id)
                ->pluck('local_category_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values();

            $validCategoryIds = Category::query()
                ->where('scope', Category::SCOPE_CATALOG)
                ->whereIn('id', $categoryIds)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->values();

            if ($validCategoryIds->isEmpty()) {
                throw new DomainException('Artikl nema mapiranu M SAN kategoriju.');
            }

            /** @var Product|null $product */
            $product = $source->local_product_id
                ? Product::query()->lockForUpdate()->find($source->local_product_id)
                : null;
            $isNew = ! $product;

            if ($isNew) {
                $barcode = $this->preferredBarcode($source);
                $collision = Product::query()
                    ->where('code', $source->external_code)
                    ->orWhere('sku', $source->external_code)
                    ->when($barcode !== null, fn ($query) => $query->orWhere('barcode', $barcode))
                    ->first();

                if ($collision) {
                    $message = 'Kod, SKU ili barkod već koristi postojeći artikl.';
                    $source->forceFill([
                        'match_status' => MsanProduct::MATCH_CONFLICT,
                        'import_status' => MsanProduct::IMPORT_FAILED,
                        'last_error' => $message,
                    ])->save();

                    return ['status' => 'conflict', 'image' => false, 'message' => $message];
                }

                $product = new Product;
            }

            $payload = is_array($product->payload) ? $product->payload : [];
            $hasCatalogOwner = ! $isNew && CatalogSourceMapping::query()
                ->where('entity_type', CatalogSourceMapping::ENTITY_PRODUCT)
                ->where('local_id', $product->id)
                ->lockForUpdate()
                ->exists();
            $hasImportedSource = ! empty($payload['import_sources'] ?? []);
            $ownsCatalogFields = $isNew || (
                data_get($payload, 'catalog_origin') === 'msan'
                && ! $hasCatalogOwner
                && ! $hasImportedSource
            );
            if ($isNew) {
                $payload['catalog_origin'] = 'msan';
            }
            $payload['supplier_sources']['msan'] = [
                'external_code' => $source->external_code,
                'product_type' => $source->product_type,
                'brand' => $source->brand,
                'model' => $source->model,
                'part_number' => $source->part_number,
                'warranty_months' => $source->warranty_months,
                'currency_code' => $source->currency_code,
                'list_price' => $source->list_price,
                'discount_percent' => $source->discount_percent,
                'partner_price' => $source->partner_price,
                'recommended_retail_price' => $source->recommended_retail_price,
                'availability_level' => $source->availability_level,
                'on_promotion' => $source->on_promotion,
                'synced_at' => now()->toIso8601String(),
            ];

            $productAttributes = [
                'payload' => $payload,
                'created_by' => $product->exists ? $product->created_by : $userId,
                'updated_by' => $userId,
            ];

            if ($ownsCatalogFields) {
                $recommendedPrice = max(0, (float) ($source->recommended_retail_price ?? 0));
                $storedRecommendedPrice = $recommendedPrice > 0
                    ? $this->storedMpcPrice($recommendedPrice, $product)
                    : null;
                $basePrice = $storedRecommendedPrice
                    ?? ($product->exists ? (float) $product->base_price : 0.0);
                $isActive = $product->exists
                    ? (bool) $product->is_active
                    : ((bool) $this->settings->get('msan_import_products_active', false) && $basePrice > 0);

                $productAttributes += [
                    'code' => $source->external_code,
                    'sku' => $source->external_code,
                    'barcode' => $this->preferredBarcode($source),
                    'unit_of_measure' => 'pcs',
                    'minimum_order_quantity' => 1,
                    'order_quantity_step' => 1,
                    'is_active' => $isActive,
                    'manufacturer_id' => $this->resolveManufacturerId((string) ($source->brand ?? ''), $userId),
                    'tax_rate_id' => $product->tax_rate_id ?: $this->defaultTaxRateId(),
                    'base_price' => $basePrice,
                    'stock_qty' => $this->stockQuantityForLevel($source->availability_level),
                    'weight_kg' => $source->package_weight_kg,
                    'length_cm' => $source->package_length_cm,
                    'width_cm' => $source->package_width_cm,
                    'height_cm' => $source->package_height_cm,
                ];
            }

            $product->forceFill($productAttributes)->save();

            if ($ownsCatalogFields) {
                $name = Str::limit(trim((string) ($source->name ?: $source->external_code)), 255, '');
                $description = $this->combinedDescription($source);
                ProductTranslation::query()->updateOrCreate(
                    ['product_id' => $product->id, 'locale' => 'hr'],
                    [
                        'name' => $name,
                        'slug' => $this->productSlug($name, (int) $source->id),
                        'excerpt' => Str::limit(trim(strip_tags((string) $source->marketing_description)), 500, ''),
                        'description' => $description,
                        'meta_title' => Str::limit($name, 191, ''),
                        'meta_description' => Str::limit(trim(strip_tags((string) ($source->marketing_description ?: $source->technical_description))), 300, ''),
                        'payload' => ['supplier_source' => 'msan'],
                    ]
                );
            }

            if ($isNew) {
                $sync = [];
                foreach ($validCategoryIds as $index => $categoryId) {
                    $sync[$categoryId] = [
                        'sort_order' => ($index + 1) * 10,
                        'is_primary' => $index === 0,
                    ];
                }
                $product->categories()->sync($sync);
            } elseif ($ownsCatalogFields) {
                $attach = [];
                foreach ($validCategoryIds as $index => $categoryId) {
                    $attach[$categoryId] = [
                        'sort_order' => ($index + 1) * 10,
                        'is_primary' => false,
                    ];
                }
                $product->categories()->syncWithoutDetaching($attach);
            }

            $source->forceFill([
                'local_product_id' => $product->id,
                'match_status' => MsanProduct::MATCH_MATCHED,
                'import_status' => MsanProduct::IMPORT_IMPORTED,
                'last_imported_at' => now(),
                'last_error' => null,
            ])->save();

            return [
                'status' => $isNew ? 'created' : 'updated',
                'image' => $ownsCatalogFields
                    && (bool) $this->settings->get('msan_import_images', true)
                    && trim((string) $source->image_url) !== '',
                'source_id' => (int) $source->id,
            ];
        }, 3);

        if (($result['status'] ?? null) === 'conflict') {
            throw new DomainException((string) ($result['message'] ?? 'M SAN artikl nije moguće sigurno povezati.'));
        }

        if (($result['image'] ?? false) && isset($result['source_id'])) {
            ImportMsanProductImageJob::dispatch((int) $result['source_id'])->onQueue('integrations');
        }
        if ($this->msanSettings->importSpecifications() && isset($result['source_id'])) {
            $source = MsanProduct::query()->find((int) $result['source_id']);
            if ($source) {
                $this->specificationPublisher->publishProductFromActiveSnapshot($source);
            }
        }

        return (string) $result['status'];
    }

    private function preferredBarcode(MsanProduct $source): ?string
    {
        $barcodes = is_array($source->barcodes) ? $source->barcodes : [];
        foreach (['EAN', 'UPC'] as $preferredType) {
            foreach ($barcodes as $key => $entry) {
                $type = is_array($entry) ? strtoupper(trim((string) ($entry['type'] ?? ''))) : strtoupper((string) $key);
                $value = is_array($entry) ? trim((string) ($entry['value'] ?? '')) : trim((string) $entry);
                if ($type === $preferredType && $value !== '') {
                    return Str::limit($value, 80, '');
                }
            }
        }

        return null;
    }

    private function stockQuantityForLevel(?int $level): int
    {
        return $this->msanSettings->stockLevelQuantity(
            max(0, min(4, (int) ($level ?? 0))),
        );
    }

    private function storedMpcPrice(float $grossPrice, Product $product): ?float
    {
        $storedPrice = $this->taxPricing->pricesIncludeTax()
            ? round($grossPrice, 2)
            : $this->taxPricing->netFromGross($grossPrice, $product);

        return is_finite($storedPrice) && $storedPrice > 0
            ? $storedPrice
            : null;
    }

    private function defaultTaxRateId(): ?int
    {
        return TaxRate::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->value('id');
    }

    private function resolveManufacturerId(string $brand, ?int $userId): ?int
    {
        $brand = Str::limit(trim($brand), 255, '');
        if ($brand === '') {
            return null;
        }

        $existingId = $this->existingManufacturerId($brand);
        if ($existingId !== null) {
            return $existingId;
        }

        $brandSlug = Str::slug($brand);
        $brandSlug = $brandSlug !== '' ? $brandSlug : 'brand';
        $code = Str::limit('msan-'.$brandSlug, 101, '')
            .'-'.substr(hash('sha256', mb_strtolower($brand)), 0, 8);

        $manufacturer = Manufacturer::query()->firstOrCreate(
            ['code' => Str::limit($code, 120, '')],
            [
                'is_active' => true,
                'is_featured' => false,
                'sort_order' => 0,
                'payload' => ['supplier_source' => 'msan'],
                'created_by' => $userId,
                'updated_by' => $userId,
            ],
        );
        $manufacturer->translations()->firstOrCreate(
            ['locale' => 'hr'],
            [
                'name' => $brand,
                'slug' => Str::limit(Str::slug($brand), 220, '').'-msan-'.$manufacturer->id,
                'payload' => ['supplier_source' => 'msan'],
            ],
        );

        return (int) $manufacturer->id;
    }

    private function existingManufacturerId(string $brand): ?int
    {
        $id = Manufacturer::query()
            ->whereHas('translations', fn ($query) => $query
                ->where('locale', 'hr')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($brand)]))
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function productSlug(string $name, int $sourceId): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'artikl';
        }

        return Str::limit($base, 220, '').'-msan-'.$sourceId;
    }

    private function combinedDescription(MsanProduct $source): string
    {
        $parts = [];
        foreach ([$source->marketing_description, $source->technical_description] as $description) {
            $clean = $this->descriptionCleaner->clean((string) $description);
            if ($clean !== '' && ! in_array($clean, $parts, true)) {
                $parts[] = $clean;
            }
        }

        return implode("\n\n", $parts);
    }
}
