<?php

namespace App\Services\Integrations\Media;

use App\Models\Catalog\Product\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Synchronizes external URLs in metadata. Manual attachments and all PDF bodies stay untouched. */
class VayoxDocumentImportService
{
    public function __construct(private readonly VayoxDocumentClient $client) {}

    public function sync(bool $dryRun = false, array $productIds = [], int $limit = 100): array
    {
        if ($limit < 1 || $limit > 100 || count($productIds) > 100
            || collect($productIds)->contains(fn ($id) => ! ctype_digit((string) $id) || (int) $id < 1)) {
            throw new RuntimeException('Odaberite valjane proizvode i najviše 100 poveznica po pokretanju.');
        }
        $lock = Cache::lock('supplier-documents:vayox', 120);
        if (! $lock->get()) {
            throw new RuntimeException('Sinkronizacija Vayox poveznica već je u tijeku.');
        }
        try {
            $links = $this->client->links();
            $products = Product::query()->whereIn('barcode', array_unique(array_column($links, 'ean')))->get()->groupBy('barcode');
            $report = ['source_links' => count($links), 'matched_products' => 0, 'eligible_documents' => 0,
                'added' => 0, 'updated' => 0, 'unchanged' => 0, 'unmatched' => 0, 'ambiguous' => 0,
                'failed' => 0, 'deferred' => 0, 'dry_run' => $dryRun];
            $matchedIds = [];
            $selectedIds = array_map('intval', $productIds);
            foreach ($links as $link) {
                $matches = $products->get($link['ean'], collect())->filter(fn ($product) => (string) $product->barcode === $link['ean']);
                if ($matches->isEmpty()) {
                    $report['unmatched']++;

                    continue;
                }
                if ($matches->count() !== 1) {
                    $report['ambiguous']++;

                    continue;
                }
                $product = $matches->sole();
                if ($selectedIds !== [] && ! in_array((int) $product->id, $selectedIds, true)) {
                    continue;
                }
                if ($report['eligible_documents'] >= $limit) {
                    $report['deferred']++;

                    continue;
                }
                $report['eligible_documents']++;
                $matchedIds[$product->id] = true;
                if (! $dryRun) {
                    try {
                        $report[$this->attachLink($product, $link)]++;
                    } catch (Throwable) {
                        $report['failed']++;
                    }
                }
            }
            $report['matched_products'] = count($matchedIds);

            return $report;
        } finally {
            $lock->release();
        }
    }

    private function attachLink(Product $product, array $link): string
    {
        return DB::transaction(function () use ($product, $link): string {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            if ((string) $product->barcode !== $link['ean'] || Product::query()->where('barcode', $link['ean'])->count() !== 1) {
                throw new RuntimeException('Identitet proizvoda promijenio se tijekom dohvata.');
            }
            $url = VayoxDocumentClient::safeDocumentUrl($link['url']) ?? throw new RuntimeException;
            $record = ['source' => 'vayox', 'url' => $url,
                'name' => 'Dokument dobavljača Vayox — '.($product->sku ?: $product->code),
                'caption' => $link['label'] ?: 'Izvorni dokument dobavljača Vayox', 'ean' => $link['ean']];
            $payload = $product->payload ?? [];
            $documents = is_array($payload['supplier_documents'] ?? null) ? $payload['supplier_documents'] : [];
            foreach ($documents as $index => $document) {
                if (is_array($document) && ($document['source'] ?? null) === 'vayox'
                    && VayoxDocumentClient::safeDocumentUrl((string) ($document['url'] ?? '')) === $url) {
                    // Native MySQL JSON storage can reorder object keys.
                    if (collect($record)->every(fn ($value, $key) => array_key_exists($key, $document) && $document[$key] === $value)) {
                        return 'unchanged';
                    }
                    $documents[$index] = $record + ['synced_at' => now()->toIso8601String()];
                    $payload['supplier_documents'] = array_values($documents);
                    $product->update(['payload' => $payload]);

                    return 'updated';
                }
            }
            $documents[] = $record + ['synced_at' => now()->toIso8601String()];
            $payload['supplier_documents'] = array_values($documents);
            $product->update(['payload' => $payload]);

            return 'added';
        });
    }
}
