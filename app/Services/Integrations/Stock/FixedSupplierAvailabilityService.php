<?php

namespace App\Services\Integrations\Stock;

use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Support\Integrations\Stock\StockSyncRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class FixedSupplierAvailabilityService
{
    public const QUANTITY = 100000;

    public function run(array $manufacturerIds, bool $apply = false, ?int $actorId = null): array
    {
        Validator::make(['ids' => $manufacturerIds], ['ids' => 'required|array|min:1|max:100', 'ids.*' => 'required|integer|distinct|exists:catalog_manufacturers,id'])->validate();
        $manufacturers = Manufacturer::query()->with('translations')->whereIn('id', $manufacturerIds)->get();
        foreach ($manufacturers as $manufacturer) {
            if ($this->synchronizedManufacturer($manufacturer)) {
                throw new RuntimeException('Odabrani dobavljač ima XML integraciju. Fiksna raspoloživost namijenjena je dobavljačima bez XML-a.');
            }
        }

        return Cache::lock('herrera-stock-sync:supplier_stock_qty', 600)->block(1, function () use ($manufacturerIds, $manufacturers, $apply, $actorId): array {
            return DB::transaction(function () use ($manufacturerIds, $manufacturers, $apply, $actorId): array {
                $changed = $preserved = 0;
                $byId = $manufacturers->keyBy('id');
                Product::query()->whereIn('manufacturer_id', $manufacturerIds)->lockForUpdate()->chunkById(300, function ($products) use (&$changed, &$preserved, $byId, $apply, $actorId): void {
                    foreach ($products as $product) {
                        $source = data_get($product->payload, 'supplier_catalog.supplier') ?: data_get($product->payload, 'catalog_origin');
                        if (is_string($source) && isset(StockSyncRegistry::all()[$source])) {
                            $preserved++;

                            continue;
                        }
                        $changed++;
                        if ($apply) {
                            $this->set($product, self::QUANTITY, $byId[$product->manufacturer_id]->code, $actorId);
                        }
                    }
                });
                if ($apply) {
                    foreach ($manufacturers as $manufacturer) {
                        $payload = $manufacturer->payload ?? [];
                        $payload['supplier_availability'] = ['mode' => 'fixed', 'quantity' => self::QUANTITY, 'updated_by' => $actorId];
                        $manufacturer->update(['payload' => $payload]);
                    }
                }

                return ['products' => $changed, 'synchronized_preserved' => $preserved, 'applied' => $apply];
            });
        });
    }

    public function applyToNewProduct(Product $product): void
    {
        $manufacturer = $product->manufacturer_id ? Manufacturer::query()->find($product->manufacturer_id) : null;
        if ($manufacturer && data_get($manufacturer->payload, 'supplier_availability.mode') === 'fixed'
            && ! isset(StockSyncRegistry::all()[data_get($product->payload, 'supplier_catalog.supplier') ?? data_get($product->payload, 'catalog_origin') ?? ''])) {
            $this->set($product, (int) data_get($manufacturer->payload, 'supplier_availability.quantity', self::QUANTITY), $manufacturer->code, $product->updated_by);
        }
    }

    /** Manual CSV changes only quantities of products explicitly assigned fixed availability. */
    public function importCsv(string $file, bool $apply = false): array
    {
        if (! is_file($file) || filesize($file) > 10 * 1024 * 1024 || ! ($stream = fopen($file, 'r'))) {
            throw new RuntimeException('Odaberite lokalni CSV do 10 MB.');
        }
        try {
            if (fgetcsv($stream, null, ',', '"', '') !== ['sku', 'quantity']) {
                throw new RuntimeException('CSV zaglavlje mora biti sku,quantity.');
            }
            $rows = [];
            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($row) !== 2 || trim($row[0]) === '' || isset($rows[trim($row[0])]) || ! ctype_digit($row[1]) || (float) $row[1] > 2147483647) {
                    throw new RuntimeException('Nevaljana ili ponovljena stavka. Ništa nije spremljeno.');
                }
                $rows[trim($row[0])] = (int) $row[1];
            }
        } finally {
            fclose($stream);
        }
        if ($rows === []) {
            throw new RuntimeException('CSV nema stavaka.');
        }

        return Cache::lock('herrera-stock-sync:supplier_stock_qty', 600)->block(1, function () use ($rows, $apply): array {
            return DB::transaction(function () use ($rows, $apply): array {
                $products = Product::query()->whereIn('sku', array_keys($rows))->lockForUpdate()->get();
                if ($products->count() !== count($rows) || $products->pluck('sku')->unique()->count() !== $products->count()
                    || $products->contains(fn ($product) => data_get($product->payload, 'supplier_availability.mode') !== 'fixed')) {
                    throw new RuntimeException('Sve šifre moraju jednoznačno pripadati artiklima s odabranom fiksnom raspoloživosti. Ništa nije spremljeno.');
                }
                if ($apply) {
                    foreach ($products as $product) {
                        $this->set($product, $rows[$product->sku], data_get($product->payload, 'supplier_availability.supplier'), null, 'manual_csv');
                    }
                }

                return ['products' => $products->count(), 'applied' => $apply];
            });
        });
    }

    private function set(Product $product, int $quantity, string $supplier, ?int $actorId, string $source = 'fixed_policy'): void
    {
        $payload = $product->payload ?? [];
        $payload['supplier_availability'] = ['mode' => 'fixed', 'quantity' => $quantity, 'supplier' => $supplier, 'source' => $source, 'updated_at' => now()->toIso8601String(), 'updated_by' => $actorId];
        $product->updateQuietly(['supplier_stock_qty' => $quantity, 'payload' => $payload]);
    }

    private function synchronizedManufacturer(Manufacturer $manufacturer): bool
    {
        $normalize = static fn (string $name): string => preg_replace('/[^a-z0-9]/', '', strtolower($name));
        $names = [$manufacturer->code, ...$manufacturer->translations->pluck('name')->all()];
        // Imported manufacturer labels also contain the supplier's combined
        // legacy names ("Master - VIP", "Videx / Allegro"). These aliases
        // identify the same XML integration, never separate manual suppliers.
        $synchronized = ['videx', 'allegro', 'videxallegro', 'allegrovidex', 'dpm', 'braytron', 'vipelectro', 'master', 'vip',
            'mastervip', 'vipmaster', 'vipelectromaster', 'mastervipelectro', 'vipelectrovip', 'vipelectromastervip', 'mastervipvipelectro',
            'esperanza', 'vayox', 'enovalite', 'brock'];

        return collect($names)->contains(fn ($name) => in_array($normalize($name), $synchronized, true));
    }
}
