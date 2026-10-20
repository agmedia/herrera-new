<?php

namespace App\Livewire\Admin\User;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Pricing\CustomerDiscountRule;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Services\Pricing\B2BAccessService;
use App\Services\Pricing\CustomerDiscountService;
use App\Services\Pricing\LegacyGroupDiscountReferenceService;
use App\Services\Pricing\PriceCatalogGroupOverview;
use App\Services\Pricing\PriceCatalogResolver;
use App\Services\Pricing\TaxPricingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class CustomerPriceManager extends Component
{
    use WithPagination;

    #[Locked]
    public int $userId;

    #[Locked]
    public ?int $ruleId = null;

    public bool $showRuleForm = false;

    public array $ruleForm = [];

    public string $categorySearch = '';

    public string $manufacturerSearch = '';

    public string $excludedProductSearch = '';

    public string $priceSearch = '';

    public bool $onlyOverrides = true;

    public ?string $savedMessage = null;

    public function mount(int $userId): void
    {
        $this->userId = $userId;
        $this->customer();
        $this->resetRule();
    }

    public function newRule(): void
    {
        $this->customer('update');
        $this->resetRule();
        $this->showRuleForm = true;
    }

    public function editRule(int $ruleId): void
    {
        $this->customer('update');
        $rule = $this->rulesQuery()->findOrFail($ruleId);
        $this->resetRule();
        $this->ruleId = $rule->id;
        $this->ruleForm = $rule->only(array_keys($this->ruleForm));
        $this->ruleForm['starts_at'] = $rule->starts_at?->format('Y-m-d\TH:i:s') ?? '';
        $this->ruleForm['ends_at'] = $rule->ends_at?->format('Y-m-d\TH:i:s') ?? '';
        if ($rule->source_key && ! data_get($rule->payload, 'customer_rule_edited', false)) {
            $this->ruleForm['priority'] = 0;
        }
        $this->showRuleForm = true;
    }

    public function saveRule(): void
    {
        $customer = $this->customer('update');
        $data = $this->ruleForm;
        $data['percent'] = str_replace(',', '.', trim((string) ($data['percent'] ?? '')));
        try {
            app(CustomerDiscountService::class)->saveRule($customer, $data, auth()->user(), $this->ruleId);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn ($messages, $field) => ['ruleForm.'.$field => $messages])->all());
        }
        $this->resetRule();
        $this->resetPage('customerPricesPage');
        $this->savedMessage = __('Popust kupca je spremljen i primjenjuje se prema zadanim uvjetima.');
        $this->dispatch('notify', type: 'success', message: $this->savedMessage);
    }

    public function personalizeRule(int $ruleId, string $source = 'group'): void
    {
        $customer = $this->customer('update');
        $catalog = app(PriceCatalogResolver::class)->activeCatalog();
        abort_unless($catalog && $customer->b2bAccount, 404);
        $groupId = (int) $customer->b2bAccount->customer_group_id;
        if ($source === 'legacy') {
            $rule = app(LegacyGroupDiscountReferenceService::class)->forGroup($catalog, $groupId)->firstWhere('id', $ruleId);
            abort_unless($rule && $rule->is_supported && $rule->percent !== null, 404);
        } else {
            abort_unless($source === 'group', 404);
            $rule = $catalog->discountRules()->findOrFail($ruleId);
            abort_unless(in_array($groupId, $rule->customer_group_ids, true), 404);
        }
        $this->resetRule();
        foreach (array_keys($this->ruleForm) as $field) {
            $this->ruleForm[$field] = $rule->{$field};
        }
        $this->ruleForm['is_active'] = true;
        $this->ruleForm['starts_at'] = '';
        $this->ruleForm['ends_at'] = '';
        $this->showRuleForm = true;
    }

    public function deleteRule(int $ruleId): void
    {
        app(CustomerDiscountService::class)->deleteRule($this->customer('delete'), $ruleId, auth()->user());
        if ($this->ruleId === $ruleId) {
            $this->resetRule();
        }
        $this->savedMessage = __('Posebni popust je obrisan. Za taj opseg ponovno vrijede preostali uvjeti kupca i primarne grupe.');
        $this->dispatch('notify', type: 'success', message: $this->savedMessage);
        $this->resetPage('customerPricesPage');
    }

    public function resetRule(): void
    {
        $this->resetValidation();
        $this->ruleId = null;
        $this->showRuleForm = false;
        $this->categorySearch = $this->manufacturerSearch = $this->excludedProductSearch = '';
        $this->ruleForm = ['name' => '', 'percent' => '', 'manufacturer_ids' => [], 'category_ids' => [],
            'include_descendants' => true, 'excluded_product_ids' => [], 'starts_at' => '', 'ends_at' => '',
            'priority' => 0, 'is_active' => true];
    }

    public function updatedPriceSearch(): void
    {
        $this->resetPage('customerPricesPage');
    }

    public function updatedOnlyOverrides(): void
    {
        $this->resetPage('customerPricesPage');
    }

    public function render()
    {
        $customer = $this->customer();
        $account = $customer->b2bAccount;
        $resolver = app(PriceCatalogResolver::class);
        $catalog = $resolver->activeCatalog();
        $rules = $this->rulesQuery()->orderBy('priority')->orderBy('id')->get();
        $categories = Category::query()->where('scope', Category::SCOPE_CATALOG)->with('translations')->orderBy('_lft')->get();
        $categoryIndex = $categories->keyBy('id');
        $categoryLabels = [];
        foreach ($categories as $category) {
            $parts = [];
            $seen = [];
            for ($node = $category; $node && ! isset($seen[$node->id]); $node = $categoryIndex->get($node->parent_id)) {
                $seen[$node->id] = true;
                array_unshift($parts, $node->translations->firstWhere('locale', app()->getLocale())?->name ?? $node->code);
            }
            $categoryLabels[$category->id] = implode(' › ', $parts);
        }
        $manufacturers = Manufacturer::query()->with('translations')->orderBy('sort_order')->orderBy('code')->get();
        $manufacturerLabels = $manufacturers->mapWithKeys(fn ($brand) => [$brand->id => $brand->translations->firstWhere('locale', app()->getLocale())?->name ?? $brand->code])->all();
        $products = null;
        $priceRows = [];
        if ($catalog && $account?->customerGroup) {
            $query = Product::query()->with('translations');
            if ($this->onlyOverrides) {
                // Use the same scope as the actual price calculation, including dates and exclusions.
                $expression = $resolver->customerDiscounts()->expression($catalog, $customer, now(), 'rule_id');
                $expression ? $query->whereRaw($expression['sql'].' IS NOT NULL', $expression['bindings']) : $query->whereRaw('1 = 0');
            }
            $this->searchProducts($query, $this->priceSearch);
            $products = $query->orderBy('products.sku')->orderBy('products.id')->paginate(10, ['products.*'], 'customerPricesPage');
            $resolver->preloadProducts($products->getCollection(), $customer);
            $groupRows = app(PriceCatalogGroupOverview::class)->rows($catalog, (int) $account->customer_group_id, $products->getCollection());
            foreach ($products as $product) {
                $resolved = $resolver->resolve($product, $customer);
                $group = $groupRows[$product->id];
                $price = $resolved?->price;
                $base = (float) $group['base_price'];
                $priceRows[] = $group + ['customer_price' => $price,
                    'customer_percent' => $price !== null && $base > 0 ? (1 - $price / $base) * 100 : null,
                    'is_override' => $resolved?->source_type === 'price_catalog_customer_discount',
                    'customer_rule' => match ($resolved?->source_type) {
                        'price_catalog_customer_discount' => $rules->firstWhere('id', $resolved->rule_id)?->name,
                        'price_catalog_primary_group_default' => __('Osnovni popust primarne grupe'),
                        default => $group['source_name'] ?? null,
                    }];
            }
        }
        $excludedQuery = Product::query()->with('translations')->whereIn('id', $this->ruleForm['excluded_product_ids'] ?? []);
        if ($this->excludedProductSearch !== '') {
            $excludedQuery->orWhere(fn ($q) => $this->searchProducts($q, $this->excludedProductSearch));
        }

        return view('livewire.admin.user.customer-price-manager', [
            'customer' => $customer, 'account' => $account, 'catalog' => $catalog, 'rules' => $rules,
            'groupRules' => $catalog && $account ? $catalog->discountRules()->get()->filter(fn ($rule) => in_array((int) $account->customer_group_id, $rule->customer_group_ids, true)) : collect(),
            'legacyReferences' => $catalog && $account ? app(LegacyGroupDiscountReferenceService::class)->forGroup($catalog, (int) $account->customer_group_id) : collect(),
            'categories' => $categories, 'categoryLabels' => $categoryLabels,
            'manufacturers' => $manufacturers, 'manufacturerLabels' => $manufacturerLabels,
            'excludedProducts' => $excludedQuery->limit(50)->get(), 'products' => $products, 'priceRows' => $priceRows,
            'canUpdate' => $this->can('update'), 'canDelete' => $this->can('delete'),
            'accessAvailable' => app(B2BAccessService::class)->approvedAccount($customer) !== null,
            'pricesIncludeTax' => app(TaxPricingService::class)->pricesIncludeTax(),
        ]);
    }

    private function rulesQuery(): Builder
    {
        return CustomerDiscountRule::query()->where('user_id', $this->userId);
    }

    private function customer(string $operation = 'view'): User
    {
        abort_unless(auth()->user(), 403);
        $customer = User::query()->with('b2bAccount.customerGroup')->findOrFail($this->userId);
        app(CustomerDiscountService::class)->authorize($customer, auth()->user(), $operation);

        return $customer;
    }

    private function can(string $operation): bool
    {
        $actor = auth()->user();

        return (bool) ($actor && ($actor->isA('superadmin') || ($actor->can('admin.access') && $actor->can('catalog.b2b_prices.'.$operation))));
    }

    private function searchProducts(Builder $query, string $search): void
    {
        if (trim($search) !== '') {
            $query->where(fn ($q) => $q->where('sku', 'like', '%'.trim($search).'%')
                ->orWhere('code', 'like', '%'.trim($search).'%')
                ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', '%'.trim($search).'%')));
        }
    }
}
