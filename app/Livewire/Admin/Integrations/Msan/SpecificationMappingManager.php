<?php

namespace App\Livewire\Admin\Integrations\Msan;

use App\Jobs\Integrations\Msan\RepublishMsanSpecificationDefinitionJob;
use App\Models\Integrations\Msan\MsanSpecificationDefinition;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Session;
use Livewire\Component;
use Livewire\WithPagination;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Throwable;

class SpecificationMappingManager extends Component
{
    use WithPagination;

    private const PAGE_NAME = 'msanSpecificationMappingsPage';

    #[Session(key: 'admin.msan.specifications.search')]
    public string $search = '';

    public string $searchInput = '';

    #[Session(key: 'admin.msan.specifications.import-state')]
    public string $importState = 'all';

    #[Session(key: 'admin.msan.specifications.stale-state')]
    public string $staleState = 'current';

    #[Session(key: 'admin.msan.specifications.role')]
    public string $role = 'all';

    public ?int $editingDefinitionId = null;

    public ?int $recordId = null;

    public bool $editPage = false;

    public string $editingDefinitionLabel = '';

    public bool $importEnabled = true;

    public bool $useAsFilter = false;

    public string $dataRole = MsanSpecificationDefinition::ROLE_SPECIFICATION;

    public string $displayGroupName = '';

    public string $displayItemName = '';

    public string $displayMeasure = '';

    public function mount(?int $recordId = null, bool $editPage = false): void
    {
        $this->authorizeView();

        $this->editPage = $editPage;
        $this->recordId = $recordId;
        $this->searchInput = $this->search;

        if ($this->editPage) {
            abort_unless($recordId !== null && $recordId > 0, 404);
            $this->openEditor($recordId);
        }
    }

    public function applySearch(): void
    {
        $search = trim(Str::limit($this->searchInput, 120, ''));

        if ($search !== '' && mb_strlen($search) < 2) {
            $this->addError('searchInput', __('Za pretragu unesite najmanje 2 znaka.'));

            return;
        }

        $this->resetErrorBag('searchInput');
        $this->searchInput = $search;
        $this->search = $search;
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updatedImportState(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updatedStaleState(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updatedRole(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->searchInput = '';
        $this->importState = 'all';
        $this->staleState = 'current';
        $this->role = 'all';
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function openEditor(int $definitionId): void
    {
        $this->authorizeManage();

        $definition = MsanSpecificationDefinition::query()->findOrFail($definitionId);
        $this->editingDefinitionId = (int) $definition->getKey();
        $this->recordId = (int) $definition->getKey();
        $this->editingDefinitionLabel = trim($definition->group_name.' / '.$definition->item_name, ' /');
        $this->importEnabled = (bool) $definition->import_enabled;
        $this->useAsFilter = (bool) $definition->use_as_filter;
        $this->dataRole = (string) $definition->data_role;
        $this->displayGroupName = (string) ($definition->display_group_name ?? '');
        $this->displayItemName = (string) ($definition->display_item_name ?? '');
        $this->displayMeasure = (string) ($definition->display_measure ?? '');
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->resetEditor();
    }

    public function saveDefinition()
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'editingDefinitionId' => ['required', 'integer', Rule::exists('msan_specification_definitions', 'id')],
            'importEnabled' => ['required', 'boolean'],
            'useAsFilter' => ['required', 'boolean'],
            'dataRole' => ['required', 'string', Rule::in(array_keys(MsanSpecificationDefinition::roleOptions()))],
            'displayGroupName' => ['nullable', 'string', 'max:255'],
            'displayItemName' => ['nullable', 'string', 'max:255'],
            'displayMeasure' => ['nullable', 'string', 'max:100'],
        ]);

        $definitionId = (int) $validated['editingDefinitionId'];
        MsanSpecificationDefinition::query()
            ->whereKey($definitionId)
            ->update([
                'import_enabled' => (bool) $validated['importEnabled'],
                'use_as_filter' => (bool) $validated['useAsFilter'],
                'data_role' => (string) $validated['dataRole'],
                'display_group_name' => $this->nullableText((string) ($validated['displayGroupName'] ?? '')),
                'display_item_name' => $this->nullableText((string) ($validated['displayItemName'] ?? '')),
                'display_measure' => $this->nullableText((string) ($validated['displayMeasure'] ?? '')),
                'updated_by' => auth()->id(),
            ]);

        $this->resetEditor();
        $notificationType = 'success';
        $notificationMessage = __('Pravilo je spremljeno, a lokalna primjena na postojeće artikle stavljena je u red.');

        try {
            RepublishMsanSpecificationDefinitionJob::dispatch($definitionId)->onQueue('integrations');
        } catch (Throwable $exception) {
            report($exception);
            $notificationType = 'warning';
            $notificationMessage = __('Pravilo je spremljeno, ali lokalnu primjenu nije moguće staviti u red. Pokrenite je ponovnim spremanjem.');
        }

        if ($this->editPage) {
            return redirect()
                ->route('admin.integrations.msan.specifications')
                ->with('notify', [
                    'type' => $notificationType,
                    'message' => $notificationMessage,
                ]);
        }

        $this->dispatch('notify', type: $notificationType, message: $notificationMessage);
    }

    public function render()
    {
        $this->authorizeView();

        $perPage = app(SystemSettingsService::class)->getInt(
            'admin_items_per_page',
            (int) config('admin_ui.pagination.admin_items_per_page', 20),
            5,
            200,
        );

        $definitions = $this->filteredQuery()
            ->orderBy('is_stale')
            ->orderBy('group_name')
            ->orderBy('item_name')
            ->orderBy('id')
            ->paginate($perPage, pageName: self::PAGE_NAME);

        return view('livewire.admin.integrations.msan.specification-mapping-manager', [
            'definitions' => $definitions,
            'roleOptions' => MsanSpecificationDefinition::roleOptions(),
            'canManageMapping' => $this->canManage(),
            'perPage' => $perPage,
        ]);
    }

    private function filteredQuery(): Builder
    {
        $search = trim(Str::limit($this->search, 120, ''));

        return MsanSpecificationDefinition::query()
            ->select([
                'id',
                'source_key',
                'group_name',
                'item_name',
                'measure',
                'display_group_name',
                'display_item_name',
                'display_measure',
                'source_for_filter',
                'import_enabled',
                'use_as_filter',
                'data_role',
                'sample_values',
                'product_count',
                'last_seen_at',
                'is_stale',
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $prefix = $search.'%';
                $query->where(function (Builder $nested) use ($prefix): void {
                    $nested
                        ->where('source_key', 'like', $prefix)
                        ->orWhere('group_name', 'like', $prefix)
                        ->orWhere('item_name', 'like', $prefix);
                });
            })
            ->when($this->importState === 'enabled', fn (Builder $query) => $query->where('import_enabled', true))
            ->when($this->importState === 'disabled', fn (Builder $query) => $query->where('import_enabled', false))
            ->when($this->staleState === 'current', fn (Builder $query) => $query->where('is_stale', false))
            ->when($this->staleState === 'stale', fn (Builder $query) => $query->where('is_stale', true))
            ->when(
                $this->role !== 'all' && array_key_exists($this->role, MsanSpecificationDefinition::roleOptions()),
                fn (Builder $query) => $query->where('data_role', $this->role),
            );
    }

    private function nullableText(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function resetEditor(): void
    {
        $this->editingDefinitionId = null;
        $this->recordId = null;
        $this->editingDefinitionLabel = '';
        $this->importEnabled = true;
        $this->useAsFilter = false;
        $this->dataRole = MsanSpecificationDefinition::ROLE_SPECIFICATION;
        $this->displayGroupName = '';
        $this->displayItemName = '';
        $this->displayMeasure = '';
        $this->resetValidation();
    }

    private function authorizeView(): void
    {
        abort_unless($this->canView(), 403);
    }

    private function authorizeManage(): void
    {
        abort_unless($this->canManage(), 403);
    }

    private function canView(): bool
    {
        $user = auth()->user();

        return (bool) ($user && (Bouncer::is($user)->an('superadmin') || $user->can('integrations.msan.view')));
    }

    private function canManage(): bool
    {
        $user = auth()->user();

        return (bool) ($user && (Bouncer::is($user)->an('superadmin') || $user->can('integrations.msan.mapping.manage')));
    }
}
