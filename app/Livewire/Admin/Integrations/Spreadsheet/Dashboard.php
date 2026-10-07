<?php

namespace App\Livewire\Admin\Integrations\Spreadsheet;

use App\Models\Integrations\Spreadsheet\SpreadsheetImportRun;
use App\Models\Settings\Local\Currency;
use App\Services\Integrations\Spreadsheet\SpreadsheetImportService;
use App\Services\Pricing\TaxPricingService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
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

    public $upload = null;

    public array $options = [];

    #[Locked]
    public ?int $previewRunId = null;

    #[Locked]
    public ?int $selectedRunId = null;

    public string $statusFilter = 'all';

    public string $itemFilter = 'all';

    public function mount(): void
    {
        $this->authorizeManage();
        $this->options = SpreadsheetImportService::defaultOptions();
    }

    public function updatedOptions(): void
    {
        $this->previewRunId = null;
    }

    public function updatedUpload(): void
    {
        $this->previewRunId = null;
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage(pageName: 'spreadsheetRunsPage');
    }

    public function updatedItemFilter(): void
    {
        $this->resetPage(pageName: 'spreadsheetItemsPage');
    }

    public function previewFile(SpreadsheetImportService $importer): void
    {
        $this->authorizeManage();
        $this->validate([
            'upload' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt', 'extensions:xlsx,xls,csv'],
            'options.enabled' => ['required', 'boolean'],
            'options.first_row' => ['required', 'integer', 'min:1', 'max:1048576'],
            'options.identifier_type' => ['required', 'string', Rule::in(['model', 'sku', 'ean', 'product_id', 'legacy_product_id'])],
            'options.identifier_column' => ['required', 'integer', 'min:1', 'max:16384'],
            'options.update_prices' => ['required', 'boolean'],
            'options.price_column' => ['required', 'integer', 'min:1', 'max:16384'],
            'options.markup_percentage' => ['required', 'numeric', 'min:0', 'max:10000'],
            'options.update_stock' => ['required', 'boolean'],
            'options.stock_column' => ['required', 'integer', 'min:1', 'max:16384'],
            'options.update_supplier_stock' => ['required', 'boolean'],
            'options.supplier_stock_column' => ['required', 'integer', 'min:1', 'max:16384'],
        ], [
            'upload.required' => __('Odaberite Excel ili CSV datoteku.'),
            'upload.max' => __('Datoteka može imati najviše 10 MB.'),
            'upload.extensions' => __('Dopuštene su XLSX, XLS i CSV datoteke.'),
        ]);
        $this->options = $this->canonicalOptions($this->options);
        if (! $this->options['enabled']) {
            $this->addError('options.enabled', __('Uključite obradu datoteke prije pripreme pregleda.'));

            return;
        }
        if (! $this->options['update_prices'] && ! $this->options['update_stock'] && ! $this->options['update_supplier_stock']) {
            $this->addError('options.update_prices', __('Odaberite barem jedan podatak za osvježavanje.'));

            return;
        }

        try {
            $this->resetValidation('preview');
            $run = $importer->preview($this->upload->getRealPath(), $this->options, (int) auth()->id());
            $this->selectedRunId = (int) $run->id;
            $this->previewRunId = $run->status === 'preview' ? (int) $run->id : null;
            $this->itemFilter = 'all';
            $this->resetPage(pageName: 'spreadsheetItemsPage');
            $this->dispatch('notify', type: $run->status === 'preview' ? 'success' : 'error', message: $run->status === 'preview'
                ? __('Pregled datoteke je spreman. Provjerite promjene prije primjene.')
                : __('Datoteku nije moguće obraditi. Pogledajte izvještaj.'));
        } catch (Throwable) {
            $this->dispatch('notify', type: 'error', message: __('Pregled datoteke nije moguće pripremiti. Provjerite datoteku i mapiranje stupaca.'));
        }
    }

    public function applyPreview(SpreadsheetImportService $importer): void
    {
        $this->authorizeManage();
        $run = SpreadsheetImportRun::query()->where('kind', 'preview')->where('status', 'preview')->find($this->previewRunId);
        if (! $run || (int) $run->invalid_count > 0 || (int) $run->conflict_count > 0 || (int) $run->total_count === 0) {
            $this->addError('preview', __('Primjena zahtijeva aktivan pregled bez nevaljanih redova i sukoba. Ispravite datoteku i ponovno pripremite pregled.'));

            return;
        }

        try {
            $result = $importer->apply((int) $run->id, (int) auth()->id());
            $this->selectedRunId = (int) $result->id;
            $this->previewRunId = null;
            $this->resetPage(pageName: 'spreadsheetItemsPage');
            $this->dispatch('notify', type: $result->status === 'completed' ? 'success' : 'error', message: $result->status === 'completed'
                ? __('Promjene iz datoteke su primijenjene. Pogledajte izvještaj obrade.')
                : __('Promjene nisu primijenjene. Pogledajte izvještaj i pripremite novi pregled.'));
        } catch (Throwable) {
            $this->dispatch('notify', type: 'error', message: __('Promjene nije moguće primijeniti. Provjerite izvještaj i pripremite novi pregled.'));
        }
    }

    public function selectRun(int $runId): void
    {
        $this->authorizeManage();
        $run = SpreadsheetImportRun::query()->findOrFail($runId);
        $this->selectedRunId = (int) $run->id;
        $this->previewRunId = $run->kind === 'preview' && $run->status === 'preview' ? (int) $run->id : null;
        if ($this->previewRunId !== null) {
            $this->options = $this->canonicalOptions($run->options ?? []);
        }
        $this->itemFilter = 'all';
        $this->resetValidation();
        $this->resetPage(pageName: 'spreadsheetItemsPage');
    }

    public function closeReport(): void
    {
        $this->authorizeManage();
        $this->selectedRunId = null;
        $this->previewRunId = null;
    }

    public function render()
    {
        $this->authorizeManage();
        $selectedRun = SpreadsheetImportRun::query()->find($this->selectedRunId);
        $items = $selectedRun?->items()
            ->when(in_array($this->itemFilter, ['ready', 'invalid', 'unmatched', 'conflict', 'unchanged', 'applied'], true), fn (Builder $query) => $query->where('status', $this->itemFilter))
            ->orderBy('row_number')->paginate(25, pageName: 'spreadsheetItemsPage');
        $runs = SpreadsheetImportRun::query()
            ->when(in_array($this->statusFilter, ['preview', 'completed', 'failed'], true), fn (Builder $query) => $query->where('status', $this->statusFilter))
            ->latest('id')->paginate(15, pageName: 'spreadsheetRunsPage');

        return view('livewire.admin.integrations.spreadsheet.dashboard', [
            'runs' => $runs,
            'selectedRun' => $selectedRun,
            'items' => $items,
            'canApply' => $selectedRun && $this->previewRunId === $selectedRun->id
                && $selectedRun->kind === 'preview' && $selectedRun->status === 'preview'
                && (int) $selectedRun->invalid_count === 0 && (int) $selectedRun->conflict_count === 0 && (int) $selectedRun->total_count > 0,
            'statusLabels' => ['preview' => __('Pregled spreman'), 'completed' => __('Primijenjeno'), 'failed' => __('Neuspješno')],
            'itemStatusLabels' => ['ready' => __('Spremno za primjenu'), 'invalid' => __('Nevaljani podaci'), 'unmatched' => __('Artikl nije pronađen'), 'conflict' => __('Sukob podataka'), 'unchanged' => __('Bez promjene'), 'applied' => __('Primijenjeno')],
            'identifierLabels' => ['model' => __('Model / ERP šifra'), 'sku' => __('SKU'), 'ean' => __('EAN / barkod'), 'product_id' => __('ID artikla na novoj stranici'), 'legacy_product_id' => __('ID artikla na staroj stranici')],
            'valueLabels' => ['base_price' => __('Pohranjena cijena'), 'stock_qty' => __('Vlastita zaliha'), 'supplier_stock_qty' => __('Zaliha dobavljača')],
            'storeCurrency' => strtoupper((string) (Currency::query()->where('is_active', true)
                ->orderByDesc('is_default')->orderBy('sort_order')->orderBy('id')->value('code')
                ?: app(SystemSettingsService::class)->get('store_schema_product_currency', 'EUR'))),
            'pricesIncludeTax' => app(TaxPricingService::class)->pricesIncludeTax(),
        ]);
    }

    private function canonicalOptions(array $options): array
    {
        $options = array_replace(SpreadsheetImportService::defaultOptions(), Arr::only($options, array_keys(SpreadsheetImportService::defaultOptions())));
        foreach (['enabled', 'update_prices', 'update_stock', 'update_supplier_stock'] as $key) {
            $options[$key] = (bool) $options[$key];
        }
        foreach (['first_row', 'identifier_column', 'price_column', 'stock_column', 'supplier_stock_column'] as $key) {
            $options[$key] = (int) $options[$key];
        }
        $options['markup_percentage'] = (float) $options['markup_percentage'];

        return $options;
    }

    private function authorizeManage(): void
    {
        $user = auth()->user();
        abort_unless($user && (Bouncer::is($user)->an('superadmin') || $user->can('integrations.spreadsheet.manage')), 403);
    }
}
