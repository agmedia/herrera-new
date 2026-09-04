<?php

namespace App\Livewire\Admin\Catalog\Category;

use App\Models\Catalog\Category\Category;
use App\Models\Settings\Local\Language;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;

class Tree extends Component
{
    use WithPagination;

    public string $search = '';

    public string $scope = Category::SCOPE_CATALOG;

    public string $locale = 'en';

    /**
     * @var array<int>
     */
    public array $expanded = [];

    /**
     * @var array<string, mixed>
     */
    protected $queryString = [
        'search' => ['except' => ''],
        'scope' => ['except' => Category::SCOPE_CATALOG],
        'locale' => ['except' => 'en'],
    ];

    public function mount(): void
    {
        $this->locale = $this->resolveDefaultLocale();

        $requestedScope = (string) (request()->query('scope') ?: $this->scope);
        if (in_array($requestedScope, Category::availableScopes(), true)) {
            $this->scope = $requestedScope;
        }

        $requestedLocale = (string) request()->query('locale', $this->locale);
        if (in_array($requestedLocale, $this->localeOptions, true)) {
            $this->locale = $requestedLocale;
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedScope(): void
    {
        if (! in_array($this->scope, Category::availableScopes(), true)) {
            $this->scope = Category::SCOPE_CATALOG;
        }

        $this->expanded = [];
        $this->resetPage();
    }

    public function updatedLocale(): void
    {
        if (! in_array($this->locale, $this->localeOptions, true)) {
            $this->locale = $this->resolveDefaultLocale();
        }

        $this->expanded = [];
        $this->resetPage();
    }

    public function toggleExpand(int $id): void
    {
        if (in_array($id, $this->expanded, true)) {
            $branchIds = Category::query()->descendantsAndSelf($id)->pluck('id')->all();
            $this->expanded = array_values(array_diff($this->expanded, $branchIds));

            return;
        }

        $category = Category::query()
            ->where('scope', $this->scope)
            ->whereKey($id)
            ->first();

        if (! $category) {
            return;
        }

        $this->expanded[] = $id;
        $this->expanded = array_values(array_unique($this->expanded));
    }

    public function moveUp(int $id): void
    {
        $category = Category::query()
            ->where('scope', $this->scope)
            ->findOrFail($id);

        $category->up();

        $this->dispatch('notify', type: 'info', message: __('Category moved up.'));
    }

    public function moveDown(int $id): void
    {
        $category = Category::query()
            ->where('scope', $this->scope)
            ->findOrFail($id);

        $category->down();

        $this->dispatch('notify', type: 'info', message: __('Category moved down.'));
    }

    public function delete(int $id): void
    {
        $category = Category::query()
            ->where('scope', $this->scope)
            ->findOrFail($id);

        if ($category->children()->exists()) {
            $this->dispatch('notify', type: 'warning', message: __('Delete/move child categories first.'));

            return;
        }

        activity('catalog_categories')
            ->performedOn($category)
            ->causedBy(auth()->user())
            ->event('deleted')
            ->withProperties(['scope' => $category->scope])
            ->log('Category deleted');

        $category->delete();
        $this->expanded = array_values(array_diff($this->expanded, [$id]));

        $this->dispatch('notify', type: 'success', message: __('Category deleted.'));
    }

    /**
     * @return array<int, string>
     */
    public function getScopeOptionsProperty(): array
    {
        return Category::availableScopes();
    }

    /**
     * @return array<int, string>
     */
    public function getLocaleOptionsProperty(): array
    {
        $locales = Language::query()
            ->orderBy('sort_order')
            ->pluck('code')
            ->map(fn ($code): string => (string) $code)
            ->all();

        return array_values(array_unique([
            (string) config('app.locale', 'en'),
            ...$locales,
        ]));
    }

    public function render()
    {
        $isSearchMode = trim($this->search) !== '';

        if ($isSearchMode) {
            $resultPaginator = $this->searchRowsPaginator();

            return view('livewire.admin.catalog.category.tree', [
                'isSearchMode' => true,
                'rows' => $resultPaginator->getCollection()->map(function (Category $row): array {
                    return [
                        'node' => $row,
                        'depth' => (int) ($row->depth ?? 0),
                        'isExpanded' => false,
                        'hasChildren' => (int) ($row->children_count ?? 0) > 0,
                    ];
                }),
                'paginator' => $resultPaginator,
            ]);
        }

        $rootsPaginator = $this->rootPaginator();
        $rows = $this->buildTreeRows($rootsPaginator->getCollection());

        return view('livewire.admin.catalog.category.tree', [
            'isSearchMode' => false,
            'rows' => $rows,
            'paginator' => $rootsPaginator,
        ]);
    }

    /**
     * @param  EloquentCollection<int, Category>  $roots
     * @return Collection<int, array<string, mixed>>
     */
    private function buildTreeRows(EloquentCollection $roots): Collection
    {
        $rows = collect();

        /** @var array<int, EloquentCollection<int, Category>> $childrenCache */
        $childrenCache = [];

        foreach ($roots as $root) {
            $rows->push([
                'node' => $root,
                'depth' => 0,
                'isExpanded' => in_array($root->id, $this->expanded, true),
                'hasChildren' => (int) ($root->children_count ?? 0) > 0,
            ]);

            if (in_array($root->id, $this->expanded, true)) {
                $this->appendChildrenRows($rows, $root->id, 1, $childrenCache);
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<int, EloquentCollection<int, Category>>  $childrenCache
     */
    private function appendChildrenRows(Collection $rows, int $parentId, int $depth, array &$childrenCache): void
    {
        if (! array_key_exists($parentId, $childrenCache)) {
            $childrenCache[$parentId] = Category::query()
                ->where('scope', $this->scope)
                ->where('parent_id', $parentId)
                ->withCount('children')
                ->with([
                    'translations' => fn ($q) => $q
                        ->where('scope', $this->scope)
                        ->where('locale', $this->locale),
                ])
                ->orderBy('sort_order')
                ->orderBy('_lft')
                ->get();
        }

        foreach ($childrenCache[$parentId] as $child) {
            $isExpanded = in_array($child->id, $this->expanded, true);
            $hasChildren = (int) ($child->children_count ?? 0) > 0;

            $rows->push([
                'node' => $child,
                'depth' => $depth,
                'isExpanded' => $isExpanded,
                'hasChildren' => $hasChildren,
            ]);

            if ($isExpanded && $hasChildren) {
                $this->appendChildrenRows($rows, $child->id, $depth + 1, $childrenCache);
            }
        }
    }

    private function rootPaginator(): LengthAwarePaginator
    {
        $perPage = app(SystemSettingsService::class)->getInt(
            'admin_category_roots_per_page',
            (int) config('admin_ui.pagination.admin_category_roots_per_page', 12),
            5,
            100
        );

        return Category::query()
            ->where('scope', $this->scope)
            ->whereIsRoot()
            ->withCount('children')
            ->with([
                'translations' => fn ($q) => $q
                    ->where('scope', $this->scope)
                    ->where('locale', $this->locale),
            ])
            ->orderBy('sort_order')
            ->orderBy('_lft')
            ->paginate($perPage);
    }

    private function searchRowsPaginator(): LengthAwarePaginator
    {
        $perPage = app(SystemSettingsService::class)->getInt(
            'admin_items_per_page',
            (int) config('admin_ui.pagination.admin_items_per_page', 20),
            5,
            200
        );

        return Category::query()
            ->where('scope', $this->scope)
            ->withDepth()
            ->withCount('children')
            ->with([
                'translations' => fn ($q) => $q
                    ->where('scope', $this->scope)
                    ->where('locale', $this->locale),
            ])
            ->where(function ($q): void {
                $q->where('code', 'like', '%'.$this->search.'%')
                    ->orWhereHas('translations', function ($tq): void {
                        $tq->where('scope', $this->scope)
                            ->where(function ($nameQ): void {
                                $nameQ->where('name', 'like', '%'.$this->search.'%')
                                    ->orWhere('slug', 'like', '%'.$this->search.'%');
                            });
                    });
            })
            ->defaultOrder()
            ->paginate($perPage);
    }

    private function resolveDefaultLocale(): string
    {
        $default = Language::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->value('code');

        if (is_string($default) && $default !== '') {
            return $default;
        }

        return $this->localeOptions[0] ?? config('app.locale', 'en');
    }
}
