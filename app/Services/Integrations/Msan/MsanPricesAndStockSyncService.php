<?php

namespace App\Services\Integrations\Msan;

use App\Models\Catalog\Product\Product;
use App\Models\Import\CatalogSourceMapping;
use App\Models\Integrations\Msan\MsanProduct;
use App\Models\Integrations\Msan\MsanSyncRun;
use App\Services\Pricing\TaxPricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class MsanPricesAndStockSyncService
{
    private const UPSERT_SIZE = 400;

    private const MINIMUM_COVERAGE_RATIO = 0.5;

    /** @var list<string> */
    private const SUPPLIER_SNAPSHOT_FIELDS = [
        'external_code',
        'product_type',
        'brand',
        'model',
        'part_number',
        'warranty_months',
        'currency_code',
        'list_price',
        'discount_percent',
        'partner_price',
        'recommended_retail_price',
        'availability_level',
        'on_promotion',
    ];

    public function __construct(
        private readonly MsanClient $client,
        private readonly MsanXmlStreamReader $xml,
        private readonly MsanSettingsService $settings,
        private readonly TaxPricingService $taxPricing,
    ) {}

    public function sync(MsanSyncRun $run): MsanSyncRun
    {
        $run->forceFill([
            'status' => MsanSyncRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?: now(),
            'completed_at' => null,
            'error_message' => null,
            'progress' => 5,
        ])->save();

        $directory = 'integrations/msan/prices-stock/'.$run->id.'-'.bin2hex(random_bytes(5));
        $pricesPath = Storage::disk('local')->path($directory.'/prices.xml');
        $availabilityPath = Storage::disk('local')->path($directory.'/availability.xml');
        Storage::disk('local')->makeDirectory($directory);

        try {
            $importedProducts = MsanProduct::query()
                ->where('import_status', MsanProduct::IMPORT_IMPORTED)
                ->whereNotNull('local_product_id')
                ->get(['external_code', 'is_stale']);
            if ($importedProducts->isEmpty()) {
                throw new RuntimeException('Nema uvezenih M SAN artikala za osvježavanje.');
            }

            $importedProductCodes = $importedProducts
                ->where('is_stale', false)
                ->pluck('external_code')
                ->map(static fn ($code): string => trim((string) $code))
                ->filter()
                ->flip()
                ->map(static fn (): bool => true)
                ->all();
            $importedProductCount = count($importedProductCodes);
            $datasets = [];

            if ($importedProductCount > 0) {
                // M SAN exposes each refresh as one supplier-wide file. Stream
                // that file, but stage and publish only the imported products.
                $this->client->downloadDataset('prices', $pricesPath);
                $run->forceFill(['progress' => 25])->save();
                $this->client->downloadDataset('availability', $availabilityPath);
                $run->forceFill(['progress' => 45])->save();
                $datasets = ['prices', 'availability'];

                $result = DB::transaction(function () use ($pricesPath, $availabilityPath, $importedProductCodes, $importedProductCount): array {
                    $this->clearActivePrices();
                    $priceStats = $this->syncStagingPrices($pricesPath, $importedProductCodes);
                    if ($priceStats['feed_usable'] === 0) {
                        throw new RuntimeException('M SAN skup nema valjanih MPC cijena; prethodne cijene i zalihe su sačuvane.');
                    }

                    $this->clearActiveAvailability();
                    $availabilityStats = $this->syncStagingAvailability($availabilityPath, $importedProductCodes);
                    if ($availabilityStats['matched'] !== $availabilityStats['usable']) {
                        throw new RuntimeException(sprintf(
                            'M SAN skup dostupnosti sadrži nevaljanu vrijednost za %d poznatih artikala; prethodne cijene i zalihe su sačuvane.',
                            $availabilityStats['matched'] - $availabilityStats['usable'],
                        ));
                    }
                    $this->assertCoverage('dostupnosti', $availabilityStats['usable'], $importedProductCount);

                    return [
                        'prices_matched' => $priceStats['usable'],
                        'availability_matched' => $availabilityStats['usable'],
                    ] + $this->refreshOwnedLocalProducts();
                }, 3);
            } else {
                // A catalog refresh may mark the last imported products stale.
                // Their local stock must still be zeroed without depending on
                // another supplier download.
                $result = DB::transaction(fn (): array => [
                    'prices_matched' => 0,
                    'availability_matched' => 0,
                ] + $this->refreshOwnedLocalProducts(), 3);
            }

            $totalRows = $importedProductCount * 2;
            $processedRows = $result['prices_matched'] + $result['availability_matched'];
            $run->forceFill([
                'status' => MsanSyncRun::STATUS_COMPLETED,
                'progress' => 100,
                'total_count' => $totalRows,
                'processed_count' => $processedRows,
                'succeeded_count' => $processedRows,
                'skipped_count' => max(0, $totalRows - $processedRows),
                'summary' => [
                    'datasets' => $datasets,
                    'local_prices_updated' => $result['prices_updated'],
                    'local_stock_updated' => $result['stock_updated'],
                    'local_products_not_msan_owned' => $result['not_owned'],
                    'local_products_eligible' => $result['eligible'],
                    'local_prices_unchanged' => $result['prices_unchanged'],
                    'local_prices_missing' => $result['prices_missing'],
                    'local_stock_unchanged' => $result['stock_unchanged'],
                    'local_supplier_snapshots_updated' => $result['snapshots_updated'],
                    'local_stale_stock_zeroed' => $result['stale_stock_zeroed'],
                    'staging_products' => $importedProductCount,
                    'price_rows_matched' => $result['prices_matched'],
                    'availability_rows_matched' => $result['availability_matched'],
                ],
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $summary = is_array($run->summary) ? $run->summary : [];
            $summary['last_attempt_failed_at'] = now()->toIso8601String();
            $run->forceFill([
                // The queue job owns retry and terminal failure state.
                'status' => MsanSyncRun::STATUS_RUNNING,
                'error_message' => $this->sanitizeError($exception->getMessage()),
                'summary' => $summary,
                'completed_at' => null,
            ])->save();

            throw $exception;
        } finally {
            Storage::disk('local')->deleteDirectory($directory);
        }

        return $run->refresh();
    }

    private function clearActivePrices(): void
    {
        MsanProduct::query()
            ->where('is_stale', false)
            ->where('import_status', MsanProduct::IMPORT_IMPORTED)
            ->whereNotNull('local_product_id')
            ->update([
                'list_price' => null,
                'discount_percent' => null,
                'partner_price' => null,
                'recommended_retail_price' => null,
                'on_promotion' => false,
                'price_checksum' => null,
                'price_synced_at' => null,
            ]);
    }

    private function clearActiveAvailability(): void
    {
        MsanProduct::query()
            ->where('is_stale', false)
            ->where('import_status', MsanProduct::IMPORT_IMPORTED)
            ->whereNotNull('local_product_id')
            ->update([
                'availability_level' => null,
                'availability_checksum' => null,
                'availability_synced_at' => null,
            ]);
    }

    /**
     * @param  array<string, bool>  $importedProductCodes
     * @return array{matched:int, usable:int, feed_usable:int}
     */
    private function syncStagingPrices(string $path, array $importedProductCodes): array
    {
        return $this->syncStagingRows($path, $importedProductCodes, function (array $row): array {
            $recommendedRetailPrice = $this->nullableDecimal($row['RecommendedRetailPrice'] ?? null);
            $priceData = [
                'list_price' => $this->nullableDecimal($row['ProductListPrice'] ?? null),
                'discount_percent' => $this->nullableDecimal($row['ProductDiscount'] ?? null, 999.9999),
                'partner_price' => $this->nullableDecimal($row['ProductPartnerPrice'] ?? null),
                'recommended_retail_price' => $recommendedRetailPrice,
                'on_promotion' => $this->boolean($row['OnPromotion'] ?? false),
                'currency_code' => 'EUR',
            ];

            return [
                'data' => $priceData + [
                    'price_checksum' => hash(
                        'sha256',
                        json_encode($priceData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ),
                    'price_synced_at' => now(),
                ],
                'usable' => $recommendedRetailPrice !== null && (float) $recommendedRetailPrice > 0,
            ];
        }, inspectWholeFeed: true);
    }

    /**
     * @param  array<string, bool>  $importedProductCodes
     * @return array{matched:int, usable:int, feed_usable:int}
     */
    private function syncStagingAvailability(string $path, array $importedProductCodes): array
    {
        return $this->syncStagingRows($path, $importedProductCodes, function (array $row): array {
            $availabilityLevel = $this->availabilityLevel($row['ProductAvailability'] ?? null);
            $availabilityData = [
                'availability_level' => $availabilityLevel,
            ];

            return [
                'data' => $availabilityData + [
                    'availability_checksum' => hash(
                        'sha256',
                        json_encode($availabilityData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ),
                    'availability_synced_at' => now(),
                ],
                'usable' => $availabilityLevel !== null,
            ];
        });
    }

    /**
     * @param  array<string, bool>  $importedProductCodes
     * @param  callable(array<string, string>): array{data:array<string, mixed>, usable:bool}  $transform
     * @return array{matched:int, usable:int, feed_usable:int}
     */
    private function syncStagingRows(
        string $path,
        array $importedProductCodes,
        callable $transform,
        bool $inspectWholeFeed = false,
    ): array {
        $feedUsable = 0;
        $buffer = [];
        $seenCodes = [];
        $matched = 0;
        $usable = 0;

        foreach ($this->xml->rows($path) as $row) {
            $code = trim((string) ($row['ProductCode'] ?? ''));
            $isImported = isset($importedProductCodes[$code]);
            if ($code === ''
                || mb_strlen($code) > 191
                || (! $isImported && ! $inspectWholeFeed)
                || isset($seenCodes[$code])) {
                continue;
            }

            $seenCodes[$code] = true;
            $transformed = $transform($row);
            if ($inspectWholeFeed && $transformed['usable']) {
                $feedUsable++;
            }
            if (! $isImported) {
                continue;
            }

            $buffer[$code] = $transformed;

            if (count($buffer) >= self::UPSERT_SIZE) {
                $result = $this->updateProductsByCode($buffer);
                $matched += $result['matched'];
                $usable += $result['usable'];
                $buffer = [];
            }
        }

        $result = $this->updateProductsByCode($buffer);

        return [
            'matched' => $matched + $result['matched'],
            'usable' => $usable + $result['usable'],
            'feed_usable' => $inspectWholeFeed ? $feedUsable : $usable + $result['usable'],
        ];
    }

    /**
     * @param  array<string, array{data:array<string, mixed>, usable:bool}>  $updates
     * @return array{matched:int, usable:int}
     */
    private function updateProductsByCode(array $updates): array
    {
        if ($updates === []) {
            return ['matched' => 0, 'usable' => 0];
        }

        $products = MsanProduct::query()
            ->where('is_stale', false)
            ->where('import_status', MsanProduct::IMPORT_IMPORTED)
            ->whereNotNull('local_product_id')
            ->whereIn('external_code', array_keys($updates))
            ->get(['id', 'external_code'])
            ->keyBy('external_code');
        $now = now();
        $rows = [];
        $columns = [];
        $usable = 0;

        foreach ($updates as $code => $update) {
            $product = $products->get($code);
            if (! $product) {
                continue;
            }

            $data = $update['data'];
            $rows[] = [
                'id' => (int) $product->id,
                'external_code' => (string) $product->external_code,
                'updated_at' => $now,
            ] + $data;
            $columns = array_values(array_unique([...$columns, ...array_keys($data), 'updated_at']));
            $usable += $update['usable'] ? 1 : 0;
        }

        if ($rows !== []) {
            DB::table('msan_products')->upsert($rows, ['id'], $columns);
        }

        return ['matched' => count($rows), 'usable' => $usable];
    }

    private function assertCoverage(string $datasetLabel, int $matched, int $activeProductCount): void
    {
        $minimumCoverage = max(1, (int) ceil($activeProductCount * self::MINIMUM_COVERAGE_RATIO));
        if ($matched < $minimumCoverage) {
            throw new RuntimeException(sprintf(
                'M SAN skup %s pokriva premalo artikala (%d/%d); prethodne cijene i zalihe su sačuvane.',
                $datasetLabel,
                $matched,
                $activeProductCount,
            ));
        }
    }

    /**
     * @return array{
     *     eligible:int,
     *     prices_updated:int,
     *     prices_unchanged:int,
     *     prices_missing:int,
     *     stock_updated:int,
     *     stock_unchanged:int,
     *     snapshots_updated:int,
     *     stale_stock_zeroed:int,
     *     not_owned:int
     * }
     */
    private function refreshOwnedLocalProducts(): array
    {
        $counts = [
            'eligible' => 0,
            'prices_updated' => 0,
            'prices_unchanged' => 0,
            'prices_missing' => 0,
            'stock_updated' => 0,
            'stock_unchanged' => 0,
            'snapshots_updated' => 0,
            'stale_stock_zeroed' => 0,
            'not_owned' => 0,
        ];

        MsanProduct::query()
            ->whereNotNull('local_product_id')
            ->where('import_status', MsanProduct::IMPORT_IMPORTED)
            ->select([
                'id', 'external_code', 'product_type', 'brand', 'model', 'part_number',
                'warranty_months', 'currency_code', 'list_price', 'discount_percent',
                'partner_price', 'recommended_retail_price', 'availability_level',
                'on_promotion', 'local_product_id', 'is_stale',
            ])
            ->chunkById(self::UPSERT_SIZE, function ($sources) use (&$counts): void {
                $localIds = $sources
                    ->pluck('local_product_id')
                    ->filter()
                    ->map(static fn ($id): int => (int) $id)
                    ->values()
                    ->all();
                if ($localIds === []) {
                    return;
                }

                // Lock local products while applying supplier values so a
                // concurrent administrator or ERP write is not silently lost.
                $products = Product::query()
                    ->whereIn('id', $localIds)
                    ->lockForUpdate()
                    ->get(['id', 'tax_rate_id', 'base_price', 'stock_qty', 'payload'])
                    ->keyBy('id');
                $catalogOwnerIds = CatalogSourceMapping::query()
                    ->where('entity_type', CatalogSourceMapping::ENTITY_PRODUCT)
                    ->whereIn('local_id', $localIds)
                    // This must be a current/locking read under MySQL's
                    // REPEATABLE READ. An ERP adoption may have committed its
                    // ownership mapping after this sync transaction began.
                    ->lockForUpdate()
                    ->pluck('local_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->flip();

                foreach ($sources as $source) {
                    $localId = (int) $source->local_product_id;
                    /** @var Product|null $product */
                    $product = $products->get($localId);
                    if (! $product || ! $this->isMsanOwned($product, (string) $source->external_code, $catalogOwnerIds->has($localId))) {
                        $counts['not_owned']++;

                        continue;
                    }

                    $counts['eligible']++;
                    $isStale = (bool) $source->is_stale;
                    $recommendedPrice = $isStale
                        ? null
                        : $this->sellablePrice($source->recommended_retail_price, $product);
                    if ($recommendedPrice === null) {
                        $counts['prices_missing']++;
                    } elseif (number_format((float) $product->base_price, 2, '.', '') === $recommendedPrice) {
                        $counts['prices_unchanged']++;
                    } else {
                        $product->base_price = $recommendedPrice;
                        $counts['prices_updated']++;
                    }

                    $quantity = $isStale
                        ? 0
                        : $this->settings->stockLevelQuantity(
                            max(0, min(4, (int) ($source->availability_level ?? 0))),
                        );
                    if ((int) $product->stock_qty === $quantity) {
                        $counts['stock_unchanged']++;
                    } else {
                        $product->stock_qty = $quantity;
                        $counts['stock_updated']++;
                        if ($isStale) {
                            $counts['stale_stock_zeroed']++;
                        }
                    }

                    if ($this->refreshSupplierSnapshot($product, $source)) {
                        $counts['snapshots_updated']++;
                    }

                    if ($product->isDirty()) {
                        // Saving through Eloquent intentionally records base price
                        // changes through ProductPriceObserver.
                        $product->save();
                    }
                }
            });

        return $counts;
    }

    private function isMsanOwned(Product $product, string $externalCode, bool $hasCatalogOwner): bool
    {
        $payload = is_array($product->payload) ? $product->payload : [];

        return data_get($payload, 'catalog_origin') === 'msan'
            && ! $hasCatalogOwner
            && empty($payload['import_sources'] ?? [])
            && (string) data_get($payload, 'supplier_sources.msan.external_code') === $externalCode;
    }

    private function refreshSupplierSnapshot(Product $product, MsanProduct $source): bool
    {
        $payload = is_array($product->payload) ? $product->payload : [];
        $current = is_array(data_get($payload, 'supplier_sources.msan'))
            ? data_get($payload, 'supplier_sources.msan')
            : [];
        $snapshot = [];
        foreach (self::SUPPLIER_SNAPSHOT_FIELDS as $field) {
            $snapshot[$field] = $source->{$field};
        }

        $comparableCurrent = [];
        foreach (self::SUPPLIER_SNAPSHOT_FIELDS as $field) {
            $comparableCurrent[$field] = $current[$field] ?? null;
        }
        if ($comparableCurrent === $snapshot) {
            return false;
        }

        $snapshot['synced_at'] = now()->toIso8601String();
        $payload['supplier_sources']['msan'] = $snapshot;
        $product->payload = $payload;

        return true;
    }

    private function sellablePrice(mixed $value, Product $product): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        $price = (float) $value;
        if (! is_finite($price) || $price <= 0 || $price > 99_999_999.99) {
            return null;
        }

        // RecommendedRetailPrice is an MPC (gross consumer price). Keep the
        // canonical base_price consistent with the shop's configured tax
        // storage mode, just like the ERP pricing import does.
        $storedPrice = $this->taxPricing->pricesIncludeTax()
            ? round($price, 2)
            : $this->taxPricing->netFromGross($price, $product);

        return is_finite($storedPrice) && $storedPrice > 0
            ? number_format($storedPrice, 2, '.', '')
            : null;
    }

    private function nullableDecimal(mixed $value, float $max = 99_999_999.9999): ?string
    {
        $value = str_replace(',', '.', trim((string) $value));
        $number = is_numeric($value) ? (float) $value : null;

        return $number !== null && is_finite($number) && abs($number) <= $max
            ? number_format($number, 4, '.', '')
            : null;
    }

    private function availabilityLevel(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        if (! is_finite($number) || floor($number) !== $number || $number < 0 || $number > 4) {
            return null;
        }

        return (int) $number;
    }

    private function boolean(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'da'], true);
    }

    private function sanitizeError(string $message): string
    {
        $message = preg_replace('/(password|passphrase|pin)\s*[=:]\s*\S+/iu', '$1=[skriveno]', $message) ?? $message;

        return mb_substr(trim($message), 0, 1500);
    }
}
