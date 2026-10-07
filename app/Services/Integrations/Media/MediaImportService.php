<?php

namespace App\Services\Integrations\Media;

use App\Jobs\Integrations\Media\ImportLegacyProductMediaJob;
use App\Models\Catalog\Product\Product;
use App\Models\Integrations\Eracuni\EracuniCatalogItem;
use App\Models\Integrations\Eracuni\EracuniCatalogRun;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Throwable;

class MediaImportService
{
    public const KINDS = ['images', 'braytron_images'];

    public function __construct(private readonly StockSyncSettingsService $settings, private readonly LegacyProductImageClient $client) {}

    public function configured(string $kind): bool
    {
        $this->assertKind($kind);

        return $this->settings->configured($kind === 'images' ? 'eracuni' : 'braytron');
    }

    /** Local candidates only. A preview never contacts an external provider. */
    public function candidates(string $kind, int $limit = 100): array
    {
        $this->assertKind($kind);
        $limit = max(1, min(100, $limit));
        $products = [];
        $count = 0;
        $counts = $this->identifierCounts();
        $query = Product::query()->select('id', 'code', 'sku', 'payload->opencart->sku as legacy_sku')->orderBy('id');
        if ($kind === 'images') {
            $query->whereDoesntHave('media', fn ($query) => $query->where('collection_name', 'product_main')->whereRaw('LOWER(file_name) != ?', ['no-image.jpg']))
                ->where(fn ($query) => $query->whereNull('payload->opencart->image')->orWhere('payload->opencart->image', '')
                    ->orWhere('payload->opencart->image', 'like', '%no-image.jpg'));
            $identity = DB::connection()->getQueryGrammar()->wrap('products.payload->opencart->sku');
            $query->whereNotExists(fn ($query) => $query->selectRaw('1')->from('eracuni_catalog_items as attempted')
                ->join('eracuni_catalog_runs as attempt_run', 'attempt_run.id', '=', 'attempted.run_id')
                ->whereColumn('attempted.product_id', 'products.id')->where('attempt_run.kind', 'images')
                ->where('attempt_run.status', 'completed')->where('attempted.status', 'skipped')
                ->where('attempted.plan->outcome', 'not_found')
                ->whereRaw('attempted.identifier = TRIM(COALESCE('.$identity.', products.sku))'));
        } else {
            $query->whereHas('manufacturer', fn ($query) => $query->whereRaw('LOWER(code) IN (?, ?)', ['braytron', 'brytron'])
                ->orWhereHas('translations', fn ($query) => $query->whereRaw('LOWER(name) IN (?, ?)', ['braytron', 'brytron'])))
                ->where(fn ($query) => $query->whereNull('payload->opencart->mpn')->orWhere('payload->opencart->mpn', '!=', '1'));
            $doneIds = EracuniCatalogItem::query()->whereIn('status', ['imported', 'unchanged', 'skipped'])
                ->whereHas('run', fn ($query) => $query->where('kind', 'braytron_images')->where('status', 'completed'))
                ->whereNotNull('product_id')->pluck('product_id')->all();
            $query->whereNotIn('id', $doneIds);
        }
        foreach ($query->lazyById(1000) as $product) {
            $identifier = $this->identifier($product);
            if ($identifier === '' || mb_strlen($identifier) > 120 || ($counts['id:'.$identifier] ?? 0) !== 1) {
                continue;
            }
            $count++;
            if (count($products) < $limit) {
                $products[] = ['id' => $product->id, 'identifier' => $identifier, 'name' => $product->code];
            }
        }
        $names = Product::query()->with('translations')->whereKey(array_column($products, 'id'))->get()->keyBy('id');
        foreach ($products as &$row) {
            $row['name'] = $this->name($names[$row['id']]);
        }
        unset($row);

        return ['count' => $count, 'products' => $products, 'limit' => $limit];
    }

    public function start(string $kind, ?array $productIds = null, ?int $actorId = null): EracuniCatalogRun
    {
        $this->assertKind($kind);
        if (! $this->configured($kind)) {
            throw new RuntimeException('Izvor slika nije konfiguriran.');
        }
        if ($productIds === null) {
            $productIds = array_column($this->candidates($kind)['products'], 'id');
        }
        if ($productIds === [] || count($productIds) > 100 || count(array_unique($productIds, SORT_REGULAR)) !== count($productIds)) {
            throw new RuntimeException('Odaberite od 1 do 100 različitih proizvoda za uvoz slika.');
        }
        foreach ($productIds as $id) {
            if ((! is_int($id) && (! is_string($id) || ! ctype_digit($id))) || (int) $id < 1) {
                throw new RuntimeException('Odabir proizvoda nije valjan.');
            }
        }
        $lock = Cache::lock('herrera-media-import:'.$kind.':prepare', 30);
        if (! $lock->get()) {
            throw new RuntimeException('Priprema uvoza slika već je u tijeku.');
        }
        try {
            if (EracuniCatalogRun::query()->where('kind', $kind)->whereIn('status', ['queued', 'running'])->exists()) {
                throw new RuntimeException('Uvoz ovih slika već je u tijeku.');
            }
            $counts = $this->identifierCounts();
            $products = Product::query()->with(['media', 'translations'])->whereKey($productIds)->get();
            if ($products->count() !== count($productIds)) {
                throw new RuntimeException('Jedan od odabranih proizvoda više ne postoji.');
            }
            foreach ($products as $product) {
                $identifier = $this->identifier($product);
                if ($identifier === '' || mb_strlen($identifier) > 120 || ($counts['id:'.$identifier] ?? 0) !== 1) {
                    throw new RuntimeException('Odabrani proizvodi nemaju jedinstvene i valjane dobavljačke šifre.');
                }
            }
            $run = DB::transaction(function () use ($products, $kind, $actorId): EracuniCatalogRun {
                $run = EracuniCatalogRun::query()->create([
                    'kind' => $kind, 'status' => 'queued', 'actor_id' => $actorId,
                    'fetched_count' => $products->count(), 'eligible_count' => $products->count(),
                    'summary' => ['batch_limit' => 100, 'images_added' => 0, 'duplicate_count' => 0, 'failed_count' => 0],
                ]);
                foreach ($products as $product) {
                    $run->items()->create([
                        'product_id' => $product->id, 'identifier' => $this->identifier($product), 'name' => $this->name($product),
                        'status' => 'queued', 'plan' => ['images_added' => 0, 'duplicate_count' => 0, 'failed_count' => 0],
                    ]);
                }

                return $run;
            });
            try {
                $this->enqueue($run->id);
            } catch (Throwable) {
                $this->fail($run->id);
                throw new RuntimeException('Zadatak uvoza slika nije moguće staviti u red. Provjerite red zadataka i pokušajte ponovno.');
            }

            return $run->fresh();
        } finally {
            $lock->release();
        }
    }

    /** One product (at most nine image downloads) per queued invocation. */
    public function process(int $runId): EracuniCatalogRun
    {
        $run = EracuniCatalogRun::query()->findOrFail($runId);
        $this->assertKind($run->kind);
        if (! in_array($run->status, ['queued', 'running'], true)) {
            return $run;
        }
        $lock = Cache::lock('herrera-media-import:'.$run->kind, 360);
        if (! $lock->get()) {
            throw new RuntimeException('Uvoz slika ovog izvora već je aktivan.');
        }
        try {
            $run->refresh();
            if (! in_array($run->status, ['queued', 'running'], true)) {
                return $run;
            }
            $run->forceFill(['status' => 'running', 'started_at' => $run->started_at ?? now()])->save();
            $item = $run->items()->whereIn('status', ['queued', 'running'])->orderBy('id')->first();
            if ($item) {
                $item->forceFill(['status' => 'running'])->save();
                $this->processItem($run, $item);
            }

            return $this->summarize($run);
        } finally {
            $lock->release();
        }
    }

    public function enqueue(int $runId): void
    {
        // Even installations using the synchronous queue must keep HTTP actions bounded.
        $connection = config('queue.default') === 'sync' ? 'database' : config('queue.default');
        ImportLegacyProductMediaJob::dispatch($runId)->onConnection($connection)->afterCommit();
    }

    /** A previous report provides a bounded, explicit retry selection. */
    public function retry(int $runId, ?int $actorId = null): EracuniCatalogRun
    {
        $run = EracuniCatalogRun::query()->findOrFail($runId);
        $this->assertKind($run->kind);
        if (! in_array($run->status, ['completed', 'failed'], true)) {
            throw new RuntimeException('Pričekajte završetak uvoza prije ponovnog pokušaja.');
        }
        $ids = $run->items()->whereIn('status', ['failed', 'skipped'])->whereNotNull('product_id')
            ->distinct()->limit(100)->pluck('product_id')->all();

        return $this->start($run->kind, $ids, $actorId);
    }

    public function fail(int $runId): void
    {
        $run = EracuniCatalogRun::query()->find($runId);
        if (! $run || ! in_array($run->kind, self::KINDS, true) || ! in_array($run->status, ['queued', 'running'], true)) {
            return;
        }
        $run->items()->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed', 'message' => 'Zadatak uvoza slika nije dovršen. Pokrenite novi pregled i uvoz.',
        ]);
        $this->summarize($run);
    }

    private function processItem(EracuniCatalogRun $run, EracuniCatalogItem $item): void
    {
        $added = (int) ($item->plan['images_added'] ?? 0);
        $duplicates = (int) ($item->plan['duplicate_count'] ?? 0);
        $failed = 0;
        try {
            $product = Product::query()->with('media')->find($item->product_id);
            if (! $product || $this->identifier($product) !== $item->identifier
                || ($this->identifierCounts()['id:'.$item->identifier] ?? 0) !== 1) {
                $item->forceFill(['status' => 'skipped', 'message' => 'Proizvod ili dobavljačka šifra promijenjeni su nakon pripreme.'])->save();

                return;
            }
            if ($run->kind === 'images') {
                if ($this->hasMain($product)) {
                    $item->forceFill(['status' => 'skipped', 'message' => 'Glavna fotografija već postoji i sačuvana je.'])->save();

                    return;
                }
                $image = $this->client->eracuni($item->identifier);
                if ($image === null) {
                    $item->forceFill(['status' => 'skipped', 'message' => 'ERP nema glavnu fotografiju za ovaj proizvod.',
                        'plan' => ['images_added' => 0, 'duplicate_count' => 0, 'failed_count' => 0, 'outcome' => 'not_found'],
                    ])->save();

                    return;
                }
                $product->refresh()->load('media');
                if ($this->hasMain($product)) {
                    $item->forceFill(['status' => 'skipped', 'message' => 'Glavna fotografija dodana je tijekom dohvata i sačuvana je.'])->save();

                    return;
                }
                if (! $this->attach($product, $image, 'product_main', 'eracuni')) {
                    $item->forceFill(['status' => 'skipped', 'message' => 'Glavna fotografija dodana je tijekom spremanja i sačuvana je.'])->save();

                    return;
                }
                $added++;
            } else {
                $urls = $this->client->braytronImages()['id:'.$item->identifier] ?? [];
                if ($urls === []) {
                    $item->forceFill(['status' => 'skipped', 'message' => 'Braytron nema dodatnih fotografija za ovu šifru.'])->save();

                    return;
                }
                $hashes = $this->existingHashes($product);
                foreach ($urls as $url) {
                    try {
                        $image = $this->client->download($url);
                        if (isset($hashes[$image['sha256']])) {
                            $duplicates++;

                            continue;
                        }
                        $this->attach($product, $image, 'product_gallery', 'braytron');
                        $hashes[$image['sha256']] = true;
                        $added++;
                        // Retain progress so a worker retry does not lose already attached images.
                        $item->forceFill(['plan' => ['images_added' => $added, 'duplicate_count' => $duplicates, 'failed_count' => $failed]])->save();
                    } catch (Throwable) {
                        $failed++;
                    }
                }
            }
            $item->forceFill([
                'status' => $failed > 0 ? 'failed' : ($added > 0 ? 'imported' : 'unchanged'),
                'plan' => ['images_added' => $added, 'duplicate_count' => $duplicates, 'failed_count' => $failed],
                'message' => $failed > 0 ? 'Jedna ili više fotografija nisu preuzete; postojeće fotografije su sačuvane.' : null,
            ])->save();
        } catch (Throwable) {
            $item->forceFill([
                'status' => 'failed', 'message' => 'Dohvat ili spremanje fotografije nije uspjelo. Postojeće fotografije su sačuvane.',
                'plan' => ['images_added' => $added, 'duplicate_count' => $duplicates, 'failed_count' => max(1, $failed)],
            ])->save();
        }
    }

    private function attach(Product $product, array $image, string $collection, string $source): bool
    {
        $relative = 'integrations/media-import/'.bin2hex(random_bytes(16)).'.'.$image['extension'];
        $disk = Storage::disk('local');
        try {
            $disk->put($relative, $image['bytes']);
            $media = $product->addMedia($disk->path($relative))->usingFileName($source.'-'.$product->id.'-'.substr($image['sha256'], 0, 16).'.'.$image['extension'])
                ->withCustomProperties(['supplier_source' => $source, 'sha256' => $image['sha256']])
                ->toMediaCollection($collection === 'product_main' ? 'product_erp_import_staging' : $collection);
            if ($collection === 'product_main') {
                // Spatie's singleFile cleanup can delete a concurrent manual upload.
                // Stage first, then promote without ever deleting another main photo.
                $accepted = DB::transaction(function () use ($product, $media): bool {
                    $current = Product::query()->lockForUpdate()->find($product->id);
                    if (! $current) {
                        return false;
                    }
                    $current->load('media');
                    if ($this->hasMain($current)) {
                        return false;
                    }
                    $placeholders = $current->getMedia('product_main')->filter(fn ($item) => strtolower($item->file_name) === 'no-image.jpg');
                    $media->forceFill(['collection_name' => 'product_main'])->save();
                    $protected = $current->media()->where('collection_name', 'product_main')->whereKeyNot($media->id)
                        ->whereRaw('LOWER(file_name) != ?', ['no-image.jpg'])->exists();
                    if ($protected) {
                        return false;
                    }
                    foreach ($placeholders as $placeholder) {
                        $placeholder->delete();
                    }

                    return true;
                });
                if (! $accepted) {
                    $media->delete();

                    return false;
                }
                // The staging collection has no conversions; generate the actual main profile after promotion.
                app(FileManipulator::class)->createDerivedFiles($media, queueAll: true);
            }
            Cache::forget('front:product:last-modified:'.$product->id);

            return true;
        } finally {
            $disk->delete($relative);
        }
    }

    private function existingHashes(Product $product): array
    {
        $hashes = [];
        foreach ($product->getMedia('product_gallery')->merge($product->getMedia('product_main')) as $media) {
            $hash = (string) $media->getCustomProperty('sha256', '');
            if ($hash === '') {
                try {
                    $disk = Storage::disk($media->disk);
                    $path = $media->getPathRelativeToRoot();
                    if ($disk->exists($path) && $disk->size($path) <= LegacyProductImageClient::MAX_IMAGE_BYTES) {
                        $hash = hash('sha256', $disk->get($path));
                    }
                } catch (Throwable) {
                    // Unreadable manual media stays in place.
                }
            }
            if ($hash !== '') {
                $hashes[$hash] = true;
            }
        }

        return $hashes;
    }

    private function hasMain(Product $product): bool
    {
        $media = $product->getFirstMedia('product_main');
        if ($media && strtolower($media->file_name) !== 'no-image.jpg') {
            return true;
        }
        $legacy = trim((string) data_get($product->payload, 'opencart.image', ''));

        return $legacy !== '' && strtolower(basename(str_replace('\\', '/', $legacy))) !== 'no-image.jpg';
    }

    private function identifier(Product $product): string
    {
        if (array_key_exists('legacy_sku', $product->getAttributes())) {
            return trim((string) ($product->legacy_sku ?? $product->sku));
        }

        return trim((string) data_get($product->payload, 'opencart.sku', $product->sku));
    }

    private function identifierCounts(): array
    {
        $counts = [];
        foreach (Product::query()->select('id', 'sku', 'payload->opencart->sku as legacy_sku')->lazyById(500) as $product) {
            $identifier = trim((string) ($product->legacy_sku ?? $product->sku));
            $key = 'id:'.$identifier;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    private function name(Product $product): string
    {
        return (string) ($product->translations->firstWhere('locale', 'hr')?->name ?: $product->code);
    }

    private function summarize(EracuniCatalogRun $run): EracuniCatalogRun
    {
        $items = $run->items()->get();
        $pending = $items->whereIn('status', ['queued', 'running'])->isNotEmpty();
        $failed = $items->where('status', 'failed')->count();
        $run->forceFill([
            'status' => $pending ? 'running' : ($failed > 0 ? 'failed' : 'completed'),
            'updated_count' => $items->filter(fn ($item) => (int) ($item->plan['images_added'] ?? 0) > 0)->count(),
            'unchanged_count' => $items->where('status', 'unchanged')->count(), 'skipped_count' => $items->where('status', 'skipped')->count(),
            'error_message' => ! $pending && $failed > 0 ? 'Uvoz je dovršen s pogreškama. Pogledajte izvještaj proizvoda.' : null,
            'completed_at' => $pending ? null : now(),
            'summary' => [
                'batch_limit' => 100, 'images_added' => $items->sum(fn ($item) => (int) ($item->plan['images_added'] ?? 0)),
                'duplicate_count' => $items->sum(fn ($item) => (int) ($item->plan['duplicate_count'] ?? 0)), 'failed_count' => $failed,
                'failed_images' => $items->sum(fn ($item) => (int) ($item->plan['failed_count'] ?? 0)),
            ],
        ])->save();

        return $run->fresh();
    }

    private function assertKind(string $kind): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new RuntimeException('Vrsta uvoza slika nije valjana.');
        }
    }
}
