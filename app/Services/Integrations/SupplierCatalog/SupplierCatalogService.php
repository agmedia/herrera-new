<?php

namespace App\Services\Integrations\SupplierCatalog;

use App\Jobs\Integrations\SupplierCatalog\ImportSupplierImages;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Integrations\SupplierCatalog\SupplierCatalogItem;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\TaxRate;
use App\Services\Pricing\TaxPricingService;
use App\Services\Settings\SystemSettingsService;
use App\Support\ImportedDescriptionHtmlCleaner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class SupplierCatalogService
{
    public function options(string $supplier): array
    {
        $this->assertSupplier($supplier);

        return array_replace([
            'markup' => 0, 'default_category_id' => '', 'category_map' => [],
            'source_currency' => 'EUR', 'exchange_rate' => 1, 'source_includes_tax' => false,
            'tax_rate_id' => TaxRate::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('sort_order')->value('id') ?? '',
        ], (array) app(SystemSettingsService::class)->get('supplier_catalog_'.$supplier, []));
    }

    public function validateOptions(array $options): array
    {
        return Validator::make($options, [
            'markup' => 'required|numeric|min:0|max:10000',
            'default_category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('scope', 'catalog')],
            'category_map' => 'present|array|max:2000',
            'category_map.*' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('scope', 'catalog')],
            'source_currency' => 'required|string|size:3|regex:/^[A-Z]{3}$/',
            'exchange_rate' => 'required|numeric|gt:0|max:100000',
            'source_includes_tax' => 'required|boolean',
            'tax_rate_id' => ['required', 'integer', Rule::exists('tax_rates', 'id')->where('is_active', true)],
        ])->validate();
    }

    public function saveOptions(string $supplier, array $options): void
    {
        $this->assertSupplier($supplier);
        app(SystemSettingsService::class)->put('supplier_catalog_'.$supplier, $this->validateOptions($options));
    }

    public function storeCurrency(): string
    {
        return strtoupper((string) (Currency::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('sort_order')->value('code')
            ?: app(SystemSettingsService::class)->get('store_schema_product_currency', 'EUR')));
    }

    public function price(SupplierCatalogItem $item, array $options): ?float
    {
        if ($item->source_price === null || ($item->currency && $item->currency !== $options['source_currency'])) {
            return null;
        }
        if ($options['source_currency'] === $this->storeCurrency() && (float) $options['exchange_rate'] !== 1.0) {
            return null;
        }
        $tax = TaxRate::query()->where('is_active', true)->find($options['tax_rate_id']);
        if (! $tax) {
            return null;
        }
        $value = (float) $item->source_price * (float) $options['exchange_rate'] * (1 + (float) $options['markup'] / 100);
        $pricing = app(TaxPricingService::class);
        if ((bool) $options['source_includes_tax'] !== $pricing->pricesIncludeTax()) {
            $value = $options['source_includes_tax'] ? $pricing->netFromGross($value, taxRate: $tax) : $pricing->grossFromNet($value, taxRate: $tax);
        }

        return is_finite($value) && $value > 0 && $value <= 99999999 ? round($value, 2) : null;
    }

    public function categoryId(SupplierCatalogItem $item, array $options): ?int
    {
        // Hash source keys so Livewire dot notation cannot change their meaning.
        $id = ($options['category_map'][hash('sha256', $item->category_key)] ?? null) ?: $options['default_category_id'];

        return $id ? (int) $id : null;
    }

    public function refresh(string $supplier): int
    {
        $this->assertSupplier($supplier);

        return Cache::lock('supplier-catalog:'.$supplier, 180)->block(1, function () use ($supplier): int {
            $rows = app(SupplierCatalogFeedClient::class)->fetch($supplier);
            DB::transaction(function () use ($supplier, $rows): void {
                SupplierCatalogItem::query()->where('supplier', $supplier)->update(['is_current' => false]);
                $identities = $this->identities();
                foreach ($rows as $row) {
                    $item = SupplierCatalogItem::query()->firstOrNew(['supplier' => $supplier, 'external_id' => $row['external_id']]);
                    $item->fill($row + ['is_current' => true, 'synced_at' => now()]);
                    $matches = $this->matches($item, $identities);
                    $wasImported = $item->status === 'imported' && $item->product_id && $matches === [(int) $item->product_id];
                    $item->status = count($matches) > 1 ? 'conflict' : ($wasImported ? 'imported' : ($matches !== [] ? 'existing' : 'new'));
                    $item->product_id = count($matches) === 1 ? $matches[0] : null;
                    $item->message = count($matches) > 1 ? 'Šifra ili EAN odgovaraju različitim postojećim artiklima.' : ($wasImported ? $item->message : null);
                    $item->save();
                }
            });

            return count($rows);
        });
    }

    public function import(string $supplier, array $ids, array $options, ?int $actorId): array
    {
        $this->assertSupplier($supplier);
        $options = $this->validateOptions($options);
        Validator::make(['ids' => $ids], ['ids' => 'required|array|min:1|max:100', 'ids.*' => 'required|integer|min:1|distinct'])->validate();

        return Cache::lock('supplier-catalog:'.$supplier, 180)->block(1, function () use ($supplier, $ids, $options, $actorId): array {
            // All supplier imports share this lock to prevent cross-supplier duplicates.
            return Cache::lock('supplier-catalog:import', 180)->block(1, function () use ($supplier, $ids, $options, $actorId): array {
                return DB::transaction(function () use ($supplier, $ids, $options, $actorId): array {
                    $items = SupplierCatalogItem::query()->where('supplier', $supplier)->whereIn('id', $ids)->lockForUpdate()->get();
                    if ($items->count() !== count($ids)) {
                        throw new RuntimeException('Odabir ne pripada ovom dobavljaču.');
                    }
                    $identities = $this->identities(true);
                    $created = $skipped = 0;
                    foreach ($items as $item) {
                        $matches = $this->matches($item, $identities);
                        if ($matches !== []) {
                            $item->update(['status' => count($matches) > 1 ? 'conflict' : ($item->status === 'imported' ? 'imported' : 'existing'), 'product_id' => count($matches) === 1 ? $matches[0] : null,
                                'message' => 'Artikl već postoji; postojeći podaci su sačuvani.']);
                            $skipped++;

                            continue;
                        }
                        $category = Category::query()->where('scope', 'catalog')->find($this->categoryId($item, $options));
                        $price = $this->price($item, $options);
                        if (! $item->is_current || ! $category || $price === null) {
                            throw new RuntimeException('Svaki odabrani artikl mora biti u aktualnom feedu, imati mapiranu kategoriju i valjanu cijenu/valutu.');
                        }
                        $product = Product::query()->create([
                            'code' => $supplier.'-'.substr(hash('sha256', $item->external_id), 0, 40), 'sku' => $item->sku,
                            'barcode' => $item->barcode, 'is_active' => false, 'base_price' => $price,
                            'stock_qty' => 0, 'supplier_stock_qty' => $item->quantity, 'unit_of_measure' => 'pcs',
                            'minimum_order_quantity' => 1, 'order_quantity_step' => 1, 'tax_rate_id' => $options['tax_rate_id'],
                            'payload' => ['catalog_origin' => $supplier, 'supplier_catalog' => [
                                'supplier' => $supplier, 'external_id' => $item->external_id, 'sku' => $item->sku,
                                'source_price' => $item->source_price, 'source_currency' => $options['source_currency'],
                                'markup' => $options['markup'], 'exchange_rate' => $options['exchange_rate'],
                                'source_includes_tax' => $options['source_includes_tax'],
                            ]], 'created_by' => $actorId, 'updated_by' => $actorId,
                        ]);
                        $product->translations()->create([
                            'locale' => 'hr', 'name' => $item->name,
                            'slug' => Str::limit(Str::slug($item->name) ?: 'artikl', 180, '').'-'.$product->code,
                            'description' => app(ImportedDescriptionHtmlCleaner::class)->clean($item->description ?? ''),
                        ]);
                        $product->categories()->attach($category->id, ['is_primary' => true, 'sort_order' => 0]);
                        foreach ($item->specifications ?? [] as $position => $spec) {
                            $product->technicalSpecificationRows()->create([
                                'source' => $supplier, 'source_key' => hash('sha256', $spec['label'].':'.$position),
                                'group_name' => 'Specifikacije', 'item_name' => $spec['label'], 'values' => [$spec['value']], 'sort_order' => $position,
                            ]);
                        }
                        $item->update(['product_id' => $product->id, 'status' => 'imported', 'message' => $item->images ? 'Uvezen nacrt. Slike čekaju obradu.' : 'Uvezen nacrt. Dobavljač nema slike za ovaj artikl.']);
                        $this->addIdentity($identities, $product);
                        if ($item->images !== []) {
                            ImportSupplierImages::dispatch($item->id)->onQueue('integrations')->afterCommit();
                        }
                        $created++;
                    }

                    return compact('created', 'skipped');
                });
            });
        });
    }

    private function assertSupplier(string $supplier): void
    {
        abort_unless(isset(SupplierCatalogFeedClient::SUPPLIERS[$supplier]), 404);
    }

    private function identities(bool $lock = false): array
    {
        $map = [];
        Product::query()->select(['id', 'sku', 'code', 'barcode', 'payload'])->when($lock, fn ($query) => $query->lockForUpdate())
            ->chunkById(500, function ($products) use (&$map): void {
                foreach ($products as $product) {
                    $this->addIdentity($map, $product);
                }
            });

        return $map;
    }

    private function addIdentity(array &$map, Product $product): void
    {
        foreach ([$product->sku, $product->code, data_get($product->payload, 'opencart.sku'), data_get($product->payload, 'opencart.model'), data_get($product->payload, 'supplier_catalog.sku')] as $sku) {
            if (is_scalar($sku) && trim((string) $sku) !== '') {
                $map['sku:'.mb_strtolower(trim((string) $sku))][$product->id] = $product->id;
            }
        }
        foreach ([$product->barcode, data_get($product->payload, 'opencart.ean')] as $ean) {
            if (is_scalar($ean) && trim((string) $ean) !== '') {
                $map['ean:'.trim((string) $ean)][$product->id] = $product->id;
            }
        }
    }

    private function matches(SupplierCatalogItem $item, array $map): array
    {
        return array_values(array_unique(array_merge(
            array_values($map['sku:'.mb_strtolower($item->sku)] ?? []),
            $item->barcode ? array_values($map['ean:'.$item->barcode] ?? []) : [],
            $item->product_id && Product::query()->whereKey($item->product_id)->exists() ? [(int) $item->product_id] : [],
        )));
    }
}
