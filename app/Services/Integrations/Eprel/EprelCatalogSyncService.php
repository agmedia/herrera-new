<?php

namespace App\Services\Integrations\Eprel;

use App\Jobs\Integrations\Eprel\SyncEprelCatalogJob;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Integrations\Eprel\EprelCatalogSyncItem;
use App\Models\Integrations\Eprel\EprelCatalogSyncRun;
use App\Services\Integrations\Msan\EprelException;
use App\Services\Integrations\Msan\EprelHttpException;
use App\Services\Integrations\Msan\EprelMatchConflictException;
use App\Services\Integrations\Msan\EprelProductIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class EprelCatalogSyncService
{
    public function __construct(private readonly EprelSettingsService $settings, private readonly EprelCatalogProductMatcher $matcher) {}

    public function start(int $limit = 10, ?int $categoryId = null, ?int $actorId = null): EprelCatalogSyncRun
    {
        if ($limit < 1 || $limit > 50) {
            throw ValidationException::withMessages(['limit' => 'Probni paket može sadržavati od 1 do 50 artikala.']);
        }

        return $this->createRun($limit, $categoryId, $actorId);
    }

    public function startAll(?int $categoryId = null, ?int $actorId = null): EprelCatalogSyncRun
    {
        return $this->createRun(0, $categoryId, $actorId);
    }

    private function createRun(int $limit, ?int $categoryId, ?int $actorId): EprelCatalogSyncRun
    {
        $this->configured();
        if ($categoryId && ! Category::query()->where('scope', 'catalog')->whereKey($categoryId)->exists()) {
            throw ValidationException::withMessages(['categoryId' => 'Kategorija ne postoji.']);
        }
        $lock = Cache::lock('eprel-catalog:start', 15);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['limit' => 'Drugi EPREL paket upravo se pokreće.']);
        }
        try {
            if (EprelCatalogSyncRun::query()->whereIn('status', ['pending', 'running', 'paused'])->exists()) {
                throw ValidationException::withMessages(['limit' => 'Paket već postoji. Najprije ga nastavite ili otkažite.']);
            }
            $run = EprelCatalogSyncRun::query()->create(['status' => 'pending', 'category_id' => $categoryId, 'actor_id' => $actorId, 'max_products' => $limit]);
            SyncEprelCatalogJob::dispatch($run->id);

            return $run;
        } finally {
            $lock->release();
        }
    }

    public function cancel(EprelCatalogSyncRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $fresh = EprelCatalogSyncRun::query()->lockForUpdate()->findOrFail($run->id);
            if (in_array($fresh->status, ['pending', 'running', 'paused'], true)) {
                $fresh->update(['status' => 'cancelled', 'completed_at' => now()]);
            }
        });
    }

    public function resume(EprelCatalogSyncRun $run): void
    {
        $this->configured();
        $lock = Cache::lock('eprel-catalog:start', 15);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['limit' => 'Drugi EPREL paket upravo se pokreće.']);
        }
        try {
            DB::transaction(function () use ($run): void {
                $fresh = EprelCatalogSyncRun::query()->lockForUpdate()->findOrFail($run->id);
                if (! in_array($fresh->status, ['paused', 'failed'], true)) {
                    return;
                }
                if (EprelCatalogSyncRun::query()->where('id', '!=', $fresh->id)->whereIn('status', ['pending', 'running', 'paused'])->exists()) {
                    throw ValidationException::withMessages(['limit' => 'Drugi EPREL paket već je aktivan.']);
                }
                $fresh->items()->whereIn('status', ['running', 'error'])->update(['status' => 'pending', 'checked_at' => null]);
                $fresh->update(['status' => 'pending', 'error_message' => null, 'completed_at' => null]);
                SyncEprelCatalogJob::dispatch($fresh->id)->afterCommit();
            });
        } finally {
            $lock->release();
        }
    }

    /** One queue operation handles one article, not the entire catalogue. */
    public function processNext(EprelCatalogSyncRun $run): void
    {
        $lock = Cache::lock('eprel-catalog:processor', 620);
        if (! $lock->get()) {
            SyncEprelCatalogJob::dispatch($run->id)->delay(now()->addSeconds(10));

            return;
        }
        try {
            $run->refresh();
            if (! in_array($run->status, ['pending', 'running'], true)) {
                return;
            }
            try {
                $this->configured();
            } catch (Throwable) {
                EprelCatalogSyncRun::query()->whereKey($run->id)->whereIn('status', ['pending', 'running'])->update(['status' => 'paused', 'error_message' => 'EPREL dohvat je isključen ili API ključ nije dostupan.']);

                return;
            }
            if (! $run->planning_complete) {
                $this->prepare($run);
            }
            $run->refresh();
            if (! in_array($run->status, ['pending', 'running'], true)) {
                return;
            }
            if (! $run->planning_complete) {
                SyncEprelCatalogJob::dispatch($run->id)->delay(now()->addSecond());

                return;
            }
            $run = DB::transaction(function () use ($run): ?EprelCatalogSyncRun {
                $fresh = EprelCatalogSyncRun::query()->lockForUpdate()->findOrFail($run->id);
                if (! in_array($fresh->status, ['pending', 'running'], true)) {
                    return null;
                }
                $fresh->update(['status' => 'running', 'error_message' => null]);

                return $fresh;
            });
            if (! $run) {
                return;
            }
            $item = $run->items()->whereIn('status', ['pending', 'running'])->orderBy('id')->first();
            if (! $item) {
                $this->progress($run, true);

                return;
            }
            $item->update(['status' => 'running']);
            $delay = 2;
            try {
                $product = Product::query()->find($item->product_id);
                if (! $product || ! $product->is_active) {
                    $outcome = ['status' => 'skipped', 'message' => 'Artikl je uklonjen ili deaktiviran.'];
                } else {
                    $outcome = $this->matcher->match($product, $item->criteria, $item->identity, function () use ($run): bool {
                        return $this->settings->enabled() && EprelCatalogSyncRun::query()->lockForUpdate()->find($run->id)?->status === 'running';
                    });
                }
                $changes = ['status' => $outcome['status'], 'message' => $outcome['message'], 'matched_registration' => $outcome['registration'] ?? null, 'checked_at' => now()];
                if ($outcome['status'] === 'matched' && $product) {
                    // The confirmed registration is now part of the next lookup criteria.
                    $changes['criteria'] = $this->matcher->criteria($product->fresh());
                }
                $item->update($changes);
            } catch (EprelBatchThrottleException $e) {
                $delay = $e->retryAfter + 2;
                $item->update(['status' => 'pending', 'message' => $e->getMessage()]);
            } catch (EprelMatchConflictException $e) {
                $item->update(['status' => 'conflict', 'message' => $e->getMessage(), 'checked_at' => now()]);
            } catch (Throwable $e) {
                $message = $e instanceof EprelException ? $e->getMessage() : 'EPREL obrada je zaustavljena zbog neočekivane greške.';
                $item->update(['status' => 'error', 'message' => $message, 'checked_at' => now()]);
                EprelCatalogSyncRun::query()->whereKey($run->id)->whereIn('status', ['pending', 'running'])->update(['status' => $e instanceof EprelHttpException && in_array($e->status, [401, 403], true) ? 'failed' : 'paused', 'error_message' => $message]);
            }
            $this->progress($run);
            $run->refresh();
            if (in_array($run->status, ['pending', 'running'], true)) {
                SyncEprelCatalogJob::dispatch($run->id)->delay(now()->addSeconds($delay));
            }
        } finally {
            $lock->release();
        }
    }

    private function prepare(EprelCatalogSyncRun $run): void
    {
        // No product data is changed while planning; only audit rows are written.
        $query = Product::query()->where('is_active', true)->where('id', '>', $run->last_product_id)->with(['translations:id,product_id,locale,name', 'manufacturer.translations', 'categories.translations', 'energyDeclarations', 'packages'])->orderBy('id');
        if ($run->category_id) {
            $category = Category::query()->where('scope', 'catalog')->find($run->category_id);
            if (! $category) {
                throw new \RuntimeException('Kategorija je uklonjena.');
            }
            $query->whereHas('categories', fn ($q) => $q->where('scope', 'catalog')->whereBetween('_lft', [$category->_lft, $category->_rgt]));
        }
        $remaining = $run->max_products > 0 ? max(0, $run->max_products - $run->items()->count()) : 100;
        $limit = min(100, $remaining);
        $products = $limit > 0 ? $query->limit($limit)->get() : collect();
        foreach ($products as $product) {
            if (! in_array($run->fresh()->status, ['pending', 'running'], true)) {
                return;
            }
            $criteria = $this->matcher->criteria($product);
            $identity = EprelProductIdentity::fingerprint($product);
            $recent = EprelCatalogSyncItem::query()->where('product_id', $product->id)->where('identity', $identity)->whereIn('status', ['matched', 'not_found'])->where('checked_at', '>=', now()->subDays(7))->latest('id')->first();
            if ($recent && $this->matcher->sameCriteria($recent->criteria, $criteria)) {
                continue;
            }
            $status = 'pending';
            $message = null;
            if ($criteria['groups'] === [] && $criteria['registrations'] === [] && ! $product->energy_label_required) {
                $status = 'skipped';
                $message = 'Artikl nema povezanu EPREL grupu; nema nepotrebnih API poziva.';
            } elseif ($criteria['models'] === [] && $criteria['gtins'] === [] && $criteria['registrations'] === []) {
                $status = 'no_identifiers';
                $message = 'Nedostaju upotrebljiva šifra, EAN ili EPREL broj.';
            }
            $run->items()->firstOrCreate(['product_id' => $product->id], ['identity' => $identity, 'criteria' => $criteria, 'status' => $status, 'message' => $message, 'checked_at' => $status === 'pending' ? null : now()]);
        }
        $run->update(['started_at' => $run->started_at ?: now(), 'last_product_id' => $products->last()?->id ?? $run->last_product_id, 'planning_complete' => $products->count() < $limit || $limit === 0 || ($run->max_products > 0 && $run->items()->count() >= $run->max_products), 'total_count' => $run->items()->count()]);
        $this->progress($run);
    }

    private function progress(EprelCatalogSyncRun $run, bool $finished = false): void
    {
        DB::transaction(function () use ($run, $finished): void {
            $fresh = EprelCatalogSyncRun::query()->lockForUpdate()->findOrFail($run->id);
            $counts = $fresh->items()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
            $pending = ($counts['pending'] ?? 0) + ($counts['running'] ?? 0);
            $changes = ['total_count' => $counts->sum(), 'processed_count' => $counts->sum() - $pending, 'matched_count' => $counts['matched'] ?? 0, 'not_found_count' => $counts['not_found'] ?? 0, 'failed_count' => $counts['error'] ?? 0, 'skipped_count' => ($counts['skipped'] ?? 0) + ($counts['conflict'] ?? 0) + ($counts['no_identifiers'] ?? 0) + ($counts['needs_group'] ?? 0) + ($counts['needs_brand'] ?? 0)];
            if ($fresh->planning_complete && ($finished || $pending === 0) && in_array($fresh->status, ['pending', 'running'], true)) {
                $changes += ['status' => 'completed', 'completed_at' => now()];
            }
            $fresh->update($changes);
        });
    }

    private function configured(): void
    {
        if (! $this->settings->enabled() || ! $this->settings->hasApiKey()) {
            throw ValidationException::withMessages(['limit' => 'Uključite EPREL i spremite API ključ u EPREL postavkama.']);
        }
        $this->settings->apiKey();
    }
}
