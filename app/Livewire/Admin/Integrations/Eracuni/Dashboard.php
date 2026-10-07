<?php

namespace App\Livewire\Admin\Integrations\Eracuni;

use App\Models\Integrations\Eracuni\EracuniCatalogRun;
use App\Services\Integrations\Eracuni\EracuniCatalogService;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Throwable;

class Dashboard extends Component
{
    use WithFileUploads;
    use WithPagination;

    private const CATALOG_KINDS = ['preview', 'preview_csv', 'import', 'import_csv', 'attributes', 'preview_prices', 'preview_names', 'prices', 'names'];

    #[Locked]
    public ?int $previewRunId = null;

    #[Locked]
    public ?int $selectedRunId = null;

    public array $selectedIds = [];

    public string $candidateFilter = 'all';

    public string $kindFilter = 'all';

    public string $statusFilter = 'all';

    public string $rangePreset = 'all';

    public string $codeFrom = '';

    public string $codeTo = '';

    public $supplierUpload = null;

    public function mount(): void
    {
        $this->authorizeManage();
    }

    public function updatedCandidateFilter(): void
    {
        $this->resetPage(pageName: 'eracuniPreviewPage');
    }

    public function updatedKindFilter(): void
    {
        $this->resetPage(pageName: 'eracuniRunsPage');
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage(pageName: 'eracuniRunsPage');
    }

    public function clearSelection(): void
    {
        $this->authorizeManage();
        $this->selectedIds = [];
    }

    public function updatedRangePreset(): void
    {
        $ranges = ['all' => ['', ''], 'first' => ['0', '10000'], 'second' => ['10001', '20000'], 'third' => ['20001', '30000']];
        if (isset($ranges[$this->rangePreset])) {
            [$this->codeFrom, $this->codeTo] = $ranges[$this->rangePreset];
        }
        $this->previewRunId = null;
        $this->selectedIds = [];
    }

    public function updatedCodeFrom(): void
    {
        $this->rangePreset = 'custom';
        $this->previewRunId = null;
        $this->selectedIds = [];
    }

    public function updatedCodeTo(): void
    {
        $this->updatedCodeFrom();
    }

    public function updatedSupplierUpload(): void
    {
        $this->previewRunId = null;
        $this->selectedIds = [];
    }

    public function previewSupplierCsv(EracuniCatalogService $catalog): void
    {
        $this->authorizeManage();
        $this->validate(['supplierUpload' => ['required', 'file', 'max:10240', 'mimes:csv,txt', 'extensions:csv']], [
            'supplierUpload.required' => __('Odaberite IDEUS CSV datoteku.'),
            'supplierUpload.max' => __('Datoteka može imati najviše 10 MB.'),
            'supplierUpload.extensions' => __('Dopuštena je CSV datoteka.'),
        ]);
        try {
            $run = $catalog->previewSupplierCsv($this->supplierUpload->getRealPath(), (int) auth()->id());
            $this->selectedRunId = (int) $run->id;
            $this->previewRunId = $run->status === 'completed' ? (int) $run->id : null;
            $this->selectedIds = [];
            $this->candidateFilter = 'all';
            $this->resetPage(pageName: 'eracuniPreviewPage');
            $this->resetPage(pageName: 'eracuniItemsPage');
            $this->notifyRun($run, __('IDEUS CSV pregled je spreman. Provjerite nove artikle i odaberite nacrte za uvoz.'));
        } catch (Throwable) {
            $this->dispatch('notify', type: 'error', message: __('CSV pregled nije moguće pripremiti. Provjerite datoteku i podržani raspored stupaca.'));
        }
    }

    public function previewCatalog(EracuniCatalogService $catalog, StockSyncSettingsService $settings): void
    {
        $this->authorizeManage();
        if (! $this->ready($settings)) {
            return;
        }

        [$from, $to] = $this->validatedRange();
        try {
            $run = $from === null && $to === null
                ? $catalog->preview((int) auth()->id())
                : $catalog->preview((int) auth()->id(), $from, $to);
            $this->selectedRunId = (int) $run->id;
            if ($run->status === 'completed') {
                $this->previewRunId = (int) $run->id;
                $this->selectedIds = [];
                $this->candidateFilter = 'all';
                $this->resetPage(pageName: 'eracuniPreviewPage');
            }
            $this->resetPage(pageName: 'eracuniItemsPage');
            $this->notifyRun($run, __('Pregled novih artikala je spreman. Odaberite artikle za uvoz.'));
        } catch (Throwable) {
            $this->notifyFailure();
        }
    }

    public function importSelected(EracuniCatalogService $catalog, StockSyncSettingsService $settings): void
    {
        $this->authorizeManage();
        $preview = EracuniCatalogRun::query()->whereIn('kind', ['preview', 'preview_csv'])->where('status', 'completed')->find($this->previewRunId);
        if ($preview?->kind !== 'preview_csv' && ! $this->ready($settings)) {
            return;
        }
        $this->validate([
            'selectedIds' => ['required', 'array', 'min:1', 'max:500'],
            'selectedIds.*' => ['required', 'integer', 'distinct', 'min:1'],
        ], [
            'selectedIds.required' => __('Odaberite barem jedan artikl za uvoz.'),
            'selectedIds.min' => __('Odaberite barem jedan artikl za uvoz.'),
            'selectedIds.max' => __('Odaberite najviše 500 artikala po uvozu.'),
        ]);

        if (! $preview) {
            $this->addError('selectedIds', __('Prvo napravite uspješan pregled novih artikala.'));

            return;
        }
        if (data_get($preview->summary, 'limit_reached', false)) {
            $this->addError('selectedIds', __('Pregled je dosegao ERP ograničenje. Suzite raspon šifri i pripremite novi pregled prije uvoza.'));

            return;
        }
        $ids = array_map('intval', $this->selectedIds);
        if ($preview->items()->where('status', 'new')->whereIn('id', $ids)->count() !== count($ids)) {
            $this->addError('selectedIds', __('Uvoz je dopušten samo za nove artikle iz odabranog pregleda. Ponovno provjerite odabir.'));

            return;
        }

        try {
            $run = $catalog->import((int) $preview->id, $ids, (int) auth()->id());
            $this->selectedRunId = (int) $run->id;
            $this->selectedIds = [];
            $this->resetPage(pageName: 'eracuniItemsPage');
            $this->notifyRun($run, __('Odabrani artikli uvezeni su kao neaktivni nacrti. Pregledajte ih prije objave.'));
        } catch (Throwable) {
            $this->notifyFailure();
        }
    }

    public function previewUpdates(string $operation, EracuniCatalogService $catalog, StockSyncSettingsService $settings): void
    {
        $this->authorizeManage();
        abort_unless(in_array($operation, ['prices', 'names'], true), 404);
        if (! $this->ready($settings)) {
            return;
        }

        [$from, $to] = $this->validatedRange();
        try {
            $run = $from === null && $to === null
                ? $catalog->previewUpdates($operation, (int) auth()->id())
                : $catalog->previewUpdates($operation, (int) auth()->id(), $from, $to);
            $this->selectedRunId = (int) $run->id;
            if ($run->status === 'completed') {
                $this->previewRunId = (int) $run->id;
                $this->selectedIds = [];
                $this->candidateFilter = 'all';
                $this->resetPage(pageName: 'eracuniPreviewPage');
            }
            $this->resetPage(pageName: 'eracuniItemsPage');
            $this->notifyRun($run, __('Prijedlog promjena je spreman. Pregledajte stare i nove vrijednosti i odaberite promjene za primjenu.'));
        } catch (Throwable) {
            $this->notifyFailure();
        }
    }

    public function applySelectedUpdates(EracuniCatalogService $catalog, StockSyncSettingsService $settings): void
    {
        $this->authorizeManage();
        if (! $this->ready($settings)) {
            return;
        }
        $this->validate([
            'selectedIds' => ['required', 'array', 'min:1', 'max:500'],
            'selectedIds.*' => ['required', 'integer', 'distinct', 'min:1'],
        ], [
            'selectedIds.required' => __('Odaberite barem jednu promjenu.'),
            'selectedIds.min' => __('Odaberite barem jednu promjenu.'),
            'selectedIds.max' => __('Odaberite najviše 500 promjena po obradi.'),
        ]);
        $preview = EracuniCatalogRun::query()->whereIn('kind', ['preview_prices', 'preview_names'])
            ->where('status', 'completed')->find($this->previewRunId);
        $ids = array_map('intval', $this->selectedIds);
        if (! $preview || $preview->items()->where('status', 'update')->whereIn('id', $ids)->count() !== count($ids)) {
            $this->addError('selectedIds', __('Primjena je dopuštena samo za odabrane promjene iz uspješnog pregleda. Ponovno provjerite odabir.'));

            return;
        }
        if (data_get($preview->summary, 'limit_reached', false)) {
            $this->addError('selectedIds', __('Pregled je dosegao ERP ograničenje. Suzite raspon šifri i pripremite novi pregled prije primjene.'));

            return;
        }

        try {
            $run = $catalog->applyUpdates((int) $preview->id, $ids, (int) auth()->id());
            $this->selectedRunId = (int) $run->id;
            $this->selectedIds = [];
            $this->resetPage(pageName: 'eracuniItemsPage');
            $this->notifyRun($run, __('Odabrane ERP promjene su obrađene. Izvještaj prikazuje primijenjene i preskočene promjene.'));
        } catch (Throwable) {
            $this->notifyFailure();
        }
    }

    public function syncAttributes(EracuniCatalogService $catalog, StockSyncSettingsService $settings): void
    {
        $this->authorizeManage();
        if (! $this->ready($settings)) {
            return;
        }

        [$from, $to] = $this->validatedRange();
        try {
            $run = $from === null && $to === null
                ? $catalog->syncAttributes((int) auth()->id())
                : $catalog->syncAttributes((int) auth()->id(), $from, $to);
            $this->selectedRunId = (int) $run->id;
            $this->resetPage(pageName: 'eracuniItemsPage');
            $this->notifyRun($run, __('ERP svojstva postojećih artikala su osvježena. Izvještaj prikazuje obrađene artikle.'));
        } catch (Throwable) {
            $this->notifyFailure();
        }
    }

    public function selectRun(int $runId): void
    {
        $this->authorizeManage();
        $run = EracuniCatalogRun::query()->whereIn('kind', self::CATALOG_KINDS)->find($runId);
        abort_unless($run, 404);
        $this->selectedRunId = (int) $run->id;
        $this->resetPage(pageName: 'eracuniItemsPage');
        if (in_array($run->kind, ['preview', 'preview_csv', 'preview_prices', 'preview_names'], true) && $run->status === 'completed') {
            $this->previewRunId = (int) $run->id;
            $this->selectedIds = [];
            $this->candidateFilter = 'all';
            $this->codeFrom = (string) data_get($run->summary, 'code_from', '');
            $this->codeTo = (string) data_get($run->summary, 'code_to', '');
            $this->rangePreset = $this->codeFrom === '' && $this->codeTo === '' ? 'all' : 'custom';
            $this->resetPage(pageName: 'eracuniPreviewPage');
        }
    }

    public function closeReport(): void
    {
        $this->authorizeManage();
        $this->selectedRunId = null;
    }

    public function toggleAttributesCron(bool $enabled, StockSyncSettingsService $settings): void
    {
        $this->authorizeManage();
        if ($enabled && ! $this->ready($settings)) {
            return;
        }
        $settings->saveAttributesEnabled($enabled);
        $this->dispatch('notify', type: 'success', message: $enabled
            ? __('Automatsko osvježavanje ERP svojstava je uključeno. Provjerite URL i raspored u EasyCronu.')
            : __('Automatsko osvježavanje ERP svojstava je isključeno.'));
    }

    public function render(StockSyncSettingsService $settings)
    {
        $this->authorizeManage();
        $preview = EracuniCatalogRun::query()->whereIn('kind', ['preview', 'preview_csv', 'preview_prices', 'preview_names'])
            ->where('status', 'completed')->find($this->previewRunId);
        $candidates = $preview?->items()
            ->whereNotIn('status', ['existing', 'unchanged'])
            ->when(in_array($this->candidateFilter, ['new', 'update', 'updated', 'invalid', 'ambiguous', 'skipped', 'created'], true), fn (Builder $query) => $query->where('status', $this->candidateFilter))
            ->orderBy('id')->paginate(25, pageName: 'eracuniPreviewPage');
        $selectedRun = EracuniCatalogRun::query()->whereIn('kind', self::CATALOG_KINDS)->find($this->selectedRunId);
        $items = $selectedRun?->items()->orderBy('id')->paginate(25, pageName: 'eracuniItemsPage');
        $runs = EracuniCatalogRun::query()->whereIn('kind', self::CATALOG_KINDS)
            ->when(in_array($this->kindFilter, self::CATALOG_KINDS, true), fn (Builder $query) => $query->where('kind', $this->kindFilter))
            ->when(in_array($this->statusFilter, ['completed', 'failed', 'running'], true), fn (Builder $query) => $query->where('status', $this->statusFilter))
            ->latest('id')->paginate(15, pageName: 'eracuniRunsPage');

        return view('livewire.admin.integrations.eracuni.dashboard', [
            'configured' => $settings->configured('eracuni'),
            'attributesCronEnabled' => $settings->attributesEnabled(),
            'attributesCronUrl' => $settings->attributesCronUrl(trim($this->codeFrom) !== '' ? trim($this->codeFrom) : null, trim($this->codeTo) !== '' ? trim($this->codeTo) : null),
            'lastAttributesCronAttempt' => EracuniCatalogRun::query()->where('kind', 'attributes')->where('summary->trigger', 'cron')->latest('id')->first(),
            'lastAttributesCronSuccess' => EracuniCatalogRun::query()->where('kind', 'attributes')->where('summary->trigger', 'cron')->where('status', 'completed')->latest('id')->first(),
            'previewRun' => $preview,
            'candidates' => $candidates,
            'selectedRun' => $selectedRun,
            'items' => $items,
            'runs' => $runs,
            'kindLabels' => ['preview' => __('Pregled novih artikala'), 'preview_csv' => __('Pregled IDEUS CSV'), 'import' => __('Uvoz nacrta'), 'import_csv' => __('Uvoz IDEUS nacrta'), 'attributes' => __('Svojstva postojećih artikala'),
                'preview_prices' => __('Pregled promjena cijena'), 'preview_names' => __('Pregled promjena naziva'), 'prices' => __('Promjene cijena'), 'names' => __('Promjene naziva')],
            'statusLabels' => ['completed' => __('Završeno'), 'failed' => __('Neuspješno'), 'running' => __('U tijeku')],
            'itemStatusLabels' => ['existing' => __('Postojeći artikl'), 'new' => __('Spreman za uvoz nacrta'), 'created' => __('Uvezen nacrt'), 'update' => __('Spremno za primjenu'), 'updated' => __('Ažurirano'), 'unchanged' => __('Bez promjene'), 'skipped' => __('Preskočeno'), 'invalid' => __('Nevaljani podaci'), 'ambiguous' => __('Više podudaranja')],
        ]);
    }

    private function ready(StockSyncSettingsService $settings): bool
    {
        if ($settings->configured('eracuni')) {
            return true;
        }
        $this->dispatch('notify', type: 'warning', message: __('Veza s e-Računima nije podešena. Obrada će biti dostupna nakon podešavanja.'));

        return false;
    }

    private function validatedRange(): array
    {
        $this->validate(['codeFrom' => ['string', 'max:120'], 'codeTo' => ['string', 'max:120']]);

        return [trim($this->codeFrom) !== '' ? trim($this->codeFrom) : null, trim($this->codeTo) !== '' ? trim($this->codeTo) : null];
    }

    private function notifyRun(EracuniCatalogRun $run, string $message): void
    {
        if ($run->status === 'completed' && data_get($run->summary, 'limit_reached', false)) {
            $this->dispatch('notify', type: 'warning', message: __('ERP je vratio ograničen skup artikala. Pogledajte upozorenje u izvještaju i suzite raspon šifri.'));

            return;
        }
        $this->dispatch('notify', type: $run->status === 'completed' ? 'success' : 'error', message: $run->status === 'completed'
            ? $message
            : __('ERP obrada nije uspjela. Pogledajte izvještaj izvršavanja.'));
    }

    private function notifyFailure(): void
    {
        $this->dispatch('notify', type: 'error', message: __('ERP obradu nije moguće pokrenuti. Provjerite vezu i je li obrada već u tijeku.'));
    }

    private function authorizeManage(): void
    {
        $user = auth()->user();
        abort_unless($user && (Bouncer::is($user)->an('superadmin') || $user->can('integrations.eracuni.manage')), 403);
    }
}
