<?php

namespace App\Services\Analytics;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Services\Integrations\Eracuni\EracuniCompanySalesClient;
use App\Services\Integrations\Eracuni\EracuniCompanySalesException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SalesOpportunitySync
{
    private array $buffer = [];

    private array $documentTaxes = [];

    private array $taxKeys = [];

    private array $documentOnlyLines = [];

    public function run(?callable $progress = null): array
    {
        return Cache::lock('sales:opportunities-sync', 7200)->block(1, function () use ($progress): array {
            $from = today()->subDays(365)->toDateString();
            $asOf = today()->toDateString();
            $rankFrom = today()->subDays(90)->toDateString();
            $snapshotId = DB::table('sales_opportunity_snapshots')->insertGetId([
                'status' => 'running', 'coverage_from' => $from, 'as_of' => $asOf,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->buffer = [];
            $this->documentTaxes = $this->taxKeys = $this->documentOnlyLines = [];
            try {
                $identities = $this->productIdentities();
                $unmapped = $anonymous = $processedDocuments = 0;
                $result = app(EracuniCompanySalesClient::class)->eachFinancialDocument($from, $asOf, function (array $document, string $method) use ($snapshotId, $identities, $rankFrom, $progress, &$processedDocuments, &$unmapped, &$anonymous): void {
                    $processedDocuments++;
                    if ($progress && $processedDocuments % 250 === 0 && in_array($method, EracuniCompanySalesClient::FINANCIAL_SOURCES, true)) {
                        $progress(['method' => $method, 'document_count' => $processedDocuments]);
                    }
                    if (($document['status'] ?? null) === 'Draft') {
                        return;
                    }
                    if (($document['status'] ?? null) !== 'IssuedInvoice') {
                        throw new RuntimeException('Nepoznat ERP status financijskog dokumenta; analiza nije potpuna.');
                    }
                    if (! isset($document['Items']) || ! is_array($document['Items']) || ! array_is_list($document['Items'])) {
                        throw new RuntimeException('ERP nije vratio potpune prodajne stavke za analizu.');
                    }
                    $buyer = $this->buyerIdentity($document);
                    $this->registerBuyerAliases($buyer);
                    foreach ($document['Items'] as $lineIndex => $line) {
                        try {
                            if (! is_array($line) || ! $this->numeric($line['quantity'] ?? null)) {
                                throw new RuntimeException('ERP je vratio nevaljanu prodajnu količinu.');
                            }
                            $quantity = (float) $line['quantity'];
                            if (abs($quantity) > 1000000000000) {
                                throw new RuntimeException('ERP prodajna količina prelazi dopušteni raspon.');
                            }
                            if (isset($line['productCode']) && ! is_string($line['productCode']) && ! is_int($line['productCode'])) {
                                throw new RuntimeException('ERP je vratio nevaljanu šifru prodajnog artikla.');
                            }
                            $code = trim((string) ($line['productCode'] ?? ''));
                            $productId = $identities['code:'.$code] ?? 0;
                            if (! $productId) {
                                $unmapped++;

                                continue;
                            }
                            if ($quantity === 0.0) {
                                continue;
                            }
                            $isCancellation = trim((string) ($document['cancelledDocumentRef'] ?? '')) !== '';
                            if ($method === 'CreditNoteList') {
                                // CreditNote is a financial reversal whether its API
                                // represents credit quantities as positive or negative.
                                // An explicitly linked cancellation reverses the credit.
                                if (! $this->numeric($line['amount'] ?? null) || ! $this->numeric($document['documentAmount'] ?? null)) {
                                    throw new RuntimeException('Odobrenje nema potvrđene financijske podatke za analizu.');
                                }
                                $quantity = abs($quantity) * ($isCancellation ? 1 : -1);
                            } elseif ($isCancellation) {
                                if (! $this->numeric($line['amount'] ?? null)
                                    || ($quantity < 0 && (float) $line['amount'] > 0)
                                    || ($quantity > 0 && (float) $line['amount'] === 0.0)) {
                                    throw new RuntimeException('Smjer storno količine nije jednoznačan. ERP pregled nije objavljen.');
                                }
                                if ($quantity > 0 && (float) $line['amount'] < 0) {
                                    $quantity = -$quantity;
                                }
                            }
                            if (! $buyer['known_buyer']) {
                                $anonymous++;
                            }
                            if ($buyer['tax_key'] === null && $buyer['document_key'] !== null) {
                                $this->documentOnlyLines[$buyer['document_key']] = ($this->documentOnlyLines[$buyer['document_key']] ?? 0) + 1;
                            }
                            $key = $productId.':'.$buyer['buyer_key'];
                            $this->buffer[$key] ??= $buyer + ['snapshot_id' => $snapshotId, 'product_id' => $productId, 'quantity_90' => 0.0, 'quantity_365' => 0.0];
                            $this->buffer[$key]['quantity_365'] += $quantity;
                            if ($document['date'] >= $rankFrom) {
                                $this->buffer[$key]['quantity_90'] += $quantity;
                            }
                            if (count($this->buffer) >= 250) {
                                $this->flush($snapshotId);
                            }
                        } catch (RuntimeException $exception) {
                            throw new EracuniCompanySalesException($exception->getMessage(), ['method' => $method,
                                'document_id' => $document['documentID'], 'line_position' => $lineIndex + 1]);
                        }
                    }
                });
                $this->flush($snapshotId);
                $quality = $this->mergeBuyerAliases($snapshotId);
                $anonymous += $quality['ambiguous_lines'];
                if (today()->toDateString() !== $asOf || ($result['complete'] ?? false) !== true) {
                    throw new RuntimeException('Dnevni ERP pregled nije potpun. Ponovite provjeru.');
                }
                $state = ['status' => 'completed', 'document_count' => $result['document_count'], 'request_count' => $result['request_count'],
                    'unmapped_lines' => $unmapped, 'anonymous_lines' => $anonymous,
                    'message' => $quality['ambiguous_ids'] > 0
                        ? 'ERP ID-ova povezanih s više poreznih identiteta: '.$quality['ambiguous_ids'].'. Različiti OIB-i nisu spojeni; nepovezane stavke zadržane su u neto količinama, bez povećanja broja poznatih kupaca.' : null,
                    'completed_at' => now(), 'updated_at' => now()];
                DB::table('sales_opportunity_snapshots')->where('id', $snapshotId)->update($state);
                $keep = DB::table('sales_opportunity_snapshots')->where('status', 'completed')->orderByDesc('id')->limit(2)->pluck('id');
                DB::table('sales_opportunity_snapshots')->whereNotIn('id', $keep)->where('id', '<', $snapshotId)->delete();

                return $state + ['id' => $snapshotId, 'ambiguous_buyer_ids' => $quality['ambiguous_ids']];
            } catch (Throwable $exception) {
                $this->buffer = [];
                $this->documentTaxes = $this->taxKeys = $this->documentOnlyLines = [];
                DB::table('sales_opportunity_purchases')->where('snapshot_id', $snapshotId)->delete();
                DB::table('sales_opportunity_buyer_aliases')->where('snapshot_id', $snapshotId)->delete();
                DB::table('sales_opportunity_snapshots')->where('id', $snapshotId)->update([
                    'status' => 'failed', 'message' => 'Potpuni ERP pregled prodaje nije dovršen. Posljednji uspješan pregled ostaje sačuvan.', 'updated_at' => now(),
                ]);
                throw $exception;
            }
        });
    }

    public function buyerIdentity(array $document): array
    {
        foreach (['buyerTaxNumber', 'buyerDocumentID', 'buyerCode'] as $field) {
            if (isset($document[$field]) && (! is_string($document[$field]) && ! is_int($document[$field]) || mb_strlen((string) $document[$field]) > 191)) {
                throw new RuntimeException('ERP identitet kupca nije valjan za analizu.');
            }
        }
        $tax = $this->taxNumber($document['buyerTaxNumber'] ?? '', $document['buyerCountry'] ?? null);
        $documentId = $this->identifier($document['buyerDocumentID'] ?? '');
        $code = $this->identifier($document['buyerCode'] ?? '');
        $documentId = preg_match('/^0+$/D', $documentId) ? '' : $documentId;
        $code = preg_match('/^0+$/D', $code) ? '' : $code;
        $taxKey = $tax !== '' ? $this->hash('tax:'.$tax) : null;
        $documentKey = $documentId !== '' ? $this->hash('document:'.$documentId) : null;
        $codeKey = $code !== '' ? $this->hash('code:'.$code) : null;

        return ['buyer_key' => $taxKey ?? $documentKey ?? $codeKey ?? $this->hash('anonymous'),
            'tax_key' => $taxKey, 'document_key' => $documentKey, 'code_key' => $codeKey,
            'known_buyer' => $taxKey !== null || $documentKey !== null || $codeKey !== null];
    }

    public function taxNumber(mixed $value, mixed $country = null): string
    {
        $value = strtoupper(preg_replace('/\s+/', '', $this->identifier($value)));
        if ($value === '') {
            return '';
        }
        $country = strtoupper($this->identifier($country));
        $country = preg_match('/^[A-Z]{2}$/D', $country) ? $country : 'HR';
        if (preg_match('/^([A-Z]{2})(.+)$/D', $value, $parts)) {
            $country = $parts[1];
            $value = $parts[2];
        }
        if (preg_match('/^0+$/D', $value)) {
            return '';
        }

        return $country.':'.$value;
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function registerBuyerAliases(array $buyer): void
    {
        // Keep tax-primary rows separate until every document has been read:
        // an ERP ID may be reused with a different OIB on a later credit note.
        $tax = $buyer['tax_key'];
        $document = $buyer['document_key'];
        if ($tax !== null) {
            $this->taxKeys[$tax] = true;
        }
        if ($document !== null) {
            if (! array_key_exists($document, $this->documentTaxes)) {
                $this->documentTaxes[$document] = $tax;
            } elseif ($tax !== null && $this->documentTaxes[$document] !== false) {
                $previous = $this->documentTaxes[$document];
                $this->documentTaxes[$document] = $previous === null || $previous === $tax ? $tax : false;
            }
        }
        if (count($this->taxKeys) + count($this->documentTaxes) > 50000) {
            throw new RuntimeException('ERP pregled prelazi dopušteni broj identiteta kupaca. Nije objavljen nepotpun pregled.');
        }
    }

    private function mergeBuyerAliases(int $snapshotId): array
    {
        $ambiguous = array_keys(array_filter($this->documentTaxes, fn ($tax) => $tax === false));
        $anonymousLines = 0;
        foreach ($ambiguous as $document) {
            $anonymousLines += $this->documentOnlyLines[$document] ?? 0;
        }
        foreach (array_chunk($ambiguous, 250) as $documents) {
            DB::table('sales_opportunity_purchases')->where('snapshot_id', $snapshotId)->whereIn('buyer_key', $documents)
                ->whereNull('tax_key')->update(['known_buyer' => false, 'document_key' => null]);
            DB::table('sales_opportunity_purchases')->where('snapshot_id', $snapshotId)->whereIn('document_key', $documents)
                ->update(['document_key' => null]);
        }
        DB::table('sales_opportunity_purchases')->where('snapshot_id', $snapshotId)->orderBy('id')->chunkById(200, function ($rows) use ($snapshotId): void {
            foreach ($rows as $row) {
                $root = $this->documentTaxes[$row->buyer_key] ?? null;
                if ($row->tax_key !== null || ! is_string($root)) {
                    continue;
                }
                DB::transaction(function () use ($row, $root, $snapshotId): void {
                    $current = DB::table('sales_opportunity_purchases')->where('id', $row->id)->first();
                    if (! $current) {
                        return;
                    }
                    $target = DB::table('sales_opportunity_purchases')->where('snapshot_id', $snapshotId)->where('product_id', $current->product_id)->where('buyer_key', $root)->first();
                    if ($target) {
                        DB::table('sales_opportunity_purchases')->where('id', $target->id)->update([
                            'quantity_90' => (float) $target->quantity_90 + (float) $current->quantity_90,
                            'quantity_365' => (float) $target->quantity_365 + (float) $current->quantity_365,
                            'tax_key' => $root, 'document_key' => $target->document_key ?: $current->document_key,
                            'code_key' => $target->code_key ?: $current->code_key,
                        ]);
                        DB::table('sales_opportunity_purchases')->where('id', $current->id)->delete();
                    } else {
                        DB::table('sales_opportunity_purchases')->where('id', $current->id)->update(['buyer_key' => $root, 'tax_key' => $root]);
                    }
                });
            }
        });
        $aliases = [];
        foreach ($this->taxKeys as $tax => $present) {
            $aliases[$tax] = $tax;
        }
        foreach ($this->documentTaxes as $document => $tax) {
            if ($tax !== false) {
                $aliases[$document] = $tax ?? $document;
            }
        }
        $batch = [];
        foreach ($aliases as $alias => $root) {
            $batch[] = ['snapshot_id' => $snapshotId, 'alias_key' => $alias, 'buyer_key' => $root];
            if (count($batch) >= 250) {
                DB::table('sales_opportunity_buyer_aliases')->insert($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            DB::table('sales_opportunity_buyer_aliases')->insert($batch);
        }
        $this->documentTaxes = $this->taxKeys = $this->documentOnlyLines = [];

        return ['ambiguous_ids' => count($ambiguous), 'ambiguous_lines' => $anonymousLines];
    }

    private function identifier(mixed $value): string
    {
        return (is_string($value) || is_int($value)) && mb_strlen((string) $value) <= 191 ? trim((string) $value) : '';
    }

    private function numeric(mixed $value): bool
    {
        return ! is_bool($value) && is_scalar($value) && is_numeric($value) && is_finite((float) $value);
    }

    private function flush(int $snapshotId): void
    {
        if ($this->buffer === []) {
            return;
        }
        DB::transaction(function () use ($snapshotId): void {
            $existing = DB::table('sales_opportunity_purchases')->where('snapshot_id', $snapshotId)
                ->where(function ($query): void {
                    foreach ($this->buffer as $row) {
                        $query->orWhere(fn ($pair) => $pair->where('product_id', $row['product_id'])->where('buyer_key', $row['buyer_key']));
                    }
                })->get(['product_id', 'buyer_key', 'quantity_90', 'quantity_365']);
            foreach ($existing as $row) {
                $key = $row->product_id.':'.$row->buyer_key;
                if (isset($this->buffer[$key])) {
                    $this->buffer[$key]['quantity_90'] += (float) $row->quantity_90;
                    $this->buffer[$key]['quantity_365'] += (float) $row->quantity_365;
                }
            }
            DB::table('sales_opportunity_purchases')->upsert(array_values($this->buffer), ['snapshot_id', 'product_id', 'buyer_key'], ['quantity_90', 'quantity_365', 'tax_key', 'document_key', 'code_key']);
            $this->buffer = [];
        });
    }

    private function productIdentities(): array
    {
        $identities = [];
        Product::query()->select(['id', 'code', 'sku', 'payload'])->chunkById(50, function ($products) use (&$identities): void {
            foreach ($products as $product) {
                $codes = array_unique(array_filter([
                    $this->identifier($product->code), $this->identifier($product->sku), $this->identifier(data_get($product->payload, 'eracuni.productCode')),
                    $this->identifier(data_get($product->payload, 'opencart.model')), $this->identifier(data_get($product->payload, 'ideus_csv.model')),
                ]));
                foreach ($codes as $code) {
                    $key = 'code:'.$code;
                    $identities[$key] = ! isset($identities[$key]) || $identities[$key] === $product->id ? $product->id : 0;
                }
            }
        });
        ProductOptionValue::query()->select(['id', 'product_id', 'sku'])->whereNotNull('sku')->chunkById(200, function ($options) use (&$identities): void {
            foreach ($options as $option) {
                $code = $this->identifier($option->sku);
                if ($code !== '') {
                    $key = 'code:'.$code;
                    $identities[$key] = ! isset($identities[$key]) || $identities[$key] === $option->product_id ? $option->product_id : 0;
                }
            }
        });

        return $identities;
    }
}
