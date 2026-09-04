<?php

namespace App\Livewire\Admin\Integrations\Msan;

use App\Models\Catalog\Category\Category;
use App\Models\Integrations\Msan\MsanCategory;
use App\Models\Integrations\Msan\MsanCategoryMapping;
use App\Models\Integrations\Msan\MsanProduct;
use App\Services\Integrations\Msan\EprelClient;
use App\Services\Integrations\Msan\MsanCategoryBranchImportService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Session;
use Livewire\Component;
use Livewire\WithPagination;
use Silber\Bouncer\BouncerFacade as Bouncer;

class CategoryMappingManager extends Component
{
    use WithPagination;

    private const PAGE_NAME = 'msanCategoryMappingsPage';

    #[Session(key: 'admin.msan.categories.search')]
    public string $search = '';

    public string $searchInput = '';

    #[Session(key: 'admin.msan.categories.status')]
    public string $status = 'unmapped';

    #[Session(key: 'admin.msan.categories.with-products-only')]
    public bool $withProductsOnly = false;

    public ?int $editingCategoryId = null;

    public string $localCategoryId = '';

    public string $branchImportParentId = '';

    public string $eprelProductGroup = '';

    public string $energyRequirement = MsanCategoryMapping::ENERGY_REQUIREMENT_INHERIT;

    public function mount(): void
    {
        $this->authorizeView();

        $requestedStatus = (string) request()->query('status', '');
        if (in_array($requestedStatus, ['all', 'unmapped', 'mapped', 'ignored'], true)) {
            $this->status = $requestedStatus;
        }

        $this->searchInput = $this->search;
    }

    public function updatedSearch(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
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

    public function updatedStatus(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updatedWithProductsOnly(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->searchInput = '';
        $this->status = 'all';
        $this->withProductsOnly = false;
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->searchInput = '';
        $this->resetErrorBag('searchInput');
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function openEditor(int $categoryId): void
    {
        $this->authorizeManage();

        $category = MsanCategory::query()
            ->with('mapping:id,msan_category_id,local_category_id,status,eprel_product_group,energy_requirement')
            ->findOrFail($categoryId);

        $this->editingCategoryId = (int) $category->getKey();
        $this->localCategoryId = $category->mapping?->local_category_id
            ? (string) $category->mapping->local_category_id
            : '';
        $this->branchImportParentId = $this->mappedLocalParentId($category);
        $this->eprelProductGroup = (string) ($category->mapping?->eprel_product_group ?? '');
        $this->energyRequirement = (string) ($category->mapping?->energy_requirement
            ?? MsanCategoryMapping::ENERGY_REQUIREMENT_INHERIT);
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->resetEditor();
    }

    public function saveMapping(): void
    {
        $this->persistMapping(false);
    }

    public function saveMappingAndContinue(): void
    {
        $this->persistMapping(true);
    }

    public function importCategoryBranch(MsanCategoryBranchImportService $importer): void
    {
        $this->authorizeManage();

        if ($this->editingCategoryId === null || $this->localCategoryId !== '') {
            return;
        }

        $category = MsanCategory::query()
            ->where('is_stale', false)
            ->findOrFail($this->editingCategoryId);
        $parentId = $this->branchImportParentId !== '' ? (int) $this->branchImportParentId : null;

        try {
            $result = $importer->import(
                $category,
                auth()->id(),
                'hr',
                $this->preferredLocales(),
                $parentId,
            );
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('categoryBranchImport', __('Kategorije nije moguće uvesti. Ništa nije promijenjeno; pokušajte ponovno.'));

            return;
        }

        $this->forgetDashboardCounts();
        $this->resetEditor();
        $this->dispatch(
            'notify',
            type: 'success',
            message: __('Uvezeno i mapirano :count kategorija (:descendants podkategorija).', [
                'count' => (int) ($result['mapped_count'] ?? $result['category_count'] ?? 0),
                'descendants' => (int) ($result['descendant_count'] ?? 0),
            ]),
        );
    }

    private function persistMapping(bool $continue): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'editingCategoryId' => ['required', 'integer', Rule::exists('msan_categories', 'id')],
            'localCategoryId' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where(
                    static fn ($query) => $query->where('scope', Category::SCOPE_CATALOG)
                ),
            ],
            'eprelProductGroup' => [
                'nullable',
                'string',
                'max:100',
                Rule::in(array_keys(EprelClient::productGroupOptions())),
            ],
            'energyRequirement' => [
                'required',
                'string',
                Rule::in(array_keys(MsanCategoryMapping::energyRequirementOptions())),
            ],
        ]);

        $categoryId = (int) $validated['editingCategoryId'];
        $eprelProductGroup = $this->nullableText((string) ($validated['eprelProductGroup'] ?? ''));
        $energyRequirement = (string) $validated['energyRequirement'];
        DB::transaction(function () use ($categoryId, $eprelProductGroup, $energyRequirement, $validated): void {
            $mapping = MsanCategoryMapping::query()->where('msan_category_id', $categoryId)->first();
            $eprelMappingChanged = ($mapping?->eprel_product_group ?? null) !== $eprelProductGroup
                || (string) ($mapping?->energy_requirement ?? MsanCategoryMapping::ENERGY_REQUIREMENT_INHERIT) !== $energyRequirement;

            MsanCategoryMapping::query()->updateOrCreate(
                ['msan_category_id' => $categoryId],
                [
                    'local_category_id' => (int) $validated['localCategoryId'],
                    'status' => 'mapped',
                    'eprel_product_group' => $eprelProductGroup,
                    'energy_requirement' => $energyRequirement,
                    'updated_by' => auth()->id(),
                ],
            );

            if ($eprelMappingChanged) {
                $this->resetEprelStateForCategory($categoryId);
            }
        });
        $this->forgetDashboardCounts();

        if ($continue) {
            $nextCategoryId = $this->nextUnmappedCategoryId($categoryId);
            if ($nextCategoryId !== null) {
                $this->openEditor($nextCategoryId);
                $this->dispatch('notify', type: 'success', message: __('Mapiranje je spremljeno. Otvorena je sljedeća nemapirana kategorija.'));

                return;
            }
        }

        $this->resetEditor();
        $this->dispatch(
            'notify',
            type: 'success',
            message: $continue
                ? __('Mapiranje je spremljeno. Nema više nemapiranih kategorija.')
                : __('M SAN kategorija je mapirana.'),
        );
    }

    public function ignoreCategory(int $categoryId): void
    {
        $this->authorizeManage();
        MsanCategory::query()->findOrFail($categoryId);

        MsanCategoryMapping::query()->updateOrCreate(
            ['msan_category_id' => $categoryId],
            [
                'local_category_id' => null,
                'status' => 'ignored',
                'eprel_product_group' => null,
                'energy_requirement' => MsanCategoryMapping::ENERGY_REQUIREMENT_INHERIT,
                'updated_by' => auth()->id(),
            ],
        );
        $this->resetEprelStateForCategory($categoryId);
        $this->forgetDashboardCounts();

        if ($this->editingCategoryId === $categoryId) {
            $this->resetEditor();
        }

        $this->dispatch('notify', type: 'warning', message: __('M SAN kategorija će se preskakati.'));
    }

    public function clearMapping(int $categoryId): void
    {
        $this->authorizeManage();
        MsanCategory::query()->findOrFail($categoryId);

        MsanCategoryMapping::query()
            ->where('msan_category_id', $categoryId)
            ->delete();
        $this->resetEprelStateForCategory($categoryId);
        $this->forgetDashboardCounts();

        if ($this->editingCategoryId === $categoryId) {
            $this->resetEditor();
        }

        $this->dispatch('notify', type: 'success', message: __('Mapiranje M SAN kategorije je uklonjeno.'));
    }

    public function autoMatchExactNames(): void
    {
        $this->authorizeManage();

        $localByName = $this->uniqueLocalCategoriesByName();
        $uniqueMsanNames = $this->uniqueMsanCategoryNames();
        if ($localByName === [] || $uniqueMsanNames === []) {
            $this->dispatch('notify', type: 'warning', message: __('Nema jedinstvenih naziva na obje strane za automatsko mapiranje.'));

            return;
        }

        $matched = 0;
        DB::transaction(function () use ($localByName, $uniqueMsanNames, &$matched): void {
            MsanCategory::query()
                ->select(['id', 'name'])
                ->where('is_stale', false)
                ->where(function (Builder $query): void {
                    $query
                        ->whereDoesntHave('mapping')
                        ->orWhereHas('mapping', static function (Builder $mappingQuery): void {
                            $mappingQuery
                                ->where('status', 'unmapped')
                                ->orWhere(function (Builder $invalidMappedQuery): void {
                                    $invalidMappedQuery
                                        ->where('status', 'mapped')
                                        ->whereNull('local_category_id');
                                });
                        });
                })
                ->orderBy('id')
                ->chunkById(200, function ($categories) use ($localByName, $uniqueMsanNames, &$matched): void {
                    foreach ($categories as $category) {
                        $key = $this->normalizedName((string) $category->name);
                        if (! isset($uniqueMsanNames[$key])) {
                            continue;
                        }

                        $localCategoryId = $localByName[$key] ?? null;
                        if (! $localCategoryId) {
                            continue;
                        }

                        MsanCategoryMapping::query()->updateOrCreate(
                            ['msan_category_id' => (int) $category->getKey()],
                            [
                                'local_category_id' => $localCategoryId,
                                'status' => 'mapped',
                                'updated_by' => auth()->id(),
                            ],
                        );
                        $matched++;
                    }
                });
        });
        $this->forgetDashboardCounts();

        $this->dispatch(
            'notify',
            type: $matched > 0 ? 'success' : 'info',
            message: trans_choice(
                '{0} Nije pronađeno sigurno automatsko mapiranje.|{1} Automatski je mapirana :count kategorija.|[2,*] Automatski su mapirane :count kategorije.',
                $matched,
                ['count' => $matched],
            ),
        );
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

        $categoriesQuery = $this->filteredQuery()
            ->with([
                'mapping:id,msan_category_id,local_category_id,status,eprel_product_group,energy_requirement,updated_by,updated_at',
                'mapping.localCategory.translations' => fn ($query) => $query
                    ->whereIn('locale', $this->preferredLocales()),
            ]);
        $categories = $this->applyHierarchyOrder($categoriesQuery)
            ->paginate($perPage, pageName: self::PAGE_NAME);
        $editingCategory = $this->editingCategory();
        $branchImportSuggestion = null;
        if ($this->canManage() && $editingCategory && $this->localCategoryId === '') {
            try {
                $preview = app(MsanCategoryBranchImportService::class)->preview(
                    $editingCategory,
                    'hr',
                    $this->preferredLocales(),
                    $this->branchImportParentId !== '' ? (int) $this->branchImportParentId : null,
                );
                if ((bool) ($preview['can_import'] ?? false)) {
                    $branchImportSuggestion = $preview;
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return view('livewire.admin.integrations.msan.category-mapping-manager', [
            'categories' => $categories,
            'localCategoryOptions' => $this->editingCategoryId ? $this->localCategoryOptions() : [],
            'energyRequirementOptions' => MsanCategoryMapping::energyRequirementOptions(),
            'eprelProductGroupOptions' => EprelClient::productGroupOptions(),
            'canManageMapping' => $this->canManage(),
            'perPage' => $perPage,
            'statusCounts' => $this->statusCounts(),
            'activeFilterCount' => ($this->search !== '' ? 1 : 0)
                + ($this->status !== 'all' ? 1 : 0)
                + ($this->withProductsOnly ? 1 : 0),
            'editingCategory' => $editingCategory,
            'branchImportSuggestion' => $branchImportSuggestion,
        ]);
    }

    private function filteredQuery(): Builder
    {
        $search = trim(Str::limit($this->search, 120, ''));

        return MsanCategory::query()
            ->select([
                'id',
                'external_id',
                'parent_external_id',
                'name',
                'path',
                'product_count',
                'last_seen_at',
                'is_stale',
            ])
            ->where('is_stale', false)
            ->when($this->withProductsOnly, static fn (Builder $query) => $query->where('product_count', '>', 0))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $prefix = $search.'%';
                $query->where(function (Builder $nested) use ($prefix): void {
                    $nested
                        ->where('external_id', 'like', $prefix)
                        ->orWhere('name', 'like', $prefix)
                        ->orWhere('path', 'like', $prefix);
                });
            })
            ->when($this->status === 'mapped', function (Builder $query): void {
                $query->whereHas('mapping', static function (Builder $mappingQuery): void {
                    $mappingQuery
                        ->where('status', 'mapped')
                        ->whereNotNull('local_category_id');
                });
            })
            ->when($this->status === 'ignored', function (Builder $query): void {
                $query->whereHas('mapping', static fn (Builder $mappingQuery) => $mappingQuery->where('status', 'ignored'));
            })
            ->when($this->status === 'unmapped', function (Builder $query): void {
                $this->applyUnmappedConstraint($query);
            });
    }

    /** @return array{all:int,unmapped:int,mapped:int,ignored:int} */
    private function statusCounts(): array
    {
        $base = MsanCategory::query()->where('is_stale', false);
        $mapped = (clone $base)
            ->whereHas('mapping', static fn (Builder $query) => $query
                ->where('status', MsanCategoryMapping::STATUS_MAPPED)
                ->whereNotNull('local_category_id'))
            ->count();
        $ignored = (clone $base)
            ->whereHas('mapping', static fn (Builder $query) => $query
                ->where('status', MsanCategoryMapping::STATUS_IGNORED))
            ->count();

        return [
            'all' => (clone $base)->count(),
            'mapped' => $mapped,
            'ignored' => $ignored,
            'unmapped' => (clone $base)->where(fn (Builder $query) => $this->applyUnmappedConstraint($query))->count(),
        ];
    }

    private function applyUnmappedConstraint(Builder $query): void
    {
        $query->where(function (Builder $nested): void {
            $nested
                ->whereDoesntHave('mapping')
                ->orWhereHas('mapping', static function (Builder $mappingQuery): void {
                    $mappingQuery
                        ->where('status', MsanCategoryMapping::STATUS_UNMAPPED)
                        ->orWhere(function (Builder $invalidMappedQuery): void {
                            $invalidMappedQuery
                                ->where('status', MsanCategoryMapping::STATUS_MAPPED)
                                ->whereNull('local_category_id');
                        });
                });
        });
    }

    private function nextUnmappedCategoryId(int $exceptCategoryId): ?int
    {
        $query = MsanCategory::query()
            ->where('is_stale', false)
            ->whereKeyNot($exceptCategoryId);
        $this->applyUnmappedConstraint($query);

        $id = $this->applyHierarchyOrder($query)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function applyHierarchyOrder(Builder $query): Builder
    {
        return $query->inTreeOrder();
    }

    private function editingCategory(): ?MsanCategory
    {
        if ($this->editingCategoryId === null) {
            return null;
        }

        return MsanCategory::query()
            ->select(['id', 'external_id', 'parent_external_id', 'name', 'path', 'product_count', 'is_stale'])
            ->find($this->editingCategoryId);
    }

    private function mappedLocalParentId(MsanCategory $category): string
    {
        if (blank($category->parent_external_id)) {
            return '';
        }

        $localCategoryId = MsanCategory::query()
            ->where('external_id', $category->parent_external_id)
            ->whereHas('mapping', static fn (Builder $query) => $query
                ->where('status', MsanCategoryMapping::STATUS_MAPPED)
                ->whereNotNull('local_category_id'))
            ->with('mapping:id,msan_category_id,local_category_id')
            ->first()
            ?->mapping
            ?->local_category_id;

        return $localCategoryId ? (string) $localCategoryId : '';
    }

    /** @return array<int, array{id:int,label:string}> */
    private function localCategoryOptions(): array
    {
        return Category::query()
            ->select(['categories.id', 'categories.code', 'categories.parent_id', 'categories._lft', 'categories._rgt'])
            ->where('scope', Category::SCOPE_CATALOG)
            ->withDepth()
            ->with([
                'translations' => fn ($query) => $query->whereIn('locale', $this->preferredLocales()),
            ])
            ->orderBy('_lft')
            ->get()
            ->map(function (Category $category): array {
                $translation = $this->preferredTranslation($category->translations);
                $name = trim((string) ($translation?->name ?? $category->code));
                $depth = max(0, (int) ($category->depth ?? 0));

                return [
                    'id' => (int) $category->getKey(),
                    'label' => str_repeat('— ', $depth).$name,
                ];
            })
            ->all();
    }

    /** @return array<string, int> */
    private function uniqueLocalCategoriesByName(): array
    {
        $grouped = Category::query()
            ->select(['categories.id', 'categories.code'])
            ->where('scope', Category::SCOPE_CATALOG)
            ->with([
                'translations' => fn ($query) => $query->whereIn('locale', $this->preferredLocales()),
            ])
            ->whereHas('translations', fn (Builder $query) => $query->whereIn('locale', $this->preferredLocales()))
            ->get()
            ->map(function (Category $category): array {
                $translation = $this->preferredTranslation($category->translations);

                return [
                    'id' => (int) $category->getKey(),
                    'name' => $this->normalizedName((string) ($translation?->name ?? '')),
                ];
            })
            ->filter(static fn (array $row): bool => $row['name'] !== '')
            ->groupBy('name');

        $result = [];
        foreach ($grouped as $name => $rows) {
            if ($rows->count() === 1) {
                $result[(string) $name] = (int) $rows->first()['id'];
            }
        }

        return $result;
    }

    /** @return array<string, true> */
    private function uniqueMsanCategoryNames(): array
    {
        return MsanCategory::query()
            ->where('is_stale', false)
            ->pluck('name')
            ->map(fn ($name): string => $this->normalizedName((string) $name))
            ->filter()
            ->countBy()
            ->filter(static fn (int $count): bool => $count === 1)
            ->map(static fn (): bool => true)
            ->all();
    }

    private function preferredTranslation($translations): mixed
    {
        foreach ($this->preferredLocales() as $locale) {
            $translation = $translations->firstWhere('locale', $locale);
            if ($translation) {
                return $translation;
            }
        }

        return $translations->first();
    }

    /** @return array<int, string> */
    private function preferredLocales(): array
    {
        return array_values(array_unique(array_filter([
            'hr',
            strtolower((string) config('app.locale', 'hr')),
            strtolower((string) config('app.fallback_locale', 'hr')),
        ])));
    }

    private function normalizedName(string $name): string
    {
        return (string) Str::of($name)->squish()->lower();
    }

    private function resetEditor(): void
    {
        $this->editingCategoryId = null;
        $this->localCategoryId = '';
        $this->branchImportParentId = '';
        $this->eprelProductGroup = '';
        $this->energyRequirement = MsanCategoryMapping::ENERGY_REQUIREMENT_INHERIT;
        $this->resetValidation();
    }

    private function nullableText(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function resetEprelStateForCategory(int $categoryId): void
    {
        MsanProduct::query()
            ->whereIn('id', DB::table('msan_product_categories')
                ->select('msan_product_id')
                ->where('msan_category_id', $categoryId))
            ->update([
                'eprel_match_status' => MsanProduct::EPREL_PENDING,
                'eprel_identifier_checksum' => null,
                'eprel_checked_at' => null,
            ]);
    }

    private function forgetDashboardCounts(): void
    {
        \Illuminate\Support\Facades\Cache::forget(Dashboard::COUNTS_CACHE_KEY);
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
