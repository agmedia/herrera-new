<?php

namespace App\Livewire\Admin\User;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\CustomerCatalogFeed;
use App\Services\Feeds\CustomerCatalogFeedAccess;
use App\Services\Integrations\SupplierCatalog\SupplierCatalogFeedClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CustomerCatalogFeedManager extends Component
{
    #[Locked]
    public int $userId;

    public string $search = '';

    public array $productIds = [];

    public ?int $categoryId = null;

    public ?int $brandId = null;

    public string $supplier = '';

    #[Locked]
    public array $previewIds = [];

    public function updated($property): void
    {
        if (in_array($property, ['categoryId', 'brandId', 'supplier'], true)) {
            $this->previewIds = [];
        }
    }

    public function previewCategory(): void
    {
        $this->customer('update');
        $this->previewIds = [];
        $this->validate([
            'categoryId' => ['required', 'integer', Rule::exists('categories', 'id')->where('scope', 'catalog')],
            'brandId' => ['nullable', 'integer', 'exists:catalog_manufacturers,id'],
            'supplier' => ['nullable', Rule::in(array_keys(SupplierCatalogFeedClient::SUPPLIERS))],
        ]);
        $categories = Category::query()->descendantsAndSelf($this->categoryId)->modelKeys();
        $ids = Product::query()->where('is_active', true)
            ->whereHas('categories', fn ($query) => $query->whereIn('categories.id', $categories))
            ->when($this->brandId, fn ($query) => $query->where('manufacturer_id', $this->brandId))
            ->when($this->supplier, fn ($query) => $query->whereHas('currentSupplierLinks', fn ($links) => $links->where('supplier', $this->supplier)))
            ->orderBy('id')->limit(5001)->pluck('id')->all();
        if (count(array_unique(array_merge($this->productIds, $ids))) > 5000) {
            $this->addError('categoryId', 'Odobrenje može sadržavati do 5.000 artikala. Suzite odabir.');

            return;
        }
        $this->previewIds = $ids;
        if (! $ids) {
            $this->addError('categoryId', 'Nema aktivnih artikala za odabrane uvjete.');
        }
    }

    public function addPreview(): void
    {
        $this->customer('update');
        $this->productIds = array_values(array_unique(array_merge($this->productIds, $this->previewIds)));
        $this->previewIds = [];
    }

    public bool $includePurchasedProducts = false;

    #[Locked]
    public ?string $issuedUrl = null;

    public function mount(int $userId): void
    {
        $this->userId = $userId;
        $this->customer();
        $feed = CustomerCatalogFeed::query()->where('user_id', $userId)->first();
        $this->productIds = $feed?->products()->pluck('products.id')->map(fn ($id) => (int) $id)->all() ?? [];
        $this->includePurchasedProducts = (bool) $feed?->include_purchased_products;
    }

    public function addProduct(int $productId): void
    {
        $this->customer('update');
        Product::query()->findOrFail($productId);
        if (count($this->productIds) < 5000) {
            $this->productIds = array_values(array_unique([...$this->productIds, $productId]));
        }
    }

    public function removeProduct(int $productId): void
    {
        $this->customer('update');
        $this->productIds = array_values(array_filter($this->productIds, fn ($id) => (int) $id !== $productId));
    }

    public function saveAssortment(): void
    {
        $customer = $this->customer('update');
        app(CustomerCatalogFeedAccess::class)->saveAssortment($customer, auth()->user(), $this->productIds, $this->includePurchasedProducts);
        $this->dispatch('notify', type: 'success', message: __('Odobreni XML asortiman je spremljen.'));
    }

    public function issueToken(): void
    {
        $this->issuedUrl = null;
        $this->saveAssortment();
        $token = app(CustomerCatalogFeedAccess::class)->issueToken($this->customer('update'), auth()->user());
        $this->issuedUrl = preg_replace('#^http://#i', 'https://', route('feed.customer-wholesale', ['token' => $token]));
        $this->dispatch('notify', type: 'success', message: __('Nova XML poveznica je spremna. Prethodna poveznica više ne vrijedi.'));
    }

    public function revoke(): void
    {
        app(CustomerCatalogFeedAccess::class)->revoke($this->customer('update'), auth()->user());
        $this->issuedUrl = null;
        $this->dispatch('notify', type: 'success', message: __('XML pristup kupcu je ukinut.'));
    }

    public function render()
    {
        $this->customer();
        $this->validate(['search' => ['string', 'max:191'], 'includePurchasedProducts' => ['boolean']]);
        $products = Product::query()->with('translations')->when(trim($this->search) !== '', function (Builder $query): void {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($this->search)).'%';
            $query->where(fn (Builder $query) => $query->whereRaw("sku LIKE ? ESCAPE '!'", [$term])
                ->orWhereRaw("code LIKE ? ESCAPE '!'", [$term])->orWhereHas('translations', fn (Builder $translation) => $translation->whereRaw("name LIKE ? ESCAPE '!'", [$term])));
        })->orderBy('sku')->limit(25)->get();

        return view('livewire.admin.user.customer-catalog-feed-manager', [
            'feed' => CustomerCatalogFeed::query()->where('user_id', $this->userId)->first(),
            'products' => $products,
            'selectedProducts' => Product::query()->whereKey($this->productIds)->with('translations')->orderBy('sku')->limit(50)->get(),
            'canUpdate' => $this->canUpdate(),
            'categories' => Category::where('scope', 'catalog')->with('translations')->defaultOrder()->get(),
            'brands' => Manufacturer::with('translations')->orderBy('code')->get(),
            'previewProducts' => Product::whereKey(array_slice($this->previewIds, 0, 20))->with('translations')->orderBy('id')->get(),
        ]);
    }

    private function customer(string $operation = 'view'): User
    {
        abort_unless(auth()->user(), 403);
        $customer = User::query()->with('b2bAccount.customerGroup')->findOrFail($this->userId);
        app(CustomerCatalogFeedAccess::class)->authorize($customer, auth()->user(), $operation);

        return $customer;
    }

    private function canUpdate(): bool
    {
        $actor = auth()->user();

        return (bool) ($actor && ($actor->isA('superadmin') || ($actor->can('admin.access') && $actor->can('catalog.b2b_prices.update'))));
    }
}
