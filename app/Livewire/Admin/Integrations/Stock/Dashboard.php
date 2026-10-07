<?php

namespace App\Livewire\Admin\Integrations\Stock;

use App\Models\Integrations\Stock\StockSyncRun;
use App\Services\Integrations\Stock\StockSyncService;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use App\Support\Integrations\Stock\StockSyncRegistry;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Throwable;

class Dashboard extends Component
{
    use WithPagination;

    public string $supplierFilter = 'all';

    public string $triggerFilter = 'all';

    public string $statusFilter = 'all';

    public string $itemStatus = 'all';

    #[Locked]
    public ?int $selectedRunId = null;

    public function mount(): void
    {
        $this->authorizeManage();
    }

    public function updatedSupplierFilter(): void
    {
        $this->resetPage(pageName: 'stockRunsPage');
    }

    public function updatedTriggerFilter(): void
    {
        $this->resetPage(pageName: 'stockRunsPage');
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage(pageName: 'stockRunsPage');
    }

    public function updatedItemStatus(): void
    {
        $this->resetPage(pageName: 'stockItemsPage');
    }

    public function clearFilters(): void
    {
        $this->supplierFilter = 'all';
        $this->triggerFilter = 'all';
        $this->statusFilter = 'all';
        $this->resetPage(pageName: 'stockRunsPage');
    }

    public function selectRun(int $runId): void
    {
        $this->authorizeManage();
        StockSyncRun::query()->findOrFail($runId);
        $this->selectedRunId = $runId;
        $this->itemStatus = 'all';
        $this->resetPage(pageName: 'stockItemsPage');
    }

    public function closeReport(): void
    {
        $this->authorizeManage();
        $this->selectedRunId = null;
        $this->itemStatus = 'all';
        $this->resetPage(pageName: 'stockItemsPage');
    }

    public function runSupplier(string $supplier, StockSyncService $sync, StockSyncSettingsService $settings): void
    {
        $this->authorizeSupplier($supplier);

        if (! $settings->configured($supplier)) {
            $this->dispatch('notify', type: 'warning', message: __('Izvor nije spreman. Prvo treba podesiti vezu s dobavljačem.'));

            return;
        }

        try {
            $run = $sync->run($supplier, 'manual', (int) auth()->id());
            $this->selectedRunId = (int) $run->id;
            $this->itemStatus = 'all';
            $this->resetPage(pageName: 'stockItemsPage');
            $this->dispatch(
                'notify',
                type: $run->status === 'completed' ? 'success' : 'error',
                message: $run->status === 'completed'
                    ? __('Zalihe su osvježene. Izvještaj je prikazan ispod povijesti izvršavanja.')
                    : __('Osvježavanje nije uspjelo. Pogledajte izvještaj izvršavanja.'),
            );
        } catch (Throwable) {
            $this->dispatch('notify', type: 'error', message: __('Osvježavanje nije moguće pokrenuti. Provjerite vezu i je li obrada već u tijeku.'));
        }
    }

    public function toggleCron(string $supplier, bool $enabled, StockSyncSettingsService $settings): void
    {
        $this->authorizeSupplier($supplier);

        if ($enabled && ! $settings->configured($supplier)) {
            $this->dispatch('notify', type: 'warning', message: __('Prije uključivanja automatskog osvježavanja treba podesiti vezu s dobavljačem.'));

            return;
        }

        $settings->saveEnabled($supplier, $enabled);
        $this->dispatch('notify', type: 'success', message: $enabled
            ? __('Automatsko osvježavanje je uključeno.')
            : __('Automatsko osvježavanje je isključeno. Ručno pokretanje je i dalje dostupno.'));
    }

    public function render(StockSyncSettingsService $settings)
    {
        $this->authorizeManage();
        $registry = StockSyncRegistry::all();
        $latest = $this->latestRuns();
        $lastCronAttempts = $this->latestRuns('cron');
        $lastCronSuccesses = $this->latestRuns('cron', 'completed');
        $sources = [];

        foreach ($registry as $supplier => $source) {
            $lastRun = $latest->get($supplier);
            $isRunning = $lastRun?->status === 'running'
                && $lastRun->started_at?->gt(now()->subMinutes(10)) === true;
            $sources[$supplier] = $source + [
                'cron_enabled' => $settings->enabled($supplier),
                'configured' => $settings->configured($supplier),
                'cron_url' => $settings->cronUrl($supplier),
                'latest_run' => $lastRun,
                'is_running' => $isRunning,
                'is_interrupted' => $lastRun?->status === 'running' && ! $isRunning,
                'last_cron_attempt' => $lastCronAttempts->get($supplier),
                'last_cron_success' => $lastCronSuccesses->get($supplier),
            ];
        }

        $runs = StockSyncRun::query()
            ->when(isset($registry[$this->supplierFilter]), fn (Builder $query) => $query->where('supplier', $this->supplierFilter))
            ->when(in_array($this->triggerFilter, ['manual', 'cron'], true), fn (Builder $query) => $query->where('trigger', $this->triggerFilter))
            ->when(in_array($this->statusFilter, ['running', 'completed', 'failed'], true), fn (Builder $query) => $query->where('status', $this->statusFilter))
            ->latest('id')
            ->paginate(15, pageName: 'stockRunsPage');
        $selectedRun = $this->selectedRunId !== null
            ? StockSyncRun::query()->find($this->selectedRunId)
            : null;
        $items = $selectedRun?->items()
            ->when(in_array($this->itemStatus, ['updated', 'unchanged', 'unmatched', 'invalid'], true), fn (Builder $query) => $query->where('status', $this->itemStatus))
            ->orderBy('id')
            ->paginate(25, pageName: 'stockItemsPage');

        return view('livewire.admin.integrations.stock.dashboard', [
            'sources' => $sources,
            'runs' => $runs,
            'selectedRun' => $selectedRun,
            'items' => $items,
            'statusLabels' => ['running' => __('U tijeku'), 'completed' => __('Završeno'), 'failed' => __('Neuspješno')],
            'itemStatusLabels' => ['updated' => __('Ažurirano'), 'unchanged' => __('Bez promjene'), 'unmatched' => __('Bez podudaranja'), 'invalid' => __('Nevaljana stavka')],
        ]);
    }

    private function latestRuns(?string $trigger = null, ?string $status = null)
    {
        $ids = StockSyncRun::query()
            ->selectRaw('MAX(id) as id')
            ->when($trigger !== null, fn (Builder $query) => $query->where('trigger', $trigger))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->groupBy('supplier');

        return StockSyncRun::query()->whereIn('id', $ids)->get()->keyBy('supplier');
    }

    private function authorizeSupplier(string $supplier): void
    {
        $this->authorizeManage();
        abort_unless(array_key_exists($supplier, StockSyncRegistry::all()), 404);
    }

    private function authorizeManage(): void
    {
        $user = auth()->user();
        abort_unless($user && (Bouncer::is($user)->an('superadmin') || $user->can('integrations.stock.manage')), 403);
    }
}
