<?php

namespace App\Services\Integrations\Spreadsheet;

use App\Models\Catalog\Product\Product;
use App\Models\Integrations\Spreadsheet\SpreadsheetImportRun;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class SpreadsheetImportService
{
    private const STALE_MESSAGE = 'Podaci proizvoda promijenjeni su nakon pregleda. Izradite novi pregled; nijedna promjena nije spremljena.';

    public function __construct(private readonly SpreadsheetFileReader $reader) {}

    public static function defaultOptions(): array
    {
        return [
            'enabled' => true, 'first_row' => 2, 'identifier_type' => 'model', 'identifier_column' => 1,
            'update_prices' => false, 'price_column' => 2, 'markup_percentage' => 0,
            'update_stock' => false, 'stock_column' => 2,
            'update_supplier_stock' => true, 'supplier_stock_column' => 2,
        ];
    }

    public function preview(string $absoluteFilePath, array $options, ?int $actorId = null): SpreadsheetImportRun
    {
        $options = $this->options($options);
        $fields = $this->fields($options);
        $columns = array_values(array_unique([$options['identifier_column'], ...array_values($fields)]));
        $source = $this->reader->read($absoluteFilePath, $columns, $options['first_row']);
        $products = Product::query()->get(['id', 'code', 'sku', 'barcode', 'payload', 'base_price', 'stock_qty', 'supplier_stock_qty']);
        $index = $this->identityIndex($products, $options['identifier_type']);
        $plans = [];
        $productRows = [];
        $duplicates = 0;
        foreach ($source['rows'] as $row) {
            $identifier = $this->sourceIdentifier($row, $options['identifier_column']);
            $item = [
                'row_number' => $row['row_number'], 'identifier' => mb_substr($identifier, 0, 160),
                'product_id' => null, 'status' => 'invalid', 'old_values' => [], 'new_values' => [],
                'identity_hash' => null, 'message' => null,
            ];
            try {
                if ($row['errors'] !== []) {
                    throw new RuntimeException(reset($row['errors']));
                }
                if ($identifier === '' || mb_strlen($identifier) > 160) {
                    throw new RuntimeException('Oznaka proizvoda je prazna ili preduga.');
                }
                $rawIdentifier = $row['values'][$options['identifier_column']] ?? null;
                if ((is_int($rawIdentifier) || is_float($rawIdentifier)) && abs($rawIdentifier) > 999999999999999) {
                    throw new RuntimeException('Brojčana oznaka ima više od 15 znamenki. U Excelu je potrebno spremiti je kao tekst.');
                }
                $new = [];
                foreach ($fields as $field => $column) {
                    $new[$field] = $field === 'base_price'
                        ? $this->price($row['values'][$column] ?? null, $options['markup_percentage'])
                        : $this->stock($row['values'][$column] ?? null);
                }
                $candidates = $index[$this->key($identifier, $options['identifier_type'])] ?? [];
                if ($candidates === []) {
                    $item['status'] = 'unmatched';
                    $item['new_values'] = $new;
                    $item['message'] = 'Nije pronađen proizvod s ovom oznakom.';
                } elseif (count($candidates) > 1) {
                    $item['status'] = 'conflict';
                    $item['message'] = 'Oznaka pripada više proizvoda. Uvoz je potrebno razjasniti.';
                } else {
                    $product = $candidates[0];
                    $old = $this->values($product, array_keys($fields));
                    $item['product_id'] = $product->id;
                    $item['old_values'] = $old;
                    $item['new_values'] = $new;
                    $item['identity_hash'] = $this->identityHash($product, $options['identifier_type']);
                    $item['status'] = $old === $new ? 'unchanged' : 'ready';
                    $previous = $productRows[$product->id] ?? [];
                    if ($previous !== []) {
                        $different = false;
                        foreach ($previous as $prior) {
                            if ($plans[$prior]['new_values'] !== $new || $plans[$prior]['status'] === 'conflict') {
                                $different = true;
                            }
                        }
                        if ($different) {
                            $item['status'] = 'conflict';
                            $item['message'] = 'Više redaka predlaže različite vrijednosti za isti proizvod.';
                            foreach ($previous as $prior) {
                                $plans[$prior]['status'] = 'conflict';
                                $plans[$prior]['message'] = $item['message'];
                            }
                        } else {
                            $item['status'] = 'unchanged';
                            $item['message'] = 'Jednak ponovljeni redak je zanemaren.';
                            $duplicates++;
                        }
                    }
                    $productRows[$product->id][] = count($plans);
                }
            } catch (RuntimeException $exception) {
                $item['message'] = $exception->getMessage();
            }
            $plans[] = $item;
        }

        return DB::transaction(function () use ($options, $source, $plans, $duplicates, $actorId): SpreadsheetImportRun {
            $counts = array_count_values(array_column($plans, 'status'));
            $run = SpreadsheetImportRun::query()->create([
                'status' => 'preview', 'kind' => 'preview', 'actor_id' => $actorId, 'options' => $options,
                'summary' => ['sheet_name' => $source['sheet_name'], 'source_type' => $source['source_type'], 'duplicate_count' => $duplicates],
                'total_count' => count($plans), 'updated_count' => $counts['ready'] ?? 0,
                'unchanged_count' => $counts['unchanged'] ?? 0, 'invalid_count' => $counts['invalid'] ?? 0,
                'unmatched_count' => $counts['unmatched'] ?? 0, 'conflict_count' => $counts['conflict'] ?? 0,
                'started_at' => now(),
            ]);
            foreach (array_chunk($plans, 300) as $chunk) {
                $run->items()->createMany($chunk);
            }

            return $run->fresh();
        });
    }

    public function apply(int $runId, ?int $actorId = null): SpreadsheetImportRun
    {
        $run = SpreadsheetImportRun::query()->findOrFail($runId);
        if ($run->status !== 'preview') {
            throw new RuntimeException('Ovaj pregled je već obrađen. Za novi uvoz izradite novi pregled.');
        }
        if ($run->invalid_count > 0 || $run->conflict_count > 0) {
            throw new RuntimeException('Pregled sadrži neispravne ili proturječne retke. Ispravite datoteku i izradite novi pregled.');
        }
        $options = $this->options($run->options);
        $fields = $this->fields($options);
        $locks = [];
        $names = array_keys($fields);
        sort($names);
        try {
            foreach ($names as $field) {
                $lock = Cache::lock('herrera-stock-sync:'.$field, 600);
                if (! $lock->get()) {
                    throw new RuntimeException('Ažuriranje je već u tijeku. Pokušajte ponovno nakon završetka.');
                }
                $locks[] = $lock;
            }
            try {
                DB::transaction(function () use ($runId, $options, $fields, $actorId): void {
                    $run = SpreadsheetImportRun::query()->lockForUpdate()->findOrFail($runId);
                    if ($run->status !== 'preview') {
                        throw new RuntimeException(self::STALE_MESSAGE);
                    }
                    $products = Product::query()->lockForUpdate()
                        ->get(['id', 'code', 'sku', 'barcode', 'payload', 'base_price', 'stock_qty', 'supplier_stock_qty'])->keyBy('id');
                    $index = $this->identityIndex($products, $options['identifier_type']);
                    $items = $run->items()->whereIn('status', ['ready', 'unchanged'])->orderBy('row_number')->get();
                    foreach ($items as $item) {
                        $product = $products->get($item->product_id);
                        $candidates = $index[$this->key((string) $item->identifier, $options['identifier_type'])] ?? [];
                        if (! $product || count($candidates) !== 1 || $candidates[0]->id !== $product->id
                            || $this->identityHash($product, $options['identifier_type']) !== $item->identity_hash
                            || $this->values($product, array_keys($fields)) !== $item->old_values) {
                            throw new RuntimeException(self::STALE_MESSAGE);
                        }
                    }
                    $updated = 0;
                    foreach ($items->where('status', 'ready') as $item) {
                        $product = $products->get($item->product_id);
                        $product->forceFill($item->new_values + ['updated_by' => $actorId])->save();
                        $item->update(['status' => 'applied']);
                        $updated++;
                    }
                    $run->update([
                        'status' => 'completed', 'kind' => 'import', 'completed_at' => now(),
                        'updated_count' => $updated, 'error_message' => null,
                        'summary' => ($run->summary ?? []) + ['applied_actor_id' => $actorId],
                    ]);
                });
            } catch (Throwable $exception) {
                $run->refresh();
                if ($run->status !== 'preview') {
                    throw new RuntimeException('Ovaj pregled je već obrađen. Za novi uvoz izradite novi pregled.');
                }
                $run->update([
                    'status' => 'failed', 'kind' => 'import', 'completed_at' => now(), 'updated_count' => 0,
                    'error_message' => $exception instanceof RuntimeException && $exception->getMessage() === self::STALE_MESSAGE
                        ? self::STALE_MESSAGE : 'Uvoz nije proveden. Nijedna promjena nije spremljena; izradite novi pregled.',
                ]);
            }
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }

        return $run->fresh();
    }

    private function options(array $values): array
    {
        $values = $values + self::defaultOptions();
        $options = Validator::make($values, [
            'enabled' => 'required|boolean', 'first_row' => 'required|integer|min:1|max:1048576',
            'identifier_type' => 'required|in:model,sku,ean,product_id,legacy_product_id',
            'identifier_column' => 'required|integer|min:1|max:16384',
            'update_prices' => 'required|boolean', 'price_column' => 'required|integer|min:1|max:16384',
            'markup_percentage' => 'required|numeric|min:0|max:10000',
            'update_stock' => 'required|boolean', 'stock_column' => 'required|integer|min:1|max:16384',
            'update_supplier_stock' => 'required|boolean', 'supplier_stock_column' => 'required|integer|min:1|max:16384',
        ])->validate();
        if (! $options['enabled']) {
            throw new RuntimeException('Uvoz iz tablice je isključen u postavkama.');
        }
        if (! $options['update_prices'] && ! $options['update_stock'] && ! $options['update_supplier_stock']) {
            throw new RuntimeException('Odaberite barem jednu vrijednost za ažuriranje.');
        }
        foreach (['first_row', 'identifier_column', 'price_column', 'stock_column', 'supplier_stock_column'] as $key) {
            $options[$key] = (int) $options[$key];
        }

        return $options;
    }

    private function fields(array $options): array
    {
        $fields = [];
        foreach (['base_price' => ['update_prices', 'price_column'], 'stock_qty' => ['update_stock', 'stock_column'], 'supplier_stock_qty' => ['update_supplier_stock', 'supplier_stock_column']] as $field => [$toggle, $column]) {
            if ($options[$toggle]) {
                $fields[$field] = $options[$column];
            }
        }

        return $fields;
    }

    private function sourceIdentifier(array $row, int $column): string
    {
        $value = $row['values'][$column] ?? null;
        if (! is_scalar($value) || is_bool($value)) {
            return '';
        }
        if ((is_int($value) || is_float($value)) && $value >= 0 && floor($value) === (float) $value
            && preg_match('/^0{2,160}$/D', (string) ($row['masks'][$column] ?? ''))) {
            return str_pad(sprintf('%.0f', $value), strlen($row['masks'][$column]), '0', STR_PAD_LEFT);
        }

        return trim((string) $value);
    }

    private function identityIndex(iterable $products, string $type): array
    {
        $result = [];
        foreach ($products as $product) {
            $identifier = $this->productIdentifier($product, $type);
            if ($identifier !== '') {
                $result[$this->key($identifier, $type)][] = $product;
            }
        }

        return $result;
    }

    private function productIdentifier(Product $product, string $type): string
    {
        $legacy = $product->payload['opencart'] ?? [];
        if ($type === 'product_id') {
            return (string) $product->id;
        }
        if ($type === 'legacy_product_id') {
            return trim((string) ($legacy['product_id'] ?? ''));
        }
        if ($type === 'model') {
            $importedModel = $product->payload['eracuni']['productCode'] ?? $product->payload['ideus_csv']['model'] ?? null;
            if (is_scalar($importedModel) && trim((string) $importedModel) !== '') {
                return trim((string) $importedModel);
            }
        }
        if (array_key_exists($type, $legacy)) {
            return trim((string) $legacy[$type]);
        }

        return trim((string) match ($type) {
            'model' => $product->code, 'sku' => $product->sku, 'ean' => $product->barcode, default => '',
        });
    }

    private function identityHash(Product $product, string $type): string
    {
        return hash('sha256', $type.':'.$product->id.':'.$this->productIdentifier($product, $type));
    }

    private function key(string $identifier, string $type): string
    {
        if (in_array($type, ['product_id', 'legacy_product_id'], true) && preg_match('/^\d+$/D', $identifier)) {
            $identifier = ltrim($identifier, '0') ?: '0';
        }

        return 'id:'.$identifier;
    }

    private function values(Product $product, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = $field === 'base_price'
                ? (string) BigDecimal::of((string) ($product->getRawOriginal($field) ?? 0))->toScale(4, RoundingMode::HALF_UP)
                : (int) $product->{$field};
        }

        return $values;
    }

    private function stock(mixed $value): int
    {
        if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
            return 0;
        }
        if (is_bool($value) || ! is_scalar($value)
            || ! preg_match('/^\+?\d+(?:[.,]0+)?$/D', trim((string) $value))) {
            throw new RuntimeException('Količina mora biti cijeli broj veći ili jednak nuli.');
        }
        $number = (float) str_replace(',', '.', trim((string) $value));
        if ($number > 2147483647) {
            throw new RuntimeException('Količina prelazi dopuštenu vrijednost.');
        }

        return (int) $number;
    }

    private function price(mixed $value, mixed $markup): string
    {
        if (! is_scalar($value) || is_bool($value)
            || ! preg_match('/^\+?\d+(?:[.,]\d{1,4})?$/D', trim((string) $value))) {
            throw new RuntimeException('Cijena mora biti valjan iznos veći ili jednak nuli; prazna ćelija nije dopuštena za ažuriranje cijene.');
        }
        $number = BigDecimal::of(str_replace(',', '.', trim((string) $value)));
        $factor = BigDecimal::of((string) $markup)->dividedBy(100, 8, RoundingMode::HALF_UP)->plus(1);
        $result = $number->multipliedBy($factor)->toScale(4, RoundingMode::HALF_UP);
        if ($result->isGreaterThan('9999999999999999.9999')) {
            throw new RuntimeException('Cijena prelazi dopuštenu vrijednost.');
        }

        return (string) $result;
    }
}
