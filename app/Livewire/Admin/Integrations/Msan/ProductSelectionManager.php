<?php

namespace App\Livewire\Admin\Integrations\Msan;

use App\Models\Integrations\Msan\MsanCategory;
use App\Models\Integrations\Msan\MsanProduct;
use App\Services\Integrations\Msan\MsanCatalogSyncService;
use App\Services\Integrations\Msan\MsanImportCoordinator;
use App\Services\Integrations\Msan\MsanSettingsService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Session;
use Livewire\Component;
use Livewire\WithPagination;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Throwable;

class ProductSelectionManager extends Component
{
    use WithPagination;

    private const PAGE_NAME = 'msanProductsPage';

    #[Session(key: 'admin.msan.products.search')]
    public string $search = '';

    #[Session(key: 'admin.msan.products.search-input')]
    public string $searchInput = '';

    #[Session(key: 'admin.msan.products.category')]
    public string $categoryId = '';

    #[Session(key: 'admin.msan.products.brand')]
    public string $brand = '';

    #[Session(key: 'admin.msan.products.availability')]
    public string $availability = 'all';

    #[Session(key: 'admin.msan.products.selection')]
    public string $selection = 'all';

    #[Session(key: 'admin.msan.products.import-status')]
    public string $importStatus = 'all';

    public function mount(): void
    {
        $this->authorizeView();

        $query = request()->query();
        if (array_key_exists('selection', $query) && in_array((string) $query['selection'], ['all', 'selected', 'unselected'], true)) {
            $this->selection = (string) $query['selection'];
        }
        if (array_key_exists('importStatus', $query) && in_array((string) $query['importStatus'], [
            'all',
            MsanProduct::IMPORT_PENDING,
            MsanProduct::IMPORT_QUEUED,
            MsanProduct::IMPORT_IMPORTING,
            MsanProduct::IMPORT_IMPORTED,
            MsanProduct::IMPORT_FAILED,
            MsanProduct::IMPORT_SKIPPED,
        ], true)) {
            $this->importStatus = (string) $query['importStatus'];
        }

        if ($this->searchInput === '') {
            $this->searchInput = $this->search;
        }
    }

    public function updatedSearch(): void
    {
        $this->resetProductsPage();
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
        $this->resetProductsPage();
    }

    public function updatedCategoryId(): void
    {
        $this->resetProductsPage();
    }

    public function updatedBrand(): void
    {
        $this->resetProductsPage();
    }

    public function updatedAvailability(): void
    {
        $this->resetProductsPage();
    }

    public function updatedSelection(): void
    {
        $this->resetProductsPage();
    }

    public function updatedImportStatus(): void
    {
        $this->resetProductsPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->searchInput = '';
        $this->categoryId = '';
        $this->brand = '';
        $this->availability = 'all';
        $this->selection = 'all';
        $this->importStatus = 'all';
        $this->resetProductsPage();
    }

    public function clearFilter(string $filter): void
    {
        match ($filter) {
            'search' => $this->clearSearchFilter(),
            'category' => $this->categoryId = '',
            'brand' => $this->brand = '',
            'availability' => $this->availability = 'all',
            'selection' => $this->selection = 'all',
            'importStatus' => $this->importStatus = 'all',
            default => null,
        };

        $this->resetProductsPage();
    }

    public function toggleSelection(int $productId): void
    {
        $this->authorizeImport();

        $product = MsanProduct::query()->findOrFail($productId);
        if ((bool) $product->selected) {
            $product->update(['selected' => false]);
            $this->forgetDashboardCounts();

            return;
        }

        if ((bool) $product->is_stale) {
            $this->dispatch(
                'notify',
                type: 'warning',
                message: __('Zastarjeli M SAN artikl nije moguće odabrati za uvoz.'),
            );

            return;
        }

        if (in_array($product->match_status, [MsanProduct::MATCH_CONFLICT, MsanProduct::MATCH_IGNORED], true)) {
            $this->dispatch(
                'notify',
                type: 'warning',
                message: __('Artikl s konfliktom ili statusom ignoriranja nije moguće odabrati za uvoz.'),
            );

            return;
        }

        if (! in_array($product->import_status, MsanProduct::IMPORT_READY_STATUSES, true)) {
            $this->dispatch(
                'notify',
                type: 'info',
                message: $product->import_status === MsanProduct::IMPORT_IMPORTED
                    ? __('Artikl je već uvezen i ne treba ga ponovno uključivati za uvoz.')
                    : __('Artikl je već u redu čekanja ili se trenutačno uvozi.'),
            );

            return;
        }

        if (! $this->productHasMappedCategory($product)) {
            $this->dispatch(
                'notify',
                type: 'warning',
                message: __('Artikl se ne može odabrati dok barem jedna njegova kategorija nije mapirana.'),
            );

            return;
        }

        $product->update(['selected' => true]);
        $this->forgetDashboardCounts();
    }

    public function selectFiltered(): void
    {
        $this->authorizeImport();

        $query = $this->eligibleQuery($this->filteredQuery());

        $eligibleCount = (clone $query)->count();
        $alreadySelectedCount = (clone $query)->where('selected', true)->count();
        $changedCount = (clone $query)->where('selected', false)->update(['selected' => true]);
        $this->forgetDashboardCounts();

        $this->dispatch(
            'notify',
            type: $eligibleCount > 0 ? 'success' : 'info',
            message: __('Novo uključeno: :changed. Već uključeno: :existing.', [
                'changed' => $changedCount,
                'existing' => $alreadySelectedCount,
            ]),
        );
    }

    public function deselectFiltered(): void
    {
        $this->authorizeImport();

        $query = $this->filteredQuery();
        $count = (clone $query)->where('selected', true)->count();
        $query->update(['selected' => false]);
        $this->forgetDashboardCounts();

        $this->dispatch(
            'notify',
            type: $count > 0 ? 'success' : 'info',
            message: __('Poništen je odabir artikala prema trenutnim filtrima: :count.', ['count' => $count]),
        );
    }

    public function queueSelectedImport(): void
    {
        $this->authorizeImport();

        $eligibleCount = $this->eligibleQuery(MsanProduct::query()->where('selected', true))
            ->count();

        if ($eligibleCount === 0) {
            $this->dispatch(
                'notify',
                type: 'warning',
                message: __('Nema odabranih artikala spremnih za novi uvoz.'),
            );

            return;
        }

        try {
            app(MsanImportCoordinator::class)->queueSelected((int) auth()->id());
        } catch (Throwable $exception) {
            report($exception);
            $this->dispatch(
                'notify',
                type: 'error',
                message: __('Uvoz nije moguće staviti u red. Provjerite postavke i pokušajte ponovno.'),
            );

            return;
        }

        $this->dispatch(
            'notify',
            type: 'success',
            message: __('Uvoz :count odabranih artikala stavljen je u red.', ['count' => $eligibleCount]),
        );
    }

    public function render()
    {
        $this->authorizeView();
        $filterOptions = $this->filterOptions();
        $msanSettings = app(MsanSettingsService::class);

        $perPage = app(SystemSettingsService::class)->getInt(
            'admin_items_per_page',
            (int) config('admin_ui.pagination.admin_items_per_page', 20),
            5,
            200,
        );

        $products = $this->filteredQuery()
            ->select([
                'id',
                'external_code',
                'name',
                'image_url',
                'catalog_checksum',
                'brand',
                'part_number',
                'currency_code',
                'partner_price',
                'recommended_retail_price',
                'availability_level',
                'selected',
                'is_stale',
                'local_product_id',
                'match_status',
                'import_status',
                'last_seen_at',
                'last_imported_at',
                'last_error',
            ])
            ->withCount([
                'categories as mapped_categories_count' => static function (Builder $categoryQuery): void {
                    $categoryQuery->whereHas('mapping', static function (Builder $mappingQuery): void {
                        $mappingQuery
                            ->where('status', 'mapped')
                            ->whereNotNull('local_category_id');
                    });
                },
            ])
            ->with([
                'categories:id,name,path',
                'localProduct:id,code,sku',
            ])
            ->orderBy('name')
            ->orderBy('external_code')
            ->paginate($perPage, pageName: self::PAGE_NAME);

        $filteredStatsQuery = $this->filteredQuery();
        $filteredCount = (clone $filteredStatsQuery)->count();
        $filteredSelectedCount = (clone $filteredStatsQuery)->where('selected', true)->count();
        $filteredEligibleCount = $this->eligibleQuery(clone $filteredStatsQuery)->count();
        $selectedTotalCount = MsanProduct::query()->where('selected', true)->count();
        $selectedEligibleCount = $this->selectedEligibleCount();

        return view('livewire.admin.integrations.msan.product-selection-manager', [
            'products' => $products,
            'categories' => $filterOptions['categories'],
            'brands' => $filterOptions['brands'],
            'importStatuses' => $this->importStatusOptions(),
            'canManageImport' => $this->canImport(),
            'selectedEligibleCount' => $selectedEligibleCount,
            'selectedTotalCount' => $selectedTotalCount,
            'selectedIneligibleCount' => max(0, $selectedTotalCount - $selectedEligibleCount),
            'filteredCount' => $filteredCount,
            'filteredSelectedCount' => $filteredSelectedCount,
            'filteredEligibleCount' => $filteredEligibleCount,
            'activeFilterCount' => $this->activeFilterCount(),
            'perPage' => $perPage,
            'availabilityLevelLabels' => MsanSettingsService::AVAILABILITY_LEVEL_LABELS,
            'stockLevelQuantities' => $msanSettings->stockLevelQuantities(),
        ]);
    }

    private function filteredQuery(): Builder
    {
        $search = trim(Str::limit($this->search, 120, ''));
        $categoryId = ctype_digit($this->categoryId) ? (int) $this->categoryId : null;

        return MsanProduct::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $prefix = $search.'%';
                $query->where(function (Builder $nested) use ($prefix): void {
                    $nested
                        ->where('external_code', 'like', $prefix)
                        ->orWhere('part_number', 'like', $prefix)
                        ->orWhere('model', 'like', $prefix)
                        ->orWhere('name', 'like', $prefix);
                });
            })
            ->when($categoryId, static function (Builder $query) use ($categoryId): void {
                $query->whereHas('categories', static fn (Builder $categoryQuery) => $categoryQuery->whereKey($categoryId));
            })
            ->when($this->brand !== '', fn (Builder $query) => $query->where('brand', $this->brand))
            ->when($this->availability === 'available', fn (Builder $query) => $query->where('availability_level', '>', 0))
            ->when($this->availability === 'unavailable', fn (Builder $query) => $query->where('availability_level', 0))
            ->when($this->availability === 'unknown', fn (Builder $query) => $query->whereNull('availability_level'))
            ->when($this->selection === 'selected', fn (Builder $query) => $query->where('selected', true))
            ->when($this->selection === 'unselected', fn (Builder $query) => $query->where('selected', false))
            ->when($this->importStatus !== 'all' && $this->importStatus !== '', fn (Builder $query) => $query->where('import_status', $this->importStatus));
    }

    /** @return array{categories: array<int, array{id:int,label:string}>, brands: array<int,string>} */
    private function filterOptions(): array
    {
        return Cache::remember(
            MsanCatalogSyncService::ADMIN_FILTER_OPTIONS_CACHE_KEY,
            now()->addMinutes(15),
            static fn (): array => [
                'categories' => MsanCategory::query()
                    ->select(['id', 'name', 'path'])
                    ->where('is_stale', false)
                    ->inTreeOrder()
                    ->get()
                    ->map(static fn (MsanCategory $category): array => [
                        'id' => (int) $category->getKey(),
                        'label' => trim((string) ($category->path ?: $category->name)),
                    ])
                    ->all(),
                'brands' => MsanProduct::query()
                    ->where('is_stale', false)
                    ->whereNotNull('brand')
                    ->where('brand', '!=', '')
                    ->distinct()
                    ->orderBy('brand')
                    ->limit(500)
                    ->pluck('brand')
                    ->map(static fn ($brand): string => (string) $brand)
                    ->all(),
            ],
        );
    }

    /** @return array<int, array{value:string,label:string}> */
    private function importStatusOptions(): array
    {
        return collect([
            MsanProduct::IMPORT_PENDING,
            MsanProduct::IMPORT_QUEUED,
            MsanProduct::IMPORT_IMPORTING,
            MsanProduct::IMPORT_IMPORTED,
            MsanProduct::IMPORT_FAILED,
            MsanProduct::IMPORT_SKIPPED,
        ])->map(fn (string $status): array => [
            'value' => $status,
            'label' => $this->importStatusLabel($status),
        ])->all();
    }

    private function importStatusLabel(string $status): string
    {
        return match ($status) {
            MsanProduct::IMPORT_PENDING => __('Čeka uvoz'),
            MsanProduct::IMPORT_QUEUED => __('U redu čekanja'),
            MsanProduct::IMPORT_IMPORTING => __('Uvoz u tijeku'),
            MsanProduct::IMPORT_IMPORTED => __('Uvezeno'),
            MsanProduct::IMPORT_FAILED => __('Neuspješno'),
            MsanProduct::IMPORT_SKIPPED => __('Preskočeno'),
            default => $status,
        };
    }

    private function selectedEligibleCount(): int
    {
        return $this->eligibleQuery(MsanProduct::query()->where('selected', true))
            ->count();
    }

    private function eligibleQuery(Builder $query): Builder
    {
        return $query
            ->whereIn('import_status', MsanProduct::IMPORT_READY_STATUSES)
            ->where('is_stale', false)
            ->whereNotIn('match_status', [MsanProduct::MATCH_CONFLICT, MsanProduct::MATCH_IGNORED])
            ->whereHas('categories.mapping', static function (Builder $mappingQuery): void {
                $mappingQuery
                    ->where('status', 'mapped')
                    ->whereNotNull('local_category_id');
            });
    }

    private function productHasMappedCategory(MsanProduct $product): bool
    {
        return $product->categories()
            ->whereHas('mapping', static function (Builder $mappingQuery): void {
                $mappingQuery
                    ->where('status', 'mapped')
                    ->whereNotNull('local_category_id');
            })
            ->exists();
    }

    private function resetProductsPage(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    private function clearSearchFilter(): void
    {
        $this->search = '';
        $this->searchInput = '';
        $this->resetErrorBag('searchInput');
    }

    private function activeFilterCount(): int
    {
        return collect([
            $this->search !== '',
            $this->categoryId !== '',
            $this->brand !== '',
            $this->availability !== 'all',
            $this->selection !== 'all',
            $this->importStatus !== 'all',
        ])->filter()->count();
    }

    private function forgetDashboardCounts(): void
    {
        Cache::forget(Dashboard::COUNTS_CACHE_KEY);
    }

    private function authorizeView(): void
    {
        abort_unless($this->canView(), 403);
    }

    private function authorizeImport(): void
    {
        abort_unless($this->canImport(), 403);
    }

    private function canView(): bool
    {
        $user = auth()->user();

        return (bool) ($user && (Bouncer::is($user)->an('superadmin') || $user->can('integrations.msan.view')));
    }

    private function canImport(): bool
    {
        $user = auth()->user();

        return (bool) ($user && (Bouncer::is($user)->an('superadmin') || $user->can('integrations.msan.import.manage')));
    }
}
