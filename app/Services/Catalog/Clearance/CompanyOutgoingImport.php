<?php

namespace App\Services\Catalog\Clearance;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductClearance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class CompanyOutgoingImport
{
    public function run(string $file, bool $apply = false, bool $confirmedCompanyScope = false, ?int $actorId = null): array
    {
        if ($apply && ! $confirmedCompanyScope) {
            throw new RuntimeException('Potvrdite da e-Računi izvještaj obuhvaća ukupne izlaze/prodaju tvrtke i sva relevantna skladišta.');
        }
        if (! is_file($file) || filesize($file) > 20 * 1024 * 1024 || ! ($stream = fopen($file, 'r'))) {
            throw new RuntimeException('Odaberite CSV izvještaj do 20 MB.');
        }
        try {
            if (fgetcsv($stream, null, ',', '"', '') !== ['product_code', 'last_outgoing_at', 'coverage_from', 'as_of']) {
                throw new RuntimeException('CSV zaglavlje mora biti product_code,last_outgoing_at,coverage_from,as_of; datumi GGGG-MM-DD.');
            }
            $rows = [];
            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($row) !== 4 || trim($row[0]) === '' || mb_strlen(trim($row[0])) > 120 || isset($rows['code:'.trim($row[0])])) {
                    throw new RuntimeException('Izvještaj sadrži nevaljanu ili ponovljenu šifru. Ništa nije spremljeno.');
                }
                $outgoing = trim($row[1]) !== '' ? $this->date($row[1]) : null;
                $from = $this->date($row[2]);
                $asOf = $this->date($row[3]);
                if ($asOf->gt(CarbonImmutable::today()) || $from->gt($asOf) || ($outgoing && $outgoing->gt($asOf))) {
                    throw new RuntimeException('Izvještaj sadrži proturječne ili buduće datume. Ništa nije spremljeno.');
                }
                $rows['code:'.trim($row[0])] = ['last_outgoing_at' => $outgoing?->toDateString(), 'coverage_from' => $from->toDateString(), 'snapshot_date' => $asOf->toDateString()];
            }
        } finally {
            fclose($stream);
        }
        if ($rows === []) {
            throw new RuntimeException('Izvještaj nema stavaka.');
        }
        $map = [];
        Product::query()->select(['id', 'code', 'payload'])->chunkById(500, function ($products) use (&$map): void {
            foreach ($products as $product) {
                foreach ([data_get($product->payload, 'eracuni.productCode'), data_get($product->payload, 'opencart.model'), data_get($product->payload, 'ideus_csv.model'), $product->code] as $code) {
                    if (is_scalar($code) && trim((string) $code) !== '') {
                        $map['code:'.trim((string) $code)][$product->id] = $product->id;
                    }
                }
            }
        });
        $resolved = [];
        foreach ($rows as $key => $row) {
            if (count($map[$key] ?? []) !== 1) {
                throw new RuntimeException('Svaka ERP šifra mora jednoznačno odgovarati postojećem artiklu. Nepovezane ili dvosmislene stavke prvo treba razriješiti.');
            }
            $id = array_values($map[$key])[0];
            if (isset($resolved[$id])) {
                throw new RuntimeException('Više šifri odnosi se na isti artikl. Ništa nije spremljeno.');
            }
            $resolved[$id] = $row;
        }
        $fingerprint = hash_file('sha256', $file);

        return Cache::lock('catalog:automatic-clearance', 600)->block(1, function () use ($resolved, $fingerprint, $apply, $confirmedCompanyScope, $actorId): array {
            return DB::transaction(function () use ($resolved, $fingerprint, $apply, $confirmedCompanyScope, $actorId): array {
                $fresh = 0;
                foreach ($resolved as $id => $row) {
                    Product::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                    $existing = ProductClearance::query()->where('product_id', $id)->lockForUpdate()->first();
                    if ($existing && $existing->snapshot_date->toDateString() > $row['snapshot_date']) {
                        throw new RuntimeException('Izvještaj je stariji od već spremljenog. Ništa nije spremljeno.');
                    }
                    if ($row['snapshot_date'] === today()->toDateString()) {
                        $fresh++;
                    }
                    if ($apply) {
                        ProductClearance::query()->updateOrCreate(['product_id' => $id], $row + ['source' => 'eracuni_company_export', 'source_fingerprint' => $fingerprint, 'confirmed_company_scope' => $confirmedCompanyScope, 'imported_by' => $actorId]);
                    }
                }

                return ['products' => count($resolved), 'fresh' => $fresh, 'stale' => count($resolved) - $fresh, 'applied' => $apply, 'fingerprint' => $fingerprint];
            });
        });
    }

    private function date(string $value): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
            if ($date && $date->format('Y-m-d') === $value) {
                return $date;
            }
        } catch (Throwable) {
        }

        throw new RuntimeException('Datumi moraju biti stvarni datumi u obliku GGGG-MM-DD. Ništa nije spremljeno.');
    }
}
