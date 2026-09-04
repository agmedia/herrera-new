<?php

namespace App\Services\Integrations\Msan;

use App\Models\Catalog\Product\Product;
use App\Models\Integrations\Msan\MsanCategory;
use App\Models\Integrations\Msan\MsanCategoryMapping;
use App\Models\Integrations\Msan\MsanProduct;
use App\Models\Integrations\Msan\MsanSyncRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class MsanCatalogSyncService
{
    public const ADMIN_FILTER_OPTIONS_CACHE_KEY = 'integrations:msan:admin-product-filter-options';

    private const UPSERT_SIZE = 400;

    private const MAX_BARCODES_PER_PRODUCT = 64;

    public function __construct(
        private readonly MsanClient $client,
        private readonly MsanXmlStreamReader $xml,
    ) {}

    public function sync(MsanSyncRun $run): MsanSyncRun
    {
        $run->forceFill([
            'status' => MsanSyncRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?: now(),
            'completed_at' => null,
            'error_message' => null,
            'progress' => 1,
        ])->save();

        $snapshotAt = now();
        $directory = 'integrations/msan/sync/'.$run->id.'-'.bin2hex(random_bytes(5));
        Storage::disk('local')->makeDirectory($directory);
        $counts = [];
        $previousProductCount = MsanProduct::query()->where('is_stale', false)->count();
        $previousCategoryCount = MsanCategory::query()->where('is_stale', false)->count();

        try {
            $paths = [];
            // Structured specifications are intentionally a separate phase:
            // M SAN documents a feed up to 1 GB with a one-hour cooldown. The
            // initial catalog sync stays bounded to sellable catalog data and
            // the descriptions already included in the catalog dataset.
            foreach ([
                'categories' => 8,
                'catalog' => 20,
                'prices' => 32,
                'availability' => 44,
                'product_categories' => 56,
                'barcodes' => 68,
            ] as $dataset => $progress) {
                $paths[$dataset] = $this->downloadDataset($run, $dataset, $directory, $progress);
            }

            DB::transaction(function () use (
                &$counts,
                $paths,
                $snapshotAt,
                $previousProductCount,
                $previousCategoryCount,
            ): void {
                $counts['categories'] = $this->syncCategories($paths['categories'], $snapshotAt);
                $counts['catalog'] = $this->syncCatalog($paths['catalog'], $snapshotAt);
                $counts['prices'] = $this->syncPrices($paths['prices'], $snapshotAt);
                $counts['availability'] = $this->syncAvailability($paths['availability'], $snapshotAt);
                $counts['product_categories'] = $this->syncProductCategories($paths['product_categories'], $snapshotAt);
                $counts['barcodes'] = $this->syncBarcodes($paths['barcodes'], $snapshotAt);

                $emptyRequiredDataset = collect([
                    'categories',
                    'catalog',
                    'prices',
                    'availability',
                    'product_categories',
                ])->first(fn (string $dataset): bool => ($counts[$dataset] ?? 0) === 0);

                if ($emptyRequiredDataset !== null) {
                    throw new RuntimeException(sprintf(
                        'M SAN je vratio prazan ili nepovezan obavezni dataset "%s"; prethodni snapshot je sačuvan.',
                        $emptyRequiredDataset,
                    ));
                }

                $minimumProductCoverage = max(1, (int) ceil($counts['catalog'] * 0.5));
                foreach (['prices', 'availability', 'product_categories'] as $dataset) {
                    if ($counts[$dataset] < $minimumProductCoverage) {
                        throw new RuntimeException(sprintf(
                            'M SAN dataset "%s" pokriva premalo artikala (%d/%d); prethodni snapshot je sačuvan.',
                            $dataset,
                            $counts[$dataset],
                            $counts['catalog'],
                        ));
                    }
                }

                if (($previousProductCount > 0 && $counts['catalog'] < (int) floor($previousProductCount * 0.5))
                    || ($previousCategoryCount > 0 && $counts['categories'] < (int) floor($previousCategoryCount * 0.5))
                ) {
                    throw new RuntimeException('M SAN snapshot neočekivano je manji od prethodnog; prethodni snapshot je sačuvan.');
                }

                MsanProduct::query()
                    ->where(fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $snapshotAt))
                    ->update(['is_stale' => true]);
                MsanCategory::query()
                    ->where(fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $snapshotAt))
                    ->update(['is_stale' => true]);
                DB::table('msan_product_categories')
                    ->where(fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $snapshotAt))
                    ->delete();

                $this->ensureCategoryMappings();
                $this->rebuildCategoryPathsAndCounts();
                $this->refreshMatchStatuses();
            }, 3);

            Cache::forget(self::ADMIN_FILTER_OPTIONS_CACHE_KEY);

            $total = array_sum($counts);
            $run->forceFill([
                'status' => MsanSyncRun::STATUS_COMPLETED,
                'progress' => 100,
                'total_count' => $total,
                'processed_count' => $total,
                'succeeded_count' => $total,
                'summary' => [
                    'datasets' => $counts,
                    'products' => MsanProduct::query()->where('is_stale', false)->count(),
                    'categories' => MsanCategory::query()->where('is_stale', false)->count(),
                    'selected' => MsanProduct::query()->where('selected', true)->where('is_stale', false)->count(),
                    'unmapped_categories' => MsanCategoryMapping::query()->where('status', MsanCategoryMapping::STATUS_UNMAPPED)->count(),
                ],
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $summary = is_array($run->summary) ? $run->summary : [];
            $summary['last_attempt_failed_at'] = now()->toIso8601String();
            $run->forceFill([
                // The queue job owns retry/final-failure state. Keeping this run
                // active during backoff prevents a concurrent sync or import.
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

    private function downloadDataset(
        MsanSyncRun $run,
        string $dataset,
        string $directory,
        int $progress,
    ): string {
        $path = Storage::disk('local')->path($directory.'/'.$dataset.'.xml');
        $this->client->downloadDataset($dataset, $path);
        $run->forceFill(['progress' => $progress])->save();

        return $path;
    }

    private function syncCategories(string $path, mixed $seenAt): int
    {
        $buffer = [];
        $count = 0;
        $now = now();

        foreach ($this->xml->rows($path) as $row) {
            $externalId = trim((string) ($row['CategoryID'] ?? ''));
            $name = $this->nullableText($row['CategoryName'] ?? null, 255);
            if ($externalId === '' || mb_strlen($externalId) > 191 || $name === null) {
                continue;
            }

            $parent = trim((string) ($row['ParentCategoryID'] ?? ''));
            if (mb_strlen($parent) > 191) {
                $parent = '';
            }
            $buffer[] = [
                'external_id' => $externalId,
                'name' => $name,
                'parent_external_id' => $parent === '' || $parent === '0' || $parent === $externalId ? null : $parent,
                'last_seen_at' => $seenAt,
                'is_stale' => false,
                'payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $count++;

            if (count($buffer) >= self::UPSERT_SIZE) {
                $this->upsertCategories($buffer);
                $buffer = [];
            }
        }

        $this->upsertCategories($buffer);

        return $count;
    }

    /** @param list<array<string,mixed>> $rows */
    private function upsertCategories(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        DB::table('msan_categories')->upsert(
            $rows,
            ['external_id'],
            ['name', 'parent_external_id', 'last_seen_at', 'is_stale', 'payload', 'updated_at'],
        );
    }

    private function syncCatalog(string $path, mixed $seenAt): int
    {
        $buffer = [];
        $count = 0;
        $now = now();

        foreach ($this->xml->rows($path) as $row) {
            $code = trim((string) ($row['ProductCode'] ?? ''));
            if ($code === '' || mb_strlen($code) > 191) {
                continue;
            }

            $catalog = [
                'name' => $this->nullableText($row['ProductName'] ?? null, 255),
                'product_type' => $this->nullableText($row['ProductType'] ?? null, 255),
                'brand' => $this->nullableText($row['Brand'] ?? null, 255),
                'model' => $this->nullableText($row['Model'] ?? null, 120),
                'part_number' => $this->nullableText($row['PartNo'] ?? null, 120),
                'warranty_months' => $this->nullableInt($row['Warranty'] ?? null),
                'package_weight_kg' => $this->nullableDecimal($row['PackageWeight'] ?? null, 9_999_999.999),
                'package_length_cm' => $this->nullableDecimal($row['PackageDimensionLength'] ?? null, 9_999_999.999),
                'package_width_cm' => $this->nullableDecimal($row['PackageDimensionWidth'] ?? null, 9_999_999.999),
                'package_height_cm' => $this->nullableDecimal($row['PackageDimensionHeight'] ?? null, 9_999_999.999),
                'technical_description' => $this->nullableText($row['TechnicalDescription'] ?? null),
                'marketing_description' => $this->nullableText($row['MarketingDescription'] ?? null),
                'image_url' => $this->nullableText($row['ProductImageUrl'] ?? null, 2048),
            ];

            $buffer[] = $catalog + [
                'external_code' => $code,
                'currency_code' => 'EUR',
                'is_stale' => false,
                'catalog_checksum' => hash('sha256', json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'catalog_synced_at' => $seenAt,
                'last_seen_at' => $seenAt,
                'payload' => json_encode(['catalog' => $row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $count++;

            if (count($buffer) >= self::UPSERT_SIZE) {
                $this->upsertCatalog($buffer);
                $buffer = [];
            }
        }

        $this->upsertCatalog($buffer);

        return $count;
    }

    /** @param list<array<string,mixed>> $rows */
    private function upsertCatalog(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $existing = DB::table('msan_products')
            ->whereIn('external_code', array_column($rows, 'external_code'))
            ->get(['id', 'external_code', 'brand', 'model', 'part_number'])
            ->keyBy('external_code');
        $changedEprelIdentifierIds = [];
        foreach ($rows as $row) {
            $current = $existing->get((string) $row['external_code']);
            if (! $current) {
                continue;
            }
            if (trim((string) $current->brand) !== trim((string) ($row['brand'] ?? ''))
                || trim((string) $current->model) !== trim((string) ($row['model'] ?? ''))
                || trim((string) $current->part_number) !== trim((string) ($row['part_number'] ?? ''))) {
                $changedEprelIdentifierIds[] = (int) $current->id;
            }
        }

        DB::table('msan_products')->upsert(
            $rows,
            ['external_code'],
            [
                'name', 'product_type', 'brand', 'model', 'part_number', 'warranty_months',
                'package_weight_kg', 'package_length_cm', 'package_width_cm', 'package_height_cm',
                'technical_description', 'marketing_description', 'image_url', 'currency_code', 'is_stale',
                'catalog_checksum', 'catalog_synced_at', 'last_seen_at', 'payload', 'updated_at',
            ],
        );

        if ($changedEprelIdentifierIds !== []) {
            DB::table('msan_products')
                ->whereIn('id', $changedEprelIdentifierIds)
                ->update([
                    'eprel_match_status' => MsanProduct::EPREL_PENDING,
                    'eprel_identifier_checksum' => null,
                    'eprel_checked_at' => null,
                ]);
        }
    }

    private function syncPrices(string $path, mixed $seenAt): int
    {
        MsanProduct::query()
            ->where('last_seen_at', '>=', $seenAt)
            ->update([
                'list_price' => null,
                'discount_percent' => null,
                'partner_price' => null,
                'recommended_retail_price' => null,
                'on_promotion' => false,
                'price_checksum' => null,
                'price_synced_at' => null,
            ]);

        return $this->syncProductRows($path, function (array $row): array {
            $priceData = [
                'list_price' => $this->nullableDecimal($row['ProductListPrice'] ?? null),
                'discount_percent' => $this->nullableDecimal($row['ProductDiscount'] ?? null, 999.9999),
                'partner_price' => $this->nullableDecimal($row['ProductPartnerPrice'] ?? null),
                'recommended_retail_price' => $this->nullableDecimal($row['RecommendedRetailPrice'] ?? null),
                'availability_level' => $this->availability($row['ProductAvailability'] ?? null),
                'on_promotion' => $this->boolean($row['OnPromotion'] ?? false),
                'currency_code' => 'EUR',
            ];

            $data = $priceData + ['price_synced_at' => now()];
            $data['price_checksum'] = hash('sha256', json_encode($priceData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $data;
        });
    }

    private function syncAvailability(string $path, mixed $seenAt): int
    {
        MsanProduct::query()
            ->where('last_seen_at', '>=', $seenAt)
            ->update([
                'availability_level' => null,
                'availability_checksum' => null,
                'availability_synced_at' => null,
            ]);

        return $this->syncProductRows($path, function (array $row): array {
            $availabilityData = [
                'availability_level' => $this->availability($row['ProductAvailability'] ?? null),
            ];

            $data = $availabilityData + ['availability_synced_at' => now()];
            $data['availability_checksum'] = hash('sha256', json_encode($availabilityData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $data;
        });
    }

    /** @param callable(array<string,string>): array<string,mixed> $transform */
    private function syncProductRows(string $path, callable $transform): int
    {
        $buffer = [];
        $count = 0;

        foreach ($this->xml->rows($path) as $row) {
            $code = trim((string) ($row['ProductCode'] ?? ''));
            if ($code === '' || mb_strlen($code) > 191) {
                continue;
            }
            $buffer[$code] = $transform($row);

            if (count($buffer) >= self::UPSERT_SIZE) {
                $count += $this->updateProductsByCode($buffer);
                $buffer = [];
            }
        }

        $count += $this->updateProductsByCode($buffer);

        return $count;
    }

    /** @param array<string,array<string,mixed>> $updates */
    private function updateProductsByCode(array $updates): int
    {
        if ($updates === []) {
            return 0;
        }

        $products = MsanProduct::query()
            ->whereIn('external_code', array_keys($updates))
            ->get(['id', 'external_code'])
            ->keyBy('external_code');
        $now = now();
        $rows = [];
        $columns = [];

        foreach ($updates as $code => $data) {
            $product = $products->get($code);
            if (! $product) {
                continue;
            }
            $rows[] = [
                'id' => (int) $product->id,
                'external_code' => (string) $product->external_code,
                'updated_at' => $now,
            ] + $data;
            $columns = array_values(array_unique([...$columns, ...array_keys($data), 'updated_at']));
        }

        if ($rows !== []) {
            DB::table('msan_products')->upsert($rows, ['id'], $columns);
        }

        return count($rows);
    }

    private function syncProductCategories(string $path, mixed $seenAt): int
    {
        $buffer = [];

        foreach ($this->xml->rows($path) as $row) {
            $code = trim((string) ($row['ProductCode'] ?? ''));
            $categoryId = trim((string) ($row['CategoryID'] ?? ''));
            if ($code === '' || $categoryId === '' || mb_strlen($code) > 191 || mb_strlen($categoryId) > 191) {
                continue;
            }

            $buffer[] = ['code' => $code, 'category' => $categoryId];
            if (count($buffer) >= self::UPSERT_SIZE) {
                $this->upsertProductCategories($buffer, $seenAt);
                $buffer = [];
            }
        }

        $this->upsertProductCategories($buffer, $seenAt);

        return DB::table('msan_product_categories')
            ->where('last_seen_at', '>=', $seenAt)
            ->distinct()
            ->count('msan_product_id');
    }

    /** @param list<array{code:string,category:string}> $assignments */
    private function upsertProductCategories(array $assignments, mixed $seenAt): void
    {
        if ($assignments === []) {
            return;
        }

        $products = MsanProduct::query()
            ->whereIn('external_code', array_values(array_unique(array_column($assignments, 'code'))))
            ->pluck('id', 'external_code');
        $categories = MsanCategory::query()
            ->whereIn('external_id', array_values(array_unique(array_column($assignments, 'category'))))
            ->pluck('id', 'external_id');
        $now = now();
        $rows = [];

        foreach ($assignments as $assignment) {
            $productId = $products[$assignment['code']] ?? null;
            $categoryId = $categories[$assignment['category']] ?? null;
            if (! $productId || ! $categoryId) {
                continue;
            }
            $rows[] = [
                'msan_product_id' => (int) $productId,
                'msan_category_id' => (int) $categoryId,
                'last_seen_at' => $seenAt,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('msan_product_categories')->upsert(
                $rows,
                ['msan_product_id', 'msan_category_id'],
                ['last_seen_at', 'updated_at'],
            );
        }
    }

    private function syncBarcodes(string $path, mixed $seenAt): int
    {
        MsanProduct::query()
            ->where('last_seen_at', '>=', $seenAt)
            ->update(['barcodes' => null]);

        $buffer = [];
        $bufferedRows = 0;
        $count = 0;

        foreach ($this->xml->rows($path) as $row) {
            $code = trim((string) ($row['ProductCode'] ?? ''));
            $value = trim((string) ($row['BarcodeValue'] ?? ''));
            if ($code === '' || $value === '' || mb_strlen($code) > 191 || mb_strlen($value) > 80) {
                continue;
            }

            if (count($buffer[$code] ?? []) >= self::MAX_BARCODES_PER_PRODUCT
                || isset($buffer[$code][$value])
            ) {
                continue;
            }

            $buffer[$code][$value] = [
                'type' => mb_substr(strtoupper(trim((string) ($row['BarcodeType'] ?? ''))), 0, 32),
                'value' => $value,
            ];
            $bufferedRows++;

            if ($bufferedRows >= self::UPSERT_SIZE) {
                $count += $this->flushBarcodes($buffer);
                $buffer = [];
                $bufferedRows = 0;
            }
        }

        $count += $this->flushBarcodes($buffer);

        return $count;
    }

    /** @param array<string,array<string,array{type:string,value:string}>> $buffer */
    private function flushBarcodes(array $buffer): int
    {
        if ($buffer === []) {
            return 0;
        }

        $products = MsanProduct::query()
            ->whereIn('external_code', array_keys($buffer))
            ->get(['external_code', 'barcodes'])
            ->keyBy('external_code');
        $updates = [];
        $accepted = 0;

        foreach ($buffer as $code => $barcodes) {
            $product = $products->get($code);
            if (! $product) {
                continue;
            }

            $merged = [];
            foreach (is_array($product->barcodes) ? $product->barcodes : [] as $barcode) {
                if (! is_array($barcode)) {
                    continue;
                }
                $value = trim((string) ($barcode['value'] ?? ''));
                if ($value !== '') {
                    $merged[$value] = $barcode;
                }
            }
            foreach ($barcodes as $value => $barcode) {
                if (count($merged) >= self::MAX_BARCODES_PER_PRODUCT) {
                    break;
                }
                if (! isset($merged[$value])) {
                    $merged[$value] = $barcode;
                    $accepted++;
                }
            }

            $updates[$code] = [
                'barcodes' => json_encode(array_values($merged), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        $this->updateProductsByCode($updates);

        return $accepted;
    }

    private function ensureCategoryMappings(): void
    {
        MsanCategory::query()
            ->whereDoesntHave('mapping')
            ->select('id')
            ->chunkById(self::UPSERT_SIZE, function ($categories): void {
                $now = now();
                DB::table('msan_category_mappings')->insertOrIgnore(
                    $categories->map(fn ($category) => [
                        'msan_category_id' => (int) $category->id,
                        'status' => MsanCategoryMapping::STATUS_UNMAPPED,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });
    }

    private function rebuildCategoryPathsAndCounts(): void
    {
        $categories = MsanCategory::query()->get(['id', 'external_id', 'name', 'parent_external_id'])->keyBy('external_id');
        $resolved = [];

        $pathFor = function (string $externalId, array $trail = []) use (&$pathFor, &$resolved, $categories): string {
            if (isset($resolved[$externalId])) {
                return $resolved[$externalId];
            }
            $category = $categories->get($externalId);
            if (! $category) {
                return $externalId;
            }
            if (isset($trail[$externalId])) {
                return (string) $category->name;
            }
            $trail[$externalId] = true;
            $parentId = trim((string) ($category->parent_external_id ?? ''));
            $resolved[$externalId] = $parentId !== '' && $categories->has($parentId)
                ? $pathFor($parentId, $trail).' > '.$category->name
                : (string) $category->name;

            return $resolved[$externalId];
        };

        $counts = DB::table('msan_product_categories')
            ->selectRaw('msan_category_id, COUNT(*) as aggregate')
            ->groupBy('msan_category_id')
            ->pluck('aggregate', 'msan_category_id');
        $now = now();
        $updates = [];
        foreach ($categories as $category) {
            $updates[] = [
                'id' => (int) $category->id,
                'external_id' => (string) $category->external_id,
                'name' => (string) $category->name,
                'path' => $pathFor((string) $category->external_id),
                'product_count' => (int) ($counts[$category->id] ?? 0),
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($updates, self::UPSERT_SIZE) as $chunk) {
            DB::table('msan_categories')->upsert($chunk, ['id'], ['path', 'product_count', 'updated_at']);
        }
    }

    private function refreshMatchStatuses(): void
    {
        MsanProduct::query()
            ->where('match_status', '!=', MsanProduct::MATCH_IGNORED)
            ->whereNotNull('local_product_id')
            ->update(['match_status' => MsanProduct::MATCH_MATCHED]);
        MsanProduct::query()
            ->where('match_status', '!=', MsanProduct::MATCH_IGNORED)
            ->whereNull('local_product_id')
            ->update(['match_status' => MsanProduct::MATCH_UNMATCHED]);

        MsanProduct::query()
            ->whereNull('local_product_id')
            ->where('match_status', '!=', MsanProduct::MATCH_IGNORED)
            ->select(['id', 'external_code', 'barcodes'])
            ->chunkById(self::UPSERT_SIZE, function ($rows): void {
                $codes = $rows->pluck('external_code')->filter()->all();
                $barcodes = $rows->flatMap(fn (MsanProduct $product) => collect($product->barcodes ?? [])->pluck('value'))->filter()->all();
                $collisions = Product::query()
                    ->where(fn ($query) => $query
                        ->whereIn('code', $codes)
                        ->orWhereIn('sku', $codes)
                        ->when($barcodes !== [], fn ($barcodeQuery) => $barcodeQuery->orWhereIn('barcode', $barcodes)))
                    ->get(['code', 'sku', 'barcode']);
                $collisionValues = $collisions->flatMap(fn (Product $product) => [$product->code, $product->sku, $product->barcode])->filter()->flip();
                $ids = $rows->filter(function (MsanProduct $product) use ($collisionValues): bool {
                    if ($collisionValues->has($product->external_code)) {
                        return true;
                    }

                    return collect($product->barcodes ?? [])->contains(fn ($barcode) => $collisionValues->has((string) ($barcode['value'] ?? '')));
                })->pluck('id');

                if ($ids->isNotEmpty()) {
                    MsanProduct::query()->whereIn('id', $ids)->update(['match_status' => MsanProduct::MATCH_CONFLICT]);
                }
            });
    }

    private function nullableText(mixed $value, ?int $maxLength = null): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $maxLength === null ? $value : mb_substr($value, 0, $maxLength);
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, min(65535, (int) $value)) : null;
    }

    private function nullableDecimal(mixed $value, float $max = 99_999_999.9999): ?string
    {
        $value = str_replace(',', '.', trim((string) $value));
        $number = is_numeric($value) ? (float) $value : null;

        return $number !== null && is_finite($number) && abs($number) <= $max
            ? number_format($number, 4, '.', '')
            : null;
    }

    private function availability(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, min(4, (int) $value)) : null;
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
