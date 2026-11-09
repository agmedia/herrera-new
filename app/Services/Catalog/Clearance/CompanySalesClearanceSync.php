<?php

namespace App\Services\Catalog\Clearance;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductClearance;
use App\Services\Integrations\Eracuni\EracuniCompanySalesClient;
use App\Services\Integrations\Eracuni\EracuniCompanySalesException;
use App\Services\Integrations\Stock\StockFeedClient;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class CompanySalesClearanceSync
{
    public const SOURCE = 'eracuni_company_sales';

    public const STATE_KEY = 'catalog_clearance_company_sales_sync';

    private const PRODUCT_CHUNK_SIZE = 50;

    public function state(): array
    {
        return (array) app(SystemSettingsService::class)->get(self::STATE_KEY, []);
    }

    /** Fetches evidence first; a failed or incomplete fetch can never activate discounts. */
    public function run(bool $apply = false, bool $runClearance = false, ?int $actorId = null): array
    {
        if ($runClearance && ! $apply) {
            throw new RuntimeException('Za primjenu rasprodaje prvo treba spremiti potvrđeni API pregled.');
        }

        return Cache::lock('catalog:clearance-company-sales-sync', 7200)->block(1, function () use ($apply, $runClearance, $actorId): array {
            $asOf = today()->toDateString();
            $inactivityDays = app(ClearanceService::class)->inactivityDays();
            $from = today()->subDays($inactivityDays)->toDateString();
            $settings = app(SystemSettingsService::class);
            $stage = 'sales';
            if ($apply) {
                $settings->put(self::STATE_KEY, ['status' => 'running', 'started_at' => now()->toIso8601String()]);
            }
            try {
                $sales = app(EracuniCompanySalesClient::class)->fetch($from, $asOf);
                if (($sales['complete'] ?? false) !== true || ($sales['coverage_from'] ?? null) !== $from
                    || ($sales['as_of'] ?? null) !== $asOf || ! is_array($sales['recent_product_codes'] ?? null)
                    || ! preg_match('/^[a-f0-9]{64}$/D', (string) ($sales['fingerprint'] ?? ''))) {
                    throw new RuntimeException('API pregled prodaje nije potpun. Rasprodaja nije pokrenuta.');
                }
                $stage = 'stock';
                $stock = app(StockFeedClient::class)->fetch('eracuni');
                $warehouse = $this->warehouse($stock);
                unset($stock);
                $recent = [];
                foreach ($sales['recent_product_codes'] as $code) {
                    if (! is_string($code) || trim($code) === '' || mb_strlen($code) > 160) {
                        throw new RuntimeException('API pregled sadrži nevaljanu ERP šifru.');
                    }
                    $recent['code:'.trim($code)] = true;
                }
                unset($sales['recent_product_codes']);
                if (today()->toDateString() !== $asOf) {
                    throw new RuntimeException('Tijekom dohvata promijenio se datum. Ponovite dnevnu provjeru.');
                }
                $fingerprint = hash('sha256', json_encode([$sales['fingerprint'], $warehouse], JSON_THROW_ON_ERROR));
                $stage = 'snapshot';
                $result = Cache::lock('catalog:automatic-clearance', 600)->block(1, function () use ($warehouse, $recent, $fingerprint, $from, $asOf, $apply, $actorId, $inactivityDays): array {
                    app(SystemSettingsService::class)->clearRuntimeCache();
                    if (app(ClearanceService::class)->inactivityDays() !== $inactivityDays) {
                        throw new RuntimeException('Tijekom dohvata promijenjeno je razdoblje rasprodaje. Ponovite provjeru za novo razdoblje.');
                    }

                    return DB::transaction(function () use ($warehouse, $recent, $fingerprint, $from, $asOf, $apply, $actorId): array {
                        $identities = [];
                        Product::query()->select(['id', 'code', 'payload'])->chunkById(self::PRODUCT_CHUNK_SIZE, function ($products) use ($warehouse, &$identities): void {
                            foreach ($products as $product) {
                                foreach ($this->codes($product) as $code) {
                                    $key = 'code:'.$code;
                                    if (! isset($warehouse[$key])) {
                                        continue;
                                    }
                                    // Only warehouse identities matter. Zero marks a
                                    // collision without retaining every matching ID.
                                    if (! isset($identities[$key])) {
                                        $identities[$key] = $product->id;
                                    } elseif ($identities[$key] !== $product->id) {
                                        $identities[$key] = 0;
                                    }
                                }
                            }
                        });
                        $observed = [];
                        $zeroStock = [];
                        $counts = ['observed' => 0, 'recent' => 0, 'unproven' => 0, 'invalidated' => 0];
                        Product::query()->select(['id', 'code', 'payload', 'stock_qty', 'updated_at'])->where('stock_qty', '>', 0)
                            ->chunkById(self::PRODUCT_CHUNK_SIZE, function ($products) use ($warehouse, $identities, $recent, $fingerprint, $from, $asOf, $apply, $actorId, &$observed, &$zeroStock, &$counts): void {
                                foreach ($products as $product) {
                                    $codes = $this->codes($product);
                                    $matches = array_values(array_filter($codes, fn (string $code): bool => isset($warehouse['code:'.$code])));
                                    if (count($matches) !== 1 || ($identities['code:'.$matches[0]] ?? 0) !== $product->id) {
                                        $counts['unproven']++;

                                        continue;
                                    }
                                    if ($warehouse['code:'.$matches[0]] === 0) {
                                        $zeroStock[$product->id] = $this->productFingerprint($product);
                                        $counts['unproven']++;

                                        continue;
                                    }
                                    if (array_any($codes, fn (string $code): bool => isset($recent['code:'.$code]))) {
                                        $counts['recent']++;

                                        continue;
                                    }
                                    if ($apply) {
                                        $locked = Product::query()->whereKey($product->id)->lockForUpdate()->first();
                                        if (! $locked || (int) $locked->stock_qty <= 0 || $this->codes($locked) !== $codes) {
                                            $counts['unproven']++;

                                            continue;
                                        }
                                        ProductClearance::query()->updateOrCreate(['product_id' => $product->id], [
                                            'source' => self::SOURCE, 'source_fingerprint' => $fingerprint,
                                            'coverage_from' => $from, 'snapshot_date' => $asOf, 'last_outgoing_at' => null,
                                            'confirmed_company_scope' => true, 'imported_by' => $actorId,
                                        ]);
                                    }
                                    $counts['observed']++;
                                    $observed[] = $product->id;
                                }
                            });
                        if ($apply) {
                            $counts['invalidated'] = ProductClearance::query()->where('status', 'observed')
                                ->whereIn('source', [self::SOURCE, 'eracuni_company_export'])->where('confirmed_company_scope', true)
                                ->whereIntegerNotInRaw('product_id', $observed)->update(['confirmed_company_scope' => false]);
                        }

                        return $counts + ['zero_stock_product_proofs' => $zeroStock];
                    });
                });
                $result += ['status' => 'completed', 'source' => self::SOURCE, 'coverage_from' => $from, 'as_of' => $asOf,
                    'fingerprint' => $fingerprint, 'inactivity_days' => $inactivityDays, 'document_count' => (int) ($sales['document_count'] ?? 0),
                    'request_count' => (int) ($sales['request_count'] ?? 0), 'sources' => $sales['sources'] ?? [],
                    'unlinked_line_count' => (int) ($sales['unlinked_line_count'] ?? 0),
                    'completed_at' => now()->toIso8601String(), 'applied' => $apply];
                if ($apply) {
                    $settings->put(self::STATE_KEY, $result);
                }
                if ($runClearance) {
                    $stage = 'clearance';
                    $result['clearance'] = app(ClearanceService::class)->run(true, $fingerprint);
                }

                return $result;
            } catch (Throwable $exception) {
                $reason = $exception instanceof EracuniCompanySalesException ? $exception->getMessage() : null;
                $message = $stage === 'clearance' ? 'Primjena rasprodaje nije dovršena. Provjerite stanje pojedinih artikala.' : 'Potpuna provjera prodaje i vlastite zalihe nije uspjela. Nove rasprodaje nisu pokrenute.';
                if ($apply) {
                    Cache::lock('catalog:automatic-clearance', 600)->block(1, function (): void {
                        ProductClearance::query()->where('source', self::SOURCE)->where('status', 'observed')
                            ->update(['confirmed_company_scope' => false]);
                    });
                    $settings->put(self::STATE_KEY, ['status' => 'failed', 'stage' => $stage, 'completed_at' => now()->toIso8601String(),
                        'message' => $message, 'reason' => $reason,
                        'context' => $exception instanceof EracuniCompanySalesException ? $exception->context : []]);
                }

                throw new RuntimeException($message.($reason !== null ? ' '.$reason : ''));
            }
        });
    }

    private function warehouse(array $stock): array
    {
        if (($stock['complete'] ?? false) !== true || ! empty($stock['invalid_count']) || empty($stock['rows'])) {
            throw new RuntimeException('Nije potvrđen potpuni pregled vlastite ERP zalihe.');
        }
        $result = [];
        foreach ($stock['rows'] as $row) {
            $code = $row['identifier'] ?? null;
            $quantity = $row['quantity'] ?? null;
            if (! is_string($code) || trim($code) === '' || mb_strlen($code) > 160 || ! is_int($quantity) || $quantity < 0) {
                throw new RuntimeException('ERP pregled zalihe nije valjan.');
            }
            $key = 'code:'.trim($code);
            if (isset($result[$key]) && $result[$key] !== $quantity) {
                throw new RuntimeException('ERP pregled zalihe sadrži proturječne šifre.');
            }
            $result[$key] = $quantity;
        }
        ksort($result);

        return $result;
    }

    public function productFingerprint(Product $product): string
    {
        return hash('sha256', json_encode([$this->codes($product), (int) $product->stock_qty, $product->getRawOriginal('updated_at')], JSON_THROW_ON_ERROR));
    }

    private function codes(Product $product): array
    {
        $payload = $product->payload;

        return array_values(array_unique(array_filter(array_map(static fn ($value): string => is_string($value) || is_int($value) ? trim((string) $value) : '', [
            data_get($payload, 'eracuni.productCode'), data_get($payload, 'opencart.model'),
            data_get($payload, 'ideus_csv.model'), $product->code,
        ]), static fn (string $code): bool => $code !== '' && mb_strlen($code) <= 160)));
    }
}
