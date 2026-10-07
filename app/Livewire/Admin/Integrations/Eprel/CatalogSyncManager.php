<?php

namespace App\Livewire\Admin\Integrations\Eprel;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Integrations\Eprel\EprelCatalogSyncItem;
use App\Models\Integrations\Eprel\EprelCatalogSyncRun;
use App\Services\Integrations\Eprel\EprelCatalogSyncService;
use App\Services\Integrations\Eprel\EprelSettingsService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Throwable;

class CatalogSyncManager extends Component
{
    use WithPagination;

    public int $limit = 10;

    public string $categoryId = '';

    #[Locked]
    public ?int $selectedRunId = null;

    public function mount(): void
    {
        $this->authorizeManage();
        if ($this->backendReady()) {
            $this->selectedRunId = EprelCatalogSyncRun::query()->max('id');
        }
    }

    public function start(): void
    {
        $this->startRun(false);
    }

    public function startAll(): void
    {
        $this->startRun(true);
    }

    public function selectRun(int $runId): void
    {
        $this->authorizeManage();
        abort_unless($this->backendReady(), 404);
        $run = EprelCatalogSyncRun::query()->find($runId);
        abort_unless($run, 404);
        $this->selectedRunId = (int) $run->getKey();
        $this->resetPage(pageName: 'eprelItemsPage');
        $this->resetValidation('operation');
    }

    public function cancel(int $runId): void
    {
        $this->authorizeManage();
        $this->resetValidation('operation');
        if (! $this->backendReady()) {
            $this->addError('operation', __('EPREL obrada se još priprema. Osvježite stranicu.'));

            return;
        }
        $run = EprelCatalogSyncRun::query()->findOrFail($runId);
        if (! in_array($run->status, ['pending', 'running', 'paused'], true)) {
            $this->addError('operation', __('Ovaj paket više nije moguće otkazati.'));

            return;
        }
        try {
            app(EprelCatalogSyncService::class)->cancel($run);
        } catch (Throwable) {
            $this->addError('operation', __('Otkazivanje nije uspjelo. Osvježite stanje paketa i pokušajte ponovno.'));

            return;
        }
        $this->selectedRunId = (int) $run->getKey();
        $this->dispatch('notify', type: 'success', message: __('EPREL obrada je otkazana. Već potvrđene deklaracije ostaju sačuvane.'));
    }

    public function resume(int $runId): void
    {
        $this->authorizeManage();
        $this->resetValidation('operation');
        if (! $this->readyForRequests()) {
            return;
        }
        $run = EprelCatalogSyncRun::query()->findOrFail($runId);
        if (! in_array($run->status, ['paused', 'failed'], true)) {
            $this->addError('operation', __('Nastaviti se može samo pauziran ili neuspješan paket.'));

            return;
        }
        try {
            app(EprelCatalogSyncService::class)->resume($run);
        } catch (Throwable) {
            $this->addError('operation', __('Nastavak nije uspio. Provjerite EPREL postavke i postojeće aktivne pakete.'));

            return;
        }
        $this->selectedRunId = (int) $run->getKey();
        $this->dispatch('notify', type: 'success', message: __('EPREL obrada je nastavljena.'));
    }

    public function render()
    {
        $this->authorizeManage();
        $backendReady = $this->backendReady();
        $selectedRun = $backendReady && $this->selectedRunId
            ? EprelCatalogSyncRun::query()->find($this->selectedRunId)
            : null;
        $runs = $backendReady
            ? EprelCatalogSyncRun::query()->orderByDesc('id')->paginate(10, pageName: 'eprelRunsPage')
            : new LengthAwarePaginator([], 0, 10);
        $items = $selectedRun
            ? $selectedRun->items()->orderBy('id')->paginate(20, pageName: 'eprelItemsPage')
            : new LengthAwarePaginator([], 0, 20);
        $products = $items->isEmpty() ? collect() : Product::query()
            ->select(['id', 'code', 'sku'])
            ->whereIn('id', $items->pluck('product_id')->filter()->all())
            ->with('translations:id,product_id,locale,name')
            ->get()->keyBy('id');
        $categories = Category::query()->where('scope', Category::SCOPE_CATALOG)
            ->where('is_active', true)->with('translations:id,category_id,locale,name')
            ->orderBy('_lft')->orderBy('id')->get();
        $settings = app(EprelSettingsService::class);
        $active = $backendReady && EprelCatalogSyncRun::query()->whereIn('status', ['pending', 'running'])->exists();
        $unfinished = $backendReady && EprelCatalogSyncRun::query()->whereIn('status', ['pending', 'running', 'paused'])->exists();
        $user = auth()->user();

        return view('livewire.admin.integrations.eprel.catalog-sync-manager', [
            'backendReady' => $backendReady,
            'eprelConfigured' => $settings->enabled() && $settings->hasApiKey(),
            'pollFrequently' => $active,
            'activeRunExists' => $unfinished,
            'selectedRun' => $selectedRun,
            'runs' => $runs,
            'items' => $items,
            'products' => $products,
            'categories' => $categories,
            'canEditProducts' => $user && (Bouncer::is($user)->an('superadmin') || $user->can('catalog.products.update')),
        ]);
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => __('Na čekanju'), 'running' => __('U tijeku'), 'paused' => __('Pauzirano'),
            'completed' => __('Završeno'), 'cancelled' => __('Otkazano'), 'failed', 'error' => __('Neuspješno'),
            'matched' => __('Povezano'), 'not_found' => __('Bez podudaranja'), 'needs_group' => __('Potrebna grupa'),
            'needs_brand' => __('Potrebna marka'), 'no_identifiers' => __('Nema identifikatora'),
            'conflict' => __('Potreban pregled'), 'skipped' => __('Preskočeno'), default => __('Nepoznato'),
        };
    }

    public function itemExplanation(string $status): string
    {
        // Raw exception messages are intentionally never rendered: an upstream
        // transport failure may include authentication/request headers.
        return match ($status) {
            'matched' => __('Povezana je točno potvrđena službena deklaracija.'),
            'not_found' => __('Nije pronađeno točno podudaranje u EPREL-u.'),
            'needs_group' => __('Za pretragu po modelu odaberite EPREL grupu na artiklu.'),
            'needs_brand' => __('Za sigurnu pretragu potrebna je marka proizvoda.'),
            'no_identifiers' => __('Nema valjanog barkoda, EPREL broja ili identifikatora modela.'),
            'conflict' => __('Identifikatori ili rezultati nisu jednoznačni. Potreban je ručni pregled.'),
            'error' => __('Dohvat nije uspio. Provjerite EPREL postavke; stavka nije automatski potvrđena.'),
            'skipped' => __('Potvrđena deklaracija ostaje sačuvana ili artikl nema pouzdan energetski identitet za dohvat.'),
            'running' => __('Provjerava se službeni registar.'),
            default => __('Stavka čeka obradu.'),
        };
    }

    private function startRun(bool $all): void
    {
        $this->authorizeManage();
        $this->resetValidation('operation');
        $rules = [
            'categoryId' => ['nullable', 'integer', Rule::exists((new Category)->getTable(), 'id')
                ->where('scope', Category::SCOPE_CATALOG)->where('is_active', true)],
        ];
        if (! $all) {
            $rules['limit'] = ['required', 'integer', 'min:1', 'max:50'];
        }
        $this->validate($rules);
        if (! $this->readyForRequests()) {
            return;
        }
        $categoryId = $this->categoryId === '' ? null : (int) $this->categoryId;
        try {
            $service = app(EprelCatalogSyncService::class);
            $run = $all
                ? $service->startAll($categoryId, (int) auth()->id())
                : $service->start($this->limit, $categoryId, (int) auth()->id());
        } catch (Throwable) {
            $this->addError('operation', __('Obrada nije pokrenuta. Provjerite EPREL postavke i postoji li već aktivan paket.'));

            return;
        }
        $this->selectedRunId = (int) $run->getKey();
        $this->resetPage(pageName: 'eprelRunsPage');
        $this->resetPage(pageName: 'eprelItemsPage');
        $this->dispatch('notify', type: 'success', message: __('EPREL obrada je poslana u pozadinu. Napredak se osvježava automatski.'));
    }

    private function readyForRequests(): bool
    {
        if (! $this->backendReady()) {
            $this->addError('operation', __('EPREL obrada se još priprema. Osvježite stranicu.'));

            return false;
        }
        $settings = app(EprelSettingsService::class);
        if (! $settings->enabled() || ! $settings->hasApiKey()) {
            $this->addError('operation', __('Najprije uključite EPREL i spremite API ključ u EPREL postavkama.'));

            return false;
        }

        return true;
    }

    private function backendReady(): bool
    {
        return class_exists(EprelCatalogSyncRun::class) && class_exists(EprelCatalogSyncItem::class)
            && class_exists(EprelCatalogSyncService::class)
            && method_exists(EprelCatalogSyncService::class, 'startAll')
            && Schema::hasTable((new EprelCatalogSyncRun)->getTable())
            && Schema::hasTable((new EprelCatalogSyncItem)->getTable());
    }

    private function authorizeManage(): void
    {
        $user = auth()->user();
        abort_unless($user && (Bouncer::is($user)->an('superadmin')
            || ($user->can('admin.access') && $user->can('integrations.eprel.settings.manage'))), 403);
    }
}
