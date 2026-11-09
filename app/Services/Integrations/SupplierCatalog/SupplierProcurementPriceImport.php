<?php

namespace App\Services\Integrations\SupplierCatalog;

use App\Models\Integrations\SupplierCatalog\SupplierCatalogItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SupplierProcurementPriceImport
{
    /** Strict local CSV enrichment; a preview never writes products or catalogue rows. */
    public function run(string $supplier, string $file, bool $apply = false): array
    {
        if (! isset(SupplierCatalogFeedClient::SUPPLIERS[$supplier]) || ! is_file($file) || filesize($file) > 10 * 1024 * 1024) {
            throw new RuntimeException('Odaberite poznatog dobavljača i lokalni CSV do 10 MB.');
        }
        $stream = fopen($file, 'r');
        if (! $stream) {
            throw new RuntimeException('CSV nije moguće otvoriti.');
        }
        try {
            $header = fgetcsv($stream, null, ',', '"', '');
            if ($header !== ['sku', 'source_price', 'currency']) {
                throw new RuntimeException('CSV zaglavlje mora biti: sku,source_price,currency (zarez, decimalna točka).');
            }
            $rows = [];
            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                [$sku, $price, $currency] = array_pad($row, 3, '');
                $sku = trim($sku);
                $currency = strtoupper(trim($currency));
                if (count($row) !== 3 || $sku === '' || isset($rows[$sku]) || ! preg_match('/^\d+(?:\.\d{1,4})?$/D', $price)
                    || (float) $price <= 0 || (float) $price > 99999999 || ! preg_match('/^[A-Z]{3}$/D', $currency)) {
                    throw new RuntimeException('CSV sadrži nevaljan ili ponovljen zapis. Ništa nije spremljeno.');
                }
                $rows[$sku] = ['manual_source_price' => $price, 'manual_currency' => $currency];
            }
        } finally {
            fclose($stream);
        }
        if ($rows === []) {
            throw new RuntimeException('CSV nema stavaka.');
        }

        return Cache::lock('supplier-catalog:'.$supplier, 180)->block(1, function () use ($supplier, $rows, $apply): array {
            return DB::transaction(function () use ($supplier, $rows, $apply): array {
                $items = SupplierCatalogItem::query()->where('supplier', $supplier)->whereIn('sku', array_keys($rows))->lockForUpdate()->get();
                if ($items->count() !== count($rows) || $items->pluck('sku')->unique()->count() !== $items->count()) {
                    throw new RuntimeException('Svaka CSV šifra mora jednoznačno postojati u pregledu ovog dobavljača. Ništa nije spremljeno.');
                }
                $updated = $preserved = 0;
                foreach ($items as $item) {
                    if ($item->source_price !== null) {
                        $preserved++;

                        continue;
                    }
                    if ($apply) {
                        $item->update($rows[$item->sku]);
                    }
                    $updated++;
                }

                return ['updated' => $updated, 'preserved_feed_prices' => $preserved, 'applied' => $apply];
            });
        });
    }
}
