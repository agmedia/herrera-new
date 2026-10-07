<?php

namespace App\Services\Integrations\Eracuni;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Attribute\AttributeGroup;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\CatalogProductSpecification;
use App\Models\Catalog\Product\Product;
use App\Models\Integrations\Eracuni\EracuniCatalogItem;
use App\Models\Integrations\Eracuni\EracuniCatalogRun;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\TaxRate;
use App\Services\Pricing\TaxPricingService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class EracuniCatalogService
{
    public function __construct(private readonly EracuniCatalogClient $client, private readonly TaxPricingService $taxPricing, private readonly IdeusCsvCatalogReader $csvReader) {}

    /** Fetches a private snapshot and plans candidates; never writes catalog data. */
    public function preview(?int $actorId = null, ?string $codeFrom = null, ?string $codeTo = null): EracuniCatalogRun
    {
        return $this->execute('preview', $actorId, function (EracuniCatalogRun $run) use ($codeFrom, $codeTo): void {
            $plans = $this->fetchPlans(codeFrom: $codeFrom, codeTo: $codeTo);
            $this->recordFetch($run, count($plans));
            $this->persistPreview($run, $plans);
        });
    }

    /** Privately reviews the original Generic IDEUS CSV; never fetches ERP or writes catalog data. */
    public function previewSupplierCsv(string $absoluteFile, ?int $actorId = null): EracuniCatalogRun
    {
        return $this->execute('preview_csv', $actorId, function (EracuniCatalogRun $run) use ($absoluteFile): void {
            $file = $this->csvReader->read($absoluteFile);
            $plans = $this->csvPlans($file['rows']);
            $run->update(['fetched_count' => count($plans), 'summary' => [
                'source' => 'ideus_csv', 'file_name' => $file['file_name'], 'sha256' => $file['sha256'],
                'source_row_count' => count($file['rows']), 'limit_reached' => false,
                'warning' => 'CSV cijene prenesene su kao izvorne osnovne cijene. Nacrte i porez provjerite prije objave.',
            ]]);
            $this->persistPreview($run, $plans);
        });
    }

    /** Imports only selected items from an already persisted preview, as inactive drafts. */
    public function import(int $runId, array $selectedItemIds, ?int $actorId = null): EracuniCatalogRun
    {
        if ($selectedItemIds === [] || count($selectedItemIds) > 500
            || collect($selectedItemIds)->contains(fn ($id): bool => ! (is_int($id) || (is_string($id) && ctype_digit($id))) || (int) $id < 1)) {
            throw new RuntimeException('Odaberite od 1 do 500 artikala iz pregleda.');
        }
        $ids = array_values(array_unique(array_map('intval', $selectedItemIds)));
        $previewKind = EracuniCatalogRun::query()->findOrFail($runId)->kind;
        $kind = $previewKind === 'preview_csv' ? 'import_csv' : 'import';

        return $this->execute($kind, $actorId, function (EracuniCatalogRun $run) use ($runId, $ids, $actorId): void {
            $run->update(['summary' => ['preview_run_id' => $runId], 'fetched_count' => count($ids)]);
            DB::transaction(function () use ($run, $runId, $ids, $actorId): void {
                $preview = EracuniCatalogRun::query()->lockForUpdate()->findOrFail($runId);
                if (! in_array($preview->kind, ['preview', 'preview_csv'], true) || $preview->status !== 'completed') {
                    throw new RuntimeException('Nije odabran završeni pregled proizvoda.');
                }
                if ($this->limitReached($preview)) {
                    throw new RuntimeException('Pregled je ograničen na 10.000 artikala. Sužite raspon i dohvatite novi pregled.');
                }
                $selected = $preview->items()->whereIn('id', $ids)->lockForUpdate()->get();
                if ($selected->count() !== count($ids) || $selected->contains(fn ($item): bool => ! in_array($item->status, ['new', 'created'], true))) {
                    throw new RuntimeException('Odabrani artikli nisu kandidati iz ovog pregleda.');
                }
                // Serialize with catalog identity changes and recheck every candidate.
                $identities = $this->identities(true);
                $created = $skipped = 0;
                foreach ($selected as $candidate) {
                    $entry = ['plan' => $candidate->plan, 'source' => $candidate->source_payload];
                    $plan = $entry['plan'];
                    $matches = $identities['id:'.$plan['model']] ?? [];
                    $skuTaken = Product::query()->where('sku', $plan['sku'])->exists();
                    $barcodeTaken = $plan['barcode'] && Product::query()->where('barcode', $plan['barcode'])->exists();
                    if ($matches !== [] || $skuTaken || $barcodeTaken || $candidate->status === 'created') {
                        $skipped++;
                        $this->item($run, $entry, 'skipped', count($matches) === 1 ? $matches[0] : null, 'Artikl ili barkod već postoji; postojeći podaci su sačuvani.');
                        if (count($matches) === 1 || $skuTaken) {
                            $candidate->update(['status' => 'existing', 'product_id' => count($matches) === 1 ? $matches[0] : null]);
                        }

                        continue;
                    }
                    $this->assertPricePlan($plan);
                    $source = $preview->kind === 'preview_csv' ? 'ideus_csv' : 'eracuni';
                    $payload = $source === 'ideus_csv' ? [
                        'model' => $plan['model'], 'sku' => $plan['sku'], 'raw_price' => $plan['raw_price'],
                        'brand' => $plan['brand'], 'raw_weight' => $plan['raw_weight'], 'raw_height' => $plan['raw_height'],
                    ] : [
                        'productCode' => $plan['model'], 'grossPrice' => $plan['gross_price'],
                        'currency' => $plan['currency'], 'vatPercentage' => $plan['vat_percentage'],
                    ];
                    $payload += [
                        'draft_import' => true, 'preview_run_id' => $runId,
                        'price_review_required' => $plan['price_state'] === 'review',
                        'attribute_review_required' => ! $plan['attributes_valid'],
                    ];
                    $manufacturerId = $plan['manufacturer_id'] ?? null;
                    if ($manufacturerId && $this->manufacturerForBrand($plan['brand']) !== $manufacturerId) {
                        throw new RuntimeException('Povezivanje proizvođača promijenjeno je nakon pregleda.');
                    }
                    $product = Product::query()->create([
                        'code' => ($source === 'ideus_csv' ? 'ideus-' : 'eracuni-').substr(hash('sha256', $plan['model']), 0, 40),
                        'sku' => $plan['sku'], 'barcode' => $plan['barcode'], 'is_active' => false,
                        'stock_qty' => 0, 'supplier_stock_qty' => 0,
                        'base_price' => $plan['base_price'], 'tax_rate_id' => $plan['tax_rate_id'],
                        'manufacturer_id' => $manufacturerId,
                        'minimum_order_quantity' => 1, 'order_quantity_step' => 1,
                        'payload' => [$source => $payload],
                        'created_by' => $actorId, 'updated_by' => $actorId,
                    ]);
                    $product->translations()->create([
                        'locale' => 'hr', 'name' => $plan['name'],
                        'slug' => (Str::slug($plan['name']) ?: 'artikl').'-'.$product->code,
                        'description' => nl2br(e($plan['description']), false),
                        'meta_title' => $plan['name'], 'payload' => ['source' => $source],
                    ]);
                    $this->applyAttributes($product, $plan['attributes'], $actorId, $source);
                    $candidate->update(['status' => 'created', 'product_id' => $product->id]);
                    $this->item($run, $entry, 'created', $product->id, 'Uvezen je neaktivan nacrt. Provjerite cijenu, porez, kategorije i ostale podatke prije objave.');
                    $identities['id:'.$plan['model']] = [(int) $product->id];
                    $created++;
                }
                $run->update(['status' => 'completed', 'created_count' => $created, 'skipped_count' => $skipped, 'completed_at' => now()]);
            });
        });
    }

    /** Updates source-owned attributes only; existing prices, stock and content are untouched. */
    public function syncAttributes(?int $actorId = null, ?string $codeFrom = null, ?string $codeTo = null, string $trigger = 'manual'): EracuniCatalogRun
    {
        if (! in_array($trigger, ['manual', 'cron'], true)) {
            throw new RuntimeException('Nepoznat način pokretanja.');
        }

        return $this->execute('attributes', $actorId, function (EracuniCatalogRun $run) use ($actorId, $codeFrom, $codeTo, $trigger): void {
            $run->update(['summary' => ['trigger' => $trigger]]);
            // Parse the entire response before entering a catalog-write transaction.
            $plans = $this->fetchPlans(codeFrom: $codeFrom, codeTo: $codeTo);
            $this->recordFetch($run, count($plans), ['trigger' => $trigger]);
            if ($trigger === 'cron' && $this->limitReached($run->fresh())) {
                $run->update(['error_message' => 'Cron atributa nije promijenio katalog jer je dohvat dosegnuo ograničenje od 10.000 artikala. Postavite uži raspon šifri.']);
                throw new RuntimeException('Cron dohvat atributa nije potpun.');
            }
            DB::transaction(function () use ($plans, $run, $actorId): void {
                $identities = $this->identities(true);
                $updated = $unchanged = $skipped = 0;
                foreach ($plans as $entry) {
                    $plan = $entry['plan'];
                    $matches = $identities['id:'.$plan['model']] ?? [];
                    if (count($matches) !== 1 || ! $plan['attributes_valid'] || $plan['attributes'] === []) {
                        $skipped++;
                        $this->item($run, $entry, count($matches) > 1 ? 'ambiguous' : (! $plan['attributes_valid'] ? 'invalid' : 'skipped'), count($matches) === 1 ? $matches[0] : null, count($matches) > 1
                            ? 'Šifra odgovara više artikala; atributi nisu promijenjeni.'
                            : ($matches === [] ? 'Artikl nije pronađen u lokalnom katalogu.' : 'Opis nema nedvosmislen, podržan popis atributa; postojeći atributi su sačuvani.'));

                        continue;
                    }
                    $product = Product::query()->lockForUpdate()->findOrFail($matches[0]);
                    $changed = $this->applyAttributes($product, $plan['attributes'], $actorId);
                    $changed ? $updated++ : $unchanged++;
                    $this->item($run, $entry, $changed ? 'updated' : 'unchanged', $product->id);
                }
                $run->update([
                    'status' => 'completed', 'updated_count' => $updated, 'unchanged_count' => $unchanged,
                    'skipped_count' => $skipped, 'completed_at' => now(),
                ]);
            });
        });
    }

    /** Reviews existing-product prices or names without changing the catalog. */
    public function previewUpdates(string $operation, ?int $actorId = null, ?string $codeFrom = null, ?string $codeTo = null): EracuniCatalogRun
    {
        if (! in_array($operation, ['prices', 'names'], true)) {
            throw new RuntimeException('Nepoznata ERP obrada.');
        }

        return $this->execute('preview_'.$operation, $actorId, function (EracuniCatalogRun $run) use ($operation, $codeFrom, $codeTo): void {
            $plans = $this->fetchPlans(parseAttributes: false, codeFrom: $codeFrom, codeTo: $codeTo);
            $this->recordFetch($run, count($plans), ['operation' => $operation]);
            DB::transaction(function () use ($plans, $run, $operation): void {
                $identities = $this->identities();
                $eligible = $unchanged = $skipped = 0;
                foreach ($plans as $entry) {
                    $matches = $identities['id:'.$entry['plan']['model']] ?? [];
                    $entry['plan']['preview_operation'] = $operation;
                    if (count($matches) !== 1) {
                        $skipped++;
                        $this->item($run, $entry, count($matches) > 1 ? 'ambiguous' : 'skipped', null, count($matches) > 1
                            ? 'Šifra odgovara više artikala. Potrebno je ručno povezivanje.'
                            : 'Artikl nije pronađen u lokalnom katalogu.');

                        continue;
                    }
                    $product = Product::query()->findOrFail($matches[0]);
                    $entry['plan']['old_base_price'] = number_format((float) $product->base_price, 4, '.', '');
                    $entry['plan']['old_tax_rate_id'] = $product->tax_rate_id;
                    $entry['plan']['old_name'] = $product->translations()->where('locale', 'hr')->value('name');
                    if ($operation === 'prices') {
                        $entry['plan'] = $this->existingPricePlan($product, $entry['plan']);
                        if ($entry['plan']['price_state'] !== 'mapped') {
                            $skipped++;
                            $this->item($run, $entry, 'skipped', $product->id, 'Cijena ili porez nisu nedvosmisleno povezani. Postojeća cijena je sačuvana.');

                            continue;
                        }
                        $changed = $entry['plan']['old_base_price'] !== $entry['plan']['base_price'];
                    } else {
                        $changed = $entry['plan']['old_name'] !== $entry['plan']['name'];
                    }
                    $changed ? $eligible++ : $unchanged++;
                    $this->item($run, $entry, $changed ? 'update' : 'unchanged', $product->id);
                }
                $run->update([
                    'status' => 'completed', 'eligible_count' => $eligible, 'unchanged_count' => $unchanged,
                    'skipped_count' => $skipped, 'completed_at' => now(),
                ]);
            });
        });
    }

    /** Applies exactly the selected reviewed field, with a stale-data check. */
    public function applyUpdates(int $runId, array $selectedItemIds, ?int $actorId = null): EracuniCatalogRun
    {
        if ($selectedItemIds === [] || count($selectedItemIds) > 500
            || collect($selectedItemIds)->contains(fn ($id): bool => ! (is_int($id) || (is_string($id) && ctype_digit($id))) || (int) $id < 1)) {
            throw new RuntimeException('Odaberite od 1 do 500 stavki iz pregleda.');
        }
        $preview = EracuniCatalogRun::query()->findOrFail($runId);
        $operation = match ($preview->kind) {
            'preview_prices' => 'prices',
            'preview_names' => 'names',
            default => throw new RuntimeException('Nije odabran pregled cijena ili naziva.'),
        };
        $ids = array_values(array_unique(array_map('intval', $selectedItemIds)));

        return $this->execute($operation, $actorId, function (EracuniCatalogRun $run) use ($runId, $ids, $operation, $actorId): void {
            $run->update(['fetched_count' => count($ids), 'summary' => ['preview_run_id' => $runId, 'operation' => $operation]]);
            DB::transaction(function () use ($run, $runId, $ids, $operation, $actorId): void {
                $preview = EracuniCatalogRun::query()->lockForUpdate()->findOrFail($runId);
                if ($preview->status !== 'completed') {
                    throw new RuntimeException('Pregled nije završen.');
                }
                if ($this->limitReached($preview)) {
                    throw new RuntimeException('Pregled je ograničen na 10.000 artikala. Sužite raspon i dohvatite novi pregled.');
                }
                $selected = $preview->items()->whereIn('id', $ids)->lockForUpdate()->get();
                if ($selected->count() !== count($ids) || $selected->contains(fn ($item): bool => ! in_array($item->status, ['update', 'updated'], true))) {
                    throw new RuntimeException('Odabrane stavke ne pripadaju ovom pregledu.');
                }
                $identities = $this->identities(true);
                $updated = $skipped = 0;
                foreach ($selected as $candidate) {
                    $entry = ['plan' => $candidate->plan, 'source' => $candidate->source_payload];
                    $plan = $entry['plan'];
                    $matches = $identities['id:'.$plan['model']] ?? [];
                    if ($candidate->status === 'updated' || count($matches) !== 1 || $matches[0] !== (int) $candidate->product_id) {
                        $skipped++;
                        $this->item($run, $entry, 'skipped', $candidate->product_id, 'Stavka je već obrađena ili je povezivanje promijenjeno. Dohvatite novi pregled.');

                        continue;
                    }
                    $product = Product::query()->lockForUpdate()->findOrFail($candidate->product_id);
                    if ($operation === 'prices') {
                        $this->assertPricePlan($plan);
                        if ($plan['old_base_price'] !== number_format((float) $product->base_price, 4, '.', '')
                            || $plan['old_tax_rate_id'] !== $product->tax_rate_id) {
                            $skipped++;
                            $this->item($run, $entry, 'skipped', $product->id, 'Cijena ili porez izmijenjeni su nakon pregleda. Postojeći podaci su sačuvani.');

                            continue;
                        }
                        $product->update(['base_price' => $plan['base_price'], 'updated_by' => $actorId]);
                    } else {
                        $translation = $product->translations()->where('locale', 'hr')->lockForUpdate()->first();
                        if ($translation?->name !== $plan['old_name']) {
                            $skipped++;
                            $this->item($run, $entry, 'skipped', $product->id, 'Naziv je izmijenjen nakon pregleda. Postojeći naziv je sačuvan.');

                            continue;
                        }
                        if ($translation) {
                            $translation->update(['name' => $plan['name']]);
                        } else {
                            $product->translations()->create([
                                'locale' => 'hr', 'name' => $plan['name'],
                                'slug' => (Str::slug($plan['name']) ?: 'artikl').'-erp-'.$product->id,
                                'payload' => ['source' => 'eracuni'],
                            ]);
                        }
                    }
                    Cache::forget('front:product:last-modified:'.$product->id);
                    $candidate->update(['status' => 'updated']);
                    $this->item($run, $entry, 'updated', $product->id);
                    $updated++;
                }
                $run->update(['status' => 'completed', 'updated_count' => $updated, 'skipped_count' => $skipped, 'completed_at' => now()]);
            });
        });
    }

    private function execute(string $kind, ?int $actorId, callable $operation): EracuniCatalogRun
    {
        $lock = Cache::lock('herrera-eracuni-catalog', 900);
        if (! $lock->get()) {
            throw new RuntimeException('Druga e-Računi obrada je u tijeku. Pokušajte ponovno nakon završetka.');
        }
        $priceLock = $kind === 'prices' ? Cache::lock('herrera-stock-sync:base_price', 900) : null;
        if ($priceLock && ! $priceLock->get()) {
            $lock->release();
            throw new RuntimeException('Drugo ažuriranje cijena je u tijeku. Pokušajte ponovno nakon završetka.');
        }
        $run = null;
        try {
            $run = EracuniCatalogRun::query()->create(['kind' => $kind, 'actor_id' => $actorId, 'status' => 'running', 'started_at' => now()]);
            $operation($run);
        } catch (Throwable) {
            if (! $run) {
                throw new RuntimeException('Izvještaj e-Računi nije moguće zapisati.');
            }
            $run->update([
                'status' => 'failed', 'completed_at' => now(),
                'error_message' => $run->fresh()->error_message ?: 'Obrada nije provedena. Provjerite izvor, valjanost podataka i odabir iz pregleda. Katalog je sačuvan.',
            ]);
        } finally {
            $priceLock?->release();
            $lock->release();
        }

        return $run->fresh();
    }

    private function persistPreview(EracuniCatalogRun $run, array $plans): void
    {
        $identities = $this->identities();
        DB::transaction(function () use ($plans, $identities, $run): void {
            $eligible = $skipped = 0;
            foreach ($plans as $entry) {
                $plan = $entry['plan'];
                $matches = $identities['id:'.$plan['model']] ?? [];
                $status = count($matches) > 1 ? 'ambiguous' : ($matches !== [] ? 'existing' : 'new');
                $message = count($matches) > 1 ? 'Šifra odgovara više postojećih artikala. Potrebno je ručno povezivanje.' : null;
                if ($status === 'new' && ! $plan['visible_online']) {
                    $status = 'skipped';
                    $message = ($plan['source'] ?? 'eracuni') === 'ideus_csv'
                        ? 'Proizvođač nije na popisu izvora Marka, Struhm i Braytron.'
                        : 'Artikl u e-Računi nije označen za prikaz u web trgovini.';
                }
                if ($status === 'new' && $plan['barcode'] && Product::query()->where('barcode', $plan['barcode'])->exists()) {
                    $status = 'ambiguous';
                    $message = 'Barkod već pripada drugom artiklu. Potrebna je provjera.';
                }
                $status === 'new' ? $eligible++ : $skipped++;
                $this->item($run, $entry, $status, count($matches) === 1 ? $matches[0] : null, $message);
            }
            $run->update(['status' => 'completed', 'eligible_count' => $eligible, 'skipped_count' => $skipped, 'completed_at' => now()]);
        });
    }

    private function csvPlans(array $rows): array
    {
        $plans = $seen = $manufacturerIds = [];
        $storeCurrency = $this->storeCurrency();
        $includesTax = $this->taxPricing->pricesIncludeTax();
        foreach ($rows as $row) {
            $values = $row['values'];
            $model = $this->text($values[0], 120);
            $brand = $this->text($values[21], 255);
            if ($row['row_number'] <= 2 && (in_array(mb_strtolower($model), ['sku', 'model', 'šifra', 'sifra', 'kod', 'code', 'productcode', 'product code'], true)
                || in_array(mb_strtolower($brand), ['marka', 'brand', 'proizvođač', 'proizvodjac'], true) && $model === '')) {
                continue;
            }
            if ($model === '' || preg_match('/[\x00-\x1f\x7f]/', $model) || str_starts_with($model, '=')) {
                throw new RuntimeException('CSV sadrži nevaljanu šifru artikla.');
            }
            $name = $this->text($values[1], 255);
            if ($name === '') {
                throw new RuntimeException('CSV artikl nema naziv.');
            }
            $description = $this->text($values[19], 1000000, false);
            $barcode = $this->text($values[8], 80);
            if ($barcode !== '' && (preg_match('/[\x00-\x1f\x7f]/', $barcode) || str_starts_with($barcode, '='))) {
                throw new RuntimeException('CSV sadrži nevaljan barkod.');
            }
            $rawPrice = $this->text($values[3], 120);
            $price = $this->price($rawPrice);
            $brandKey = mb_strtolower($brand);
            $allowed = in_array($brandKey, ['marka', 'struhm', 'braytron'], true);
            if (! array_key_exists($brandKey, $manufacturerIds)) {
                $manufacturerIds[$brandKey] = $allowed ? $this->manufacturerForBrand($brand) : null;
            }
            $notes = ['Izvorna CSV cijena je osnovna cijena iz starog uvoza. Porez i cijenu treba provjeriti prije objave.'];
            if ($price === null) {
                $notes[] = 'CSV cijena nije valjana; cijena nacrta je 0 i zahtijeva provjeru.';
            }
            if (! $manufacturerIds[$brandKey]) {
                $notes[] = 'Proizvođač nije nedvosmisleno povezan s postojećim aktivnim proizvođačem.';
            }
            $rawWeight = $this->text($values[44], 120);
            $rawHeight = $this->text($values[45], 120);
            if ($rawWeight !== '' || $rawHeight !== '') {
                $notes[] = 'Izvorna težina i visina sačuvane su u izvještaju; provjerite mjerne jedinice prije unosa u katalog.';
            }
            $attributesValid = true;
            try {
                $attributes = $this->attributes($description);
            } catch (RuntimeException) {
                $attributes = [];
                $attributesValid = false;
                $notes[] = 'Atributi nisu nedvosmisleni; potrebna je ručna provjera.';
            }
            $entry = ['source' => ['row_number' => $row['row_number'], 'values' => $values], 'plan' => [
                'source' => 'ideus_csv', 'model' => $model, 'sku' => $model, 'name' => $name, 'barcode' => $barcode ?: null,
                'description' => $description, 'gross_price' => null, 'raw_price' => $rawPrice,
                'base_price' => $price ?? '0.0000', 'currency' => null, 'store_currency' => $storeCurrency,
                'vat_percentage' => null, 'tax_rate_id' => null, 'tax_rate_value' => null, 'tax_rate_type' => null,
                'prices_include_tax' => $includesTax, 'price_state' => 'review', 'notes' => $notes,
                'brand' => $brand, 'manufacturer_id' => $manufacturerIds[$brandKey],
                'raw_weight' => $rawWeight, 'raw_height' => $rawHeight,
                'visible_online' => $allowed, 'attributes' => $attributes, 'attributes_valid' => $attributesValid,
            ]];
            if (isset($seen['id:'.$model])) {
                if ($seen['id:'.$model] !== $entry['plan']) {
                    throw new RuntimeException('CSV sadrži proturječne redove za istu šifru.');
                }

                continue;
            }
            $seen['id:'.$model] = $entry['plan'];
            $plans[] = $entry;
            if (count($plans) > IdeusCsvCatalogReader::MAX_ROWS) {
                throw new RuntimeException('CSV sadrži više od 10.000 artikala.');
            }
        }
        if ($plans === []) {
            throw new RuntimeException('CSV nema valjanih redaka artikala.');
        }

        return $plans;
    }

    private function manufacturerForBrand(string $brand): ?int
    {
        $key = mb_strtolower(trim($brand));
        $matches = Manufacturer::query()->where('is_active', true)->with(['translations' => fn ($query) => $query->where('locale', 'hr')])->get()
            ->filter(fn ($manufacturer): bool => mb_strtolower(trim($manufacturer->code)) === $key
                || $manufacturer->translations->contains(fn ($translation): bool => mb_strtolower(trim($translation->name)) === $key));

        return $matches->count() === 1 ? (int) $matches->first()->id : null;
    }

    private function fetchPlans(bool $parseAttributes = true, ?string $codeFrom = null, ?string $codeTo = null): array
    {
        $plans = [];
        $rates = TaxRate::query()->where('is_active', true)->get();
        $storeCurrency = $this->storeCurrency();
        $includesTax = $this->taxPricing->pricesIncludeTax();
        foreach ($this->client->products($codeFrom, $codeTo) as $source) {
            $identifier = trim((string) $source['productCode']);
            $name = $this->text($source['name'] ?? null, 255);
            if ($name === '') {
                throw new RuntimeException('ERP proizvod nema valjan naziv.');
            }
            $description = $this->text($source['description'] ?? '', 1000000, false);
            $barcode = $this->text($source['barCode'] ?? '', 80);
            $gross = $this->price($source['grossPrice'] ?? null);
            $currency = strtoupper($this->text($source['currency'] ?? '', 3));
            $vat = $this->vat($source['vatPercentage'] ?? null);
            $matchingRates = $rates->filter(fn ($rate): bool => $vat !== null && $rate->rate_type === 'percent' && abs((float) $rate->rate - $vat) < 0.0001);
            $defaults = $matchingRates->where('is_default', true);
            $tax = $defaults->count() === 1 ? $defaults->first() : ($matchingRates->count() === 1 ? $matchingRates->first() : null);
            $notes = [];
            if (! $tax) {
                $notes[] = 'ERP porezna stopa nije nedvosmisleno povezana s aktivnim lokalnim porezom. Cijenu nacrta treba ručno provjeriti.';
            }
            if ($gross === null) {
                $notes[] = 'ERP bruto cijena nije valjana ili nije zadana. Cijenu nacrta treba ručno provjeriti.';
            }
            if ($currency === '' || $currency !== $storeCurrency) {
                $notes[] = 'ERP valuta nije usklađena s valutom trgovine. Cijenu nacrta treba ručno provjeriti.';
            }
            $attributes = [];
            $attributesValid = true;
            if ($parseAttributes) {
                try {
                    $attributes = $this->attributes($description);
                } catch (RuntimeException) {
                    $attributesValid = false;
                    $notes[] = 'Opis sadrži predugačke ili proturječne atribute. Atributi nisu automatski preneseni; potrebna je provjera.';
                }
            }
            $priced = $tax !== null && $gross !== null && $currency !== '' && $currency === $storeCurrency;
            $base = $priced ? ($includesTax ? (float) $gross : $this->taxPricing->netFromGross((float) $gross, taxRate: $tax)) : 0;
            $plans[] = ['source' => $source, 'plan' => [
                'model' => $identifier, 'sku' => $identifier, 'name' => $name, 'barcode' => $barcode ?: null,
                'description' => $description, 'gross_price' => $gross,
                'currency' => $currency ?: null, 'store_currency' => $storeCurrency, 'vat_percentage' => $vat,
                'base_price' => number_format($base, 4, '.', ''), 'tax_rate_id' => $tax?->id,
                'tax_rate_value' => $tax?->rate, 'tax_rate_type' => $tax?->rate_type,
                'prices_include_tax' => $includesTax, 'price_state' => $priced ? 'mapped' : 'review', 'notes' => $notes,
                'visible_online' => ($source['onlineShopVisibility'] ?? '') === 'visibleOnline',
                'attributes' => $attributes, 'attributes_valid' => $attributesValid,
            ]];
        }

        return $plans;
    }

    private function recordFetch(EracuniCatalogRun $run, int $count, array $summary = []): void
    {
        $run->update(['fetched_count' => $count, 'summary' => $summary + $this->client->fetchMetadata()]);
    }

    private function limitReached(EracuniCatalogRun $run): bool
    {
        return (bool) ($run->summary['limit_reached'] ?? $run->fetched_count >= 10000);
    }

    private function existingPricePlan(Product $product, array $plan): array
    {
        $tax = $product->tax_rate_id ? TaxRate::query()->where('is_active', true)->find($product->tax_rate_id) : null;
        if (! $product->tax_rate_id && $plan['tax_rate_id']) {
            $tax = TaxRate::query()->where('is_active', true)->find($plan['tax_rate_id']);
        }
        $mapped = $tax !== null && $plan['gross_price'] !== null && $plan['currency'] === $plan['store_currency']
            && $plan['vat_percentage'] !== null && $tax->rate_type === 'percent'
            && abs((float) $tax->rate - $plan['vat_percentage']) < 0.0001;
        $plan['tax_rate_id'] = $tax?->id;
        $plan['tax_rate_value'] = $tax?->rate;
        $plan['tax_rate_type'] = $tax?->rate_type;
        $plan['price_state'] = $mapped ? 'mapped' : 'review';
        $plan['base_price'] = number_format($mapped
            ? ($plan['prices_include_tax'] ? (float) $plan['gross_price'] : $this->taxPricing->netFromGross((float) $plan['gross_price'], taxRate: $tax))
            : 0, 4, '.', '');
        $plan['notes'] = $mapped ? [] : ['Za ažuriranje cijene treba valjana ERP bruto cijena, usklađena valuta i ERP porezna stopa jednaka aktivnoj lokalnoj stopi artikla.'];

        return $plan;
    }

    private function assertPricePlan(array $plan): void
    {
        if ($plan['prices_include_tax'] !== $this->taxPricing->pricesIncludeTax()) {
            throw new RuntimeException('Postavke cijena su promijenjene. Dohvatite novi pregled.');
        }
        if ($plan['store_currency'] !== $this->storeCurrency()) {
            throw new RuntimeException('Valuta trgovine je promijenjena. Dohvatite novi pregled.');
        }
        if ($plan['tax_rate_id']) {
            $tax = TaxRate::query()->where('is_active', true)->find($plan['tax_rate_id']);
            if (! $tax || (string) $tax->rate !== (string) $plan['tax_rate_value'] || $tax->rate_type !== $plan['tax_rate_type']) {
                throw new RuntimeException('Porezna stopa je promijenjena. Dohvatite novi pregled.');
            }
        }
    }

    /** Numeric identifiers remain strings, including their leading zeros. */
    private function identities(bool $lock = false): array
    {
        $result = [];
        Product::query()->select(['id', 'sku', 'code', 'payload'])
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->chunkById(500, function ($products) use (&$result): void {
                foreach ($products as $product) {
                    $payload = $product->payload ?? [];
                    $references = [$product->sku, $payload['opencart']['sku'] ?? null, $payload['opencart']['model'] ?? null, $payload['eracuni']['productCode'] ?? null,
                        $payload['ideus_csv']['sku'] ?? null, $payload['ideus_csv']['model'] ?? null];
                    foreach (array_unique(array_filter(array_map(fn ($value): string => is_scalar($value) ? trim((string) $value) : '', $references), fn ($value): bool => $value !== '')) as $identifier) {
                        $result['id:'.$identifier][] = (int) $product->id;
                    }
                }
            });

        return $result;
    }

    private function attributes(string $description): array
    {
        $description = preg_replace('~<br\s*/?>|</(?:p|div|li)>~i', "\n", $description);
        $text = html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $values = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$label, $value] = array_map('trim', explode(':', $line, 2));
            if ($label === '' || $value === '') {
                continue;
            }
            if (mb_strlen($label) > 255 || mb_strlen($value) > 255 || count($values) >= 200) {
                throw new RuntimeException('ERP opis sadrži predugačak ili prevelik popis atributa.');
            }
            $key = hash('sha256', $label);
            if (isset($values[$key]) && $values[$key]['value'] !== $value) {
                throw new RuntimeException('ERP opis sadrži proturječne vrijednosti atributa.');
            }
            $values[$key] = ['label' => $label, 'value' => $value];
        }

        return array_values($values);
    }

    private function applyAttributes(Product $product, array $attributes, ?int $actorId, string $source = 'eracuni'): bool
    {
        $desired = [];
        foreach ($attributes as $position => $entry) {
            $groupCode = $source.'-attr-'.substr(hash('sha256', $entry['label']), 0, 40);
            $attributeCode = $source.'-value-'.substr(hash('sha256', $entry['label']."\0".$entry['value']), 0, 40);
            $desired[$attributeCode] = ['entry' => $entry, 'group_code' => $groupCode, 'position' => $position];
        }
        $existing = $product->attributes()->where('catalog_attributes.payload->source', $source)->get()->keyBy('code');
        $specifications = $product->technicalSpecificationRows()->where('source', $source)->get();
        $same = $existing->count() === count($desired) && $specifications->count() === count($desired);
        foreach ($desired as $code => $item) {
            $existingAttribute = $existing->get($code);
            $specification = $specifications->firstWhere('source_key', hash('sha256', $item['entry']['label']));
            if (! $existingAttribute || (int) $existingAttribute->pivot->sort_order !== $item['position']
                || ! $specification || $specification->item_name !== $item['entry']['label']
                || $specification->values !== [$item['entry']['value']] || $specification->sort_order !== $item['position']) {
                $same = false;
            }
        }
        if ($same) {
            return false;
        }
        $links = [];
        foreach ($desired as $code => $item) {
            $group = AttributeGroup::query()->firstOrCreate(['code' => $item['group_code']], [
                'type' => 'select', 'sort_order' => 0, 'payload' => ['source' => $source], 'created_by' => $actorId, 'updated_by' => $actorId,
            ]);
            if (($group->payload['source'] ?? '') !== $source) {
                throw new RuntimeException('ERP grupa atributa nije u vlasništvu izvora.');
            }
            $group->translations()->firstOrCreate(['locale' => 'hr'], ['name' => $item['entry']['label'], 'payload' => ['source' => $source]]);
            $attribute = Attribute::query()->firstOrCreate(['code' => $code], [
                'attribute_group_id' => $group->id, 'group_code' => $group->code, 'type' => 'select', 'is_active' => true,
                'sort_order' => 0, 'payload' => ['source' => $source], 'created_by' => $actorId, 'updated_by' => $actorId,
            ]);
            if (($attribute->payload['source'] ?? '') !== $source) {
                throw new RuntimeException('ERP atribut nije u vlasništvu izvora.');
            }
            $attribute->translations()->firstOrCreate(['locale' => 'hr'], [
                'group_name' => $item['entry']['label'], 'name' => $item['entry']['value'], 'slug' => $code, 'payload' => ['source' => $source],
            ]);
            $links[$attribute->id] = ['sort_order' => $item['position']];
            CatalogProductSpecification::query()->updateOrCreate([
                'product_id' => $product->id, 'source' => $source, 'source_key' => hash('sha256', $item['entry']['label']),
            ], [
                'group_name' => 'Specifikacije', 'item_name' => $item['entry']['label'], 'values' => [$item['entry']['value']],
                'sort_order' => $item['position'], 'payload' => ['source' => $source, 'locale' => 'hr'],
            ]);
        }
        $removeIds = $existing->pluck('id')->diff(array_keys($links))->all();
        if ($removeIds !== []) {
            $product->attributes()->detach($removeIds);
        }
        $product->attributes()->syncWithoutDetaching($links);
        $product->technicalSpecificationRows()->where('source', $source)
            ->whereNotIn('source_key', array_map(fn ($entry): string => hash('sha256', $entry['label']), $attributes))->delete();
        Cache::forget('front:product:last-modified:'.$product->id);

        return true;
    }

    private function text(mixed $value, int $maximum, bool $trim = true): string
    {
        if (! is_scalar($value) || is_bool($value)) {
            throw new RuntimeException('ERP proizvod sadrži neispravno tekstualno polje.');
        }
        $value = $trim ? trim((string) $value) : (string) $value;
        if (mb_strlen($value) > $maximum || str_contains($value, "\0")) {
            throw new RuntimeException('ERP proizvod sadrži predugačko tekstualno polje.');
        }

        return $value;
    }

    private function price(mixed $value): ?string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }
        $text = str_replace(',', '.', trim((string) $value));
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/D', $text) || (float) $text > 99999999.9999) {
            return null;
        }

        return number_format((float) $text, 4, '.', '');
    }

    private function vat(mixed $value): ?float
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }
        $text = str_replace(',', '.', trim((string) $value));

        return preg_match('/^\d+(?:\.\d+)?$/D', $text) && (float) $text <= 100 ? (float) $text : null;
    }

    private function storeCurrency(): string
    {
        return strtoupper((string) (Currency::query()->where('is_active', true)
            ->orderByDesc('is_default')->orderBy('sort_order')->orderBy('id')->value('code')
            ?: app(SystemSettingsService::class)->get('store_schema_product_currency', 'EUR')));
    }

    private function item(EracuniCatalogRun $run, array $entry, string $status, ?int $productId = null, ?string $message = null): void
    {
        EracuniCatalogItem::query()->create([
            'run_id' => $run->id, 'product_id' => $productId, 'identifier' => $entry['plan']['model'],
            'name' => $entry['plan']['name'], 'status' => $status, 'source_payload' => $entry['source'], 'plan' => $entry['plan'], 'message' => $message,
        ]);
    }
}
