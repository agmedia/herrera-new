<?php

namespace App\Livewire\Admin\Integrations\Media;

use App\Models\Integrations\Eracuni\EracuniCatalogRun;
use App\Services\Integrations\Media\MediaImportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Throwable;

class Dashboard extends Component
{
    use WithPagination;

    private const KINDS = ['images', 'braytron_images'];

    public string $kind = 'images';

    public array $selectedProductIds = [];

    public string $kindFilter = 'all';

    public string $statusFilter = 'all';

    #[Locked]
    public ?int $selectedRunId = null;

    public function mount(): void
    {
        $this->authorizeManage();
    }

    public function updatedKind(): void
    {
        $this->selectedProductIds = [];
        $this->resetPage(pageName: 'mediaCandidatesPage');
    }

    public function updatedKindFilter(): void
    {
        $this->resetPage(pageName: 'mediaRunsPage');
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage(pageName: 'mediaRunsPage');
    }

    public function clearSelection(): void
    {
        $this->authorizeManage();
        $this->selectedProductIds = [];
    }

    public function startSelected(MediaImportService $media): void
    {
        $this->authorizeManage();
        $this->validate([
            'kind' => ['required', Rule::in(self::KINDS)],
            'selectedProductIds' => ['required', 'array', 'min:1', 'max:100'],
            'selectedProductIds.*' => ['required', 'integer', 'distinct', 'min:1'],
        ], [
            'selectedProductIds.required' => __('Odaberite barem jedan artikl.'),
            'selectedProductIds.min' => __('Odaberite barem jedan artikl.'),
            'selectedProductIds.max' => __('Odaberite najviše 100 artikala po obradi.'),
        ]);
        if (! $this->ready($media)) {
            return;
        }
        $ids = array_map('intval', $this->selectedProductIds);
        $eligible = array_column($media->candidates($this->kind, 100)['products'], 'id');
        if (array_diff($ids, $eligible) !== []) {
            $this->addError('selectedProductIds', __('Odabir više ne odgovara prikazanim kandidatima. Osvježite pregled i ponovite odabir.'));

            return;
        }
        $this->queue($media, $ids);
    }

    public function startBatch(MediaImportService $media): void
    {
        $this->authorizeManage();
        $this->validate(['kind' => ['required', Rule::in(self::KINDS)]]);
        if (! $this->ready($media)) {
            return;
        }
        if ($media->candidates($this->kind, 100)['count'] === 0) {
            $this->dispatch('notify', type: 'warning', message: __('Nema artikala spremnih za ovu obradu slika.'));

            return;
        }
        $this->queue($media, null);
    }

    public function selectRun(int $runId): void
    {
        $this->authorizeManage();
        abort_unless(EracuniCatalogRun::query()->whereIn('kind', self::KINDS)->find($runId), 404);
        $this->selectedRunId = $runId;
        $this->resetPage(pageName: 'mediaItemsPage');
    }

    public function closeReport(): void
    {
        $this->authorizeManage();
        $this->selectedRunId = null;
    }

    public function retryReport(MediaImportService $media): void
    {
        $this->authorizeManage();
        $previous = EracuniCatalogRun::query()->whereIn('kind', self::KINDS)->find($this->selectedRunId);
        abort_unless($previous, 404);
        if (! in_array($previous->status, ['completed', 'failed'], true) || ! $previous->items()->whereIn('status', ['skipped', 'failed'])->exists()) {
            $this->dispatch('notify', type: 'warning', message: __('Ovaj izvještaj nema završenih stavki za ponovno pokretanje.'));

            return;
        }
        if (! $media->configured($previous->kind)) {
            $this->dispatch('notify', type: 'warning', message: __('Izvor slika nije podešen. Prvo treba podesiti dobavljačku integraciju.'));

            return;
        }
        try {
            $run = $media->retry((int) $previous->id, (int) auth()->id());
            $this->selectedRunId = (int) $run->id;
            $this->resetPage(pageName: 'mediaItemsPage');
            $this->dispatch('notify', type: 'success', message: __('Preskočene i neuspjele stavke stavljene su u red za ponovnu obradu.'));
        } catch (Throwable) {
            $this->dispatch('notify', type: 'error', message: __('Ponovnu obradu slika nije moguće pokrenuti. Provjerite izvor i je li obrada već u tijeku.'));
        }
    }

    public function render(MediaImportService $media)
    {
        $this->authorizeManage();
        $kind = in_array($this->kind, self::KINDS, true) ? $this->kind : 'images';
        $configured = $media->configured($kind);
        $candidateData = $configured ? $media->candidates($kind, 100) : ['count' => 0, 'products' => [], 'limit' => 100];
        $page = $this->getPage('mediaCandidatesPage');
        $candidates = new LengthAwarePaginator(
            array_slice($candidateData['products'], ($page - 1) * 25, 25),
            count($candidateData['products']),
            25,
            $page,
            ['pageName' => 'mediaCandidatesPage'],
        );
        $runs = EracuniCatalogRun::query()->whereIn('kind', self::KINDS)
            ->when(in_array($this->kindFilter, self::KINDS, true), fn (Builder $query) => $query->where('kind', $this->kindFilter))
            ->when(in_array($this->statusFilter, ['queued', 'running', 'completed', 'failed'], true), fn (Builder $query) => $query->where('status', $this->statusFilter))
            ->latest('id')->paginate(15, pageName: 'mediaRunsPage');
        $selectedRun = EracuniCatalogRun::query()->whereIn('kind', self::KINDS)->find($this->selectedRunId);
        $items = $selectedRun?->items()->orderBy('id')->paginate(25, pageName: 'mediaItemsPage');

        return view('livewire.admin.integrations.media.dashboard', [
            'configured' => $configured,
            'candidateCount' => (int) $candidateData['count'],
            'candidates' => $candidates,
            'runs' => $runs,
            'selectedRun' => $selectedRun,
            'items' => $items,
            'canRetry' => $selectedRun && in_array($selectedRun->status, ['completed', 'failed'], true) && $selectedRun->items()->whereIn('status', ['skipped', 'failed'])->exists(),
            'pollFrequently' => EracuniCatalogRun::query()->whereIn('kind', self::KINDS)->whereIn('status', ['queued', 'running'])->exists(),
            'kindLabels' => ['images' => __('e-Računi: glavne slike koje nedostaju'), 'braytron_images' => __('Braytron: dodatne slike galerije')],
            'statusLabels' => ['queued' => __('Na čekanju'), 'running' => __('U tijeku'), 'completed' => __('Završeno'), 'failed' => __('Neuspješno')],
            'itemStatusLabels' => ['queued' => __('Na čekanju'), 'running' => __('U tijeku'), 'imported' => __('Slike dodane'), 'unchanged' => __('Bez promjene'), 'skipped' => __('Preskočeno'), 'failed' => __('Neuspješno')],
        ]);
    }

    private function queue(MediaImportService $media, ?array $ids): void
    {
        try {
            $run = $media->start($this->kind, $ids, (int) auth()->id());
            $this->selectedRunId = (int) $run->id;
            $this->selectedProductIds = [];
            $this->resetPage(pageName: 'mediaItemsPage');
            $this->dispatch('notify', type: 'success', message: __('Obrada slika je stavljena u red. Napredak i rezultat prikazani su u izvještaju.'));
        } catch (Throwable) {
            $this->dispatch('notify', type: 'error', message: __('Obradu slika nije moguće pokrenuti. Provjerite izvor i je li obrada već u tijeku.'));
        }
    }

    private function ready(MediaImportService $media): bool
    {
        if ($media->configured($this->kind)) {
            return true;
        }
        $this->dispatch('notify', type: 'warning', message: __('Izvor slika nije podešen. Prvo treba podesiti dobavljačku integraciju.'));

        return false;
    }

    private function authorizeManage(): void
    {
        $user = auth()->user();
        abort_unless($user && (Bouncer::is($user)->an('superadmin') || $user->can('integrations.media.manage')), 403);
    }
}
