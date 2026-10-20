<?php

namespace App\Livewire\Admin\Integrations\SupplierCatalog;

use App\Jobs\Integrations\SupplierCatalog\ImportSupplierImages;
use App\Models\Catalog\Category\Category;
use App\Models\Integrations\SupplierCatalog\SupplierCatalogItem;
use App\Models\Settings\Local\TaxRate;
use App\Services\Integrations\SupplierCatalog\SupplierCatalogFeedClient;
use App\Services\Integrations\SupplierCatalog\SupplierCatalogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Throwable;

class Dashboard extends Component
{
    use WithPagination;

    #[Locked]
    public string $supplier = 'videx';

    public array $options = [];

    public array $selected = [];

    public string $search = '';

    public string $status = 'new';

    public string $category = '';

    public bool $availableOnly = false;

    public string $notice = '';

    public bool $failed = false;

    public function mount(): void
    {
        $this->authorizeManage();
        $this->options = app(SupplierCatalogService::class)->options($this->supplier);
    }

    public function chooseSupplier(string $supplier): void
    {
        $this->authorizeManage();
        abort_unless(isset(SupplierCatalogFeedClient::SUPPLIERS[$supplier]), 404);
        $this->supplier = $supplier;
        $this->options = app(SupplierCatalogService::class)->options($supplier);
        $this->selected = [];
        $this->search = $this->category = $this->notice = '';
        $this->status = 'new';
        $this->resetPage();
        $this->resetValidation();
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'status', 'category', 'availableOnly'], true)) {
            $this->selected = [];
            $this->resetPage();
        }
    }

    public function saveSettings(): void
    {
        $this->authorizeManage();
        $this->runAction(function (): string {
            app(SupplierCatalogService::class)->saveOptions($this->supplier, $this->options);

            return 'Mapiranje kategorija i postavke cijena su spremljeni.';
        });
    }

    public function refreshFeed(): void
    {
        $this->authorizeManage();
        $this->runAction(function (): string {
            $count = app(SupplierCatalogService::class)->refresh($this->supplier);
            $this->selected = [];
            $this->resetPage();

            return 'Katalog je osvježen. Dohvaćeno artikala: '.$count.'.';
        });
    }

    public function selectPage(): void
    {
        $this->authorizeManage();
        $this->selected = $this->query()->where('is_current', true)->where('status', 'new')->orderBy('name')->paginate(25)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function importSelected(): void
    {
        $this->authorizeManage();
        $this->runAction(function (): string {
            $result = app(SupplierCatalogService::class)->import($this->supplier, $this->selected, $this->options, auth()->id());
            $this->selected = [];

            return 'Uvezeno nacrta: '.$result['created'].'. Već postoje / preskočeno: '.$result['skipped'].'.';
        });
    }

    public function retryImages(int $id): void
    {
        $this->authorizeManage();
        $item = SupplierCatalogItem::query()->where('supplier', $this->supplier)->whereNotNull('product_id')->findOrFail($id);
        abort_unless(\App\Models\Catalog\Product\Product::query()->whereKey($item->product_id)->where('payload->supplier_catalog->supplier', $this->supplier)->exists(), 403);
        $this->runAction(function () use ($item): string {
            ImportSupplierImages::dispatch($item->id)->onQueue('integrations');

            return 'Dohvat slika je stavljen u red.';
        });
    }

    public function render()
    {
        $this->authorizeManage();
        $service = app(SupplierCatalogService::class);
        $categories = Category::query()->where('scope', 'catalog')->with('translations')->defaultOrder()->get();
        $byId = $categories->keyBy('id');
        $categoryLabels = $categories->mapWithKeys(function ($category) use ($byId): array {
            $labels = [$category->translations->firstWhere('locale', 'hr')?->name ?: $category->code];
            $parent = $byId->get($category->parent_id);
            for ($depth = 0; $parent && $depth < 30; $depth++) {
                array_unshift($labels, $parent->translations->firstWhere('locale', 'hr')?->name ?: $parent->code);
                $parent = $byId->get($parent->parent_id);
            }

            return [$category->id => implode(' › ', $labels)];
        });
        $sourceCategories = SupplierCatalogItem::query()->where('supplier', $this->supplier)->where('is_current', true)
            ->select('category_key', 'category_name')->distinct()->orderBy('category_name')->get();
        $items = $this->query()->orderBy('name')->paginate(25);
        $prices = [];
        // Options may be incomplete while the user edits a field.
        try {
            $validOptions = $service->validateOptions($this->options);
            foreach ($items as $item) {
                $prices[$item->id] = $service->price($item, $validOptions);
            }
        } catch (ValidationException) {
        }

        return view('livewire.admin.integrations.supplier-catalog.dashboard', [
            'suppliers' => SupplierCatalogFeedClient::SUPPLIERS, 'items' => $items, 'categoryLabels' => $categoryLabels,
            'sourceCategories' => $sourceCategories, 'prices' => $prices, 'storeCurrency' => $service->storeCurrency(),
            'storedIncludesTax' => app(\App\Services\Pricing\TaxPricingService::class)->pricesIncludeTax(),
            'taxRates' => TaxRate::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'counts' => SupplierCatalogItem::query()->where('supplier', $this->supplier)->where('is_current', true)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'lastSync' => SupplierCatalogItem::query()->where('supplier', $this->supplier)->max('synced_at'),
        ]);
    }

    private function query(): Builder
    {
        return SupplierCatalogItem::query()->where('supplier', $this->supplier)
            ->when($this->status !== 'stale', fn ($query) => $query->where('is_current', true), fn ($query) => $query->where('is_current', false))
            ->when(in_array($this->status, ['new', 'existing', 'imported', 'conflict'], true), fn ($query) => $query->where('status', $this->status))
            ->when($this->category !== '', fn ($query) => $query->where('category_key', $this->category))
            ->when($this->availableOnly, fn ($query) => $query->where('quantity', '>', 0))
            ->when(trim($this->search) !== '', function ($query): void {
                $search = '%'.mb_substr(trim($this->search), 0, 120).'%';
                $query->where(fn ($query) => $query->where('name', 'like', $search)->orWhere('sku', 'like', $search)->orWhere('barcode', 'like', $search));
            });
    }

    private function runAction(callable $action): void
    {
        $this->resetValidation();
        try {
            $this->notice = $action();
            $this->failed = false;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->failed = true;
            $this->notice = get_class($exception) === \RuntimeException::class
                ? $exception->getMessage() : 'Radnju nije moguće dovršiti. Pokušajte ponovno.';
        }
    }

    private function authorizeManage(): void
    {
        $user = auth()->user();
        abort_unless($user && (Bouncer::is($user)->an('superadmin') || $user->can('integrations.stock.manage')), 403);
    }
}
