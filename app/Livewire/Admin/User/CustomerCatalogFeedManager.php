<?php

namespace App\Livewire\Admin\User;

use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\CustomerCatalogFeed;
use App\Services\Feeds\CustomerCatalogFeedAccess;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CustomerCatalogFeedManager extends Component
{
    #[Locked]
    public int $userId;

    public string $search = '';

    public array $productIds = [];

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
