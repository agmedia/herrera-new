<?php

namespace App\Services\Integrations\Stock;

use App\Models\Catalog\Product\Product;
use App\Models\Integrations\Stock\StockSyncRun;
use App\Support\Integrations\Stock\StockSyncRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockSyncService
{
    public function __construct(private readonly StockFeedClient $client, private readonly StockSyncSettingsService $settings) {}

    public function run(string $supplier, string $trigger = 'manual', ?int $actorId = null): StockSyncRun
    {
        $definition = StockSyncRegistry::get($supplier);
        if (! in_array($trigger, ['manual', 'cron'], true)) {
            throw new RuntimeException('Nepoznat način pokretanja.');
        }
        if ($trigger === 'cron' && ! $this->settings->enabled($supplier)) {
            throw new RuntimeException('Automatsko ažuriranje je isključeno.');
        }
        if (! $this->settings->configured($supplier)) {
            throw new RuntimeException('Nedostaju pristupne postavke izvora.');
        }

        // Supplier feeds write the same column: serialize them as well as
        // simultaneous manual/cron requests. Own stock has a separate lock.
        $lock = Cache::lock('herrera-stock-sync:'.$definition['target'], 600);
        if (! $lock->get()) {
            throw new RuntimeException('Ažuriranje zaliha je već u tijeku. Pokušajte ponovno nakon završetka.');
        }
        $run = null;
        try {
            $sameTarget = array_keys(array_filter(StockSyncRegistry::all(), fn (array $job): bool => $job['target'] === $definition['target']));
            StockSyncRun::query()->whereIn('supplier', $sameTarget)->where('status', 'running')
                ->where('started_at', '<=', now()->subMinutes(10))->update([
                    'status' => 'failed', 'completed_at' => now(),
                    'error_message' => 'Prethodno izvršavanje je prekinuto ili je isteklo vrijeme obrade. Pokrenite ažuriranje ponovno.',
                ]);
            $run = StockSyncRun::query()->create([
                'supplier' => $supplier, 'trigger' => $trigger, 'actor_id' => $actorId,
                'status' => 'running', 'started_at' => now(),
            ]);
            $feed = $this->client->fetch($supplier);
            $run->update([
                'fetched_count' => count($feed['rows'] ?? []) + (int) ($feed['invalid_count'] ?? 0) + (int) ($feed['skipped_count'] ?? 0) + (int) ($feed['duplicate_count'] ?? 0),
                'invalid_count' => (int) ($feed['invalid_count'] ?? 0),
                'summary' => ['skipped_count' => (int) ($feed['skipped_count'] ?? 0), 'clamped_count' => (int) ($feed['clamped_count'] ?? 0), 'duplicate_count' => (int) ($feed['duplicate_count'] ?? 0), 'target' => $definition['target']],
            ]);
            $quantities = $this->validateFeed($feed);
            $this->apply($run, $definition, $quantities);
        } catch (\Throwable) {
            if (! $run) {
                throw new RuntimeException('Izvršavanje nije moguće zabilježiti. Provjerite bazu podataka.');
            }
            // Never persist upstream exceptions, URLs, headers or credentials.
            $run->update([
                'status' => 'failed', 'completed_at' => now(),
                'error_message' => 'Ažuriranje nije provedeno. Provjerite dostupnost izvora, pristupne postavke i valjanost podataka. Zalihe su sačuvane.',
            ]);
        } finally {
            $lock->release();
        }

        return $run->fresh();
    }

    private function validateFeed(array $feed): array
    {
        if (! ($feed['complete'] ?? false) || empty($feed['rows']) || ! empty($feed['invalid_count'])) {
            throw new RuntimeException('Izvor nije potpun ili nije valjan.');
        }
        $quantities = [];
        foreach ($feed['rows'] as $row) {
            $identifier = trim((string) ($row['identifier'] ?? ''));
            $quantity = $row['quantity'] ?? null;
            if ($identifier === '' || mb_strlen($identifier) > 160 || ! is_int($quantity) || $quantity < 0 || $quantity > 2147483647) {
                throw new RuntimeException('Neispravna oznaka ili količina.');
            }
            // Prefix to preserve numeric identifiers (including leading zeros).
            $key = 'id:'.$identifier;
            if (array_key_exists($key, $quantities) && $quantities[$key] !== $quantity) {
                throw new RuntimeException('Izvor sadrži proturječne količine za istu oznaku.');
            }
            $quantities[$key] = $quantity;
        }

        return $quantities;
    }

    private function apply(StockSyncRun $run, array $definition, array $quantities): void
    {
        DB::transaction(function () use ($run, $definition, $quantities): void {
            $counts = ['matched_count' => 0, 'updated_count' => 0, 'unchanged_count' => 0, 'unmatched_count' => 0];
            $matched = [];
            $resetCount = 0;
            $target = $definition['target'];
            $items = [];
            $flush = function () use (&$items): void {
                if ($items !== []) {
                    DB::table('stock_sync_items')->insert($items);
                    $items = [];
                }
            };
            $addItem = function (array $item) use (&$items, $flush, $run): void {
                $items[] = $item + ['run_id' => $run->id, 'message' => null, 'created_at' => now(), 'updated_at' => now()];
                if (count($items) >= 300) {
                    $flush();
                }
            };

            Product::query()->select(['id', 'code', 'sku', 'barcode', 'payload', 'stock_qty', 'supplier_stock_qty'])
                ->lockForUpdate()->chunkById(500, function ($products) use ($definition, $target, $quantities, &$matched, &$counts, &$resetCount, $addItem): void {
                    foreach ($products as $product) {
                        $legacy = $product->payload['opencart'] ?? [];
                        $identifier = $this->identifier($product, $definition['match'], $legacy);
                        $key = 'id:'.$identifier;
                        $found = $identifier !== '' && array_key_exists($key, $quantities);
                        // WarehouseGetArticleStockQuantity is a complete own-stock
                        // snapshot. Reset missing imported articles only; native
                        // products never fall into the legacy global zeroing.
                        $reset = $definition['match'] === 'model' && ! $found && $identifier !== ''
                            && (! empty($legacy['product_id']) || ! empty($product->payload['eracuni']['productCode']) || ! empty($product->payload['ideus_csv']['model']));
                        if (! $found && ! $reset) {
                            continue;
                        }
                        if ($found) {
                            $matched[$key] = true;
                            $counts['matched_count']++;
                        } else {
                            $resetCount++;
                        }
                        $quantity = $found ? $quantities[$key] : 0;
                        $old = (int) $product->{$target};
                        $status = $old === $quantity ? 'unchanged' : 'updated';
                        $counts[$status === 'updated' ? 'updated_count' : 'unchanged_count']++;
                        if ($old !== $quantity) {
                            DB::table('products')->where('id', $product->id)->update([$target => $quantity, 'updated_at' => now()]);
                        }
                        $addItem([
                            'product_id' => $product->id, 'identifier' => $identifier, 'status' => $status,
                            'old_quantity' => $old, 'new_quantity' => $quantity,
                            'message' => $reset ? 'Artikl nije u potpunom izvještaju vlastitog skladišta; vlastita zaliha postavljena je na 0.' : null,
                        ]);
                    }
                });
            // A valid-looking but unrelated feed must never zero the warehouse.
            if ($matched === [] && $definition['match'] === 'model') {
                throw new RuntimeException('Nijedan artikl nije povezan.');
            }
            foreach ($quantities as $key => $quantity) {
                if (! isset($matched[$key])) {
                    $counts['unmatched_count']++;
                    $addItem([
                        'product_id' => null, 'identifier' => substr($key, 3), 'status' => 'unmatched',
                        'old_quantity' => null, 'new_quantity' => $quantity,
                    ]);
                }
            }
            $flush();
            $run->update($counts + [
                'status' => 'completed', 'completed_at' => now(),
                'summary' => ($run->summary ?? []) + ['unique_identifiers' => count($quantities), 'reset_count' => $resetCount, 'no_matches' => $matched === []],
            ]);
        });
    }

    private function identifier(Product $product, string $match, array $legacy): string
    {
        if ($match === 'model' && ! empty($product->payload['eracuni']['productCode'])) {
            return trim((string) $product->payload['eracuni']['productCode']);
        }
        if ($match === 'model' && ! empty($product->payload['ideus_csv']['model'])) {
            return trim((string) $product->payload['ideus_csv']['model']);
        }
        // Imported SKUs/barcodes can be adjusted for uniqueness. Use the
        // original identity for imported rows, exactly as the former cron did.
        if (array_key_exists($match, $legacy)) {
            return trim((string) $legacy[$match]);
        }

        return trim((string) match ($match) {
            'ean' => $product->barcode,
            'sku' => $product->sku,
            'model' => $product->code,
            default => '',
        });
    }
}
