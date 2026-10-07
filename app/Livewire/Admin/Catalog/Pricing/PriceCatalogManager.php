<?php

namespace App\Livewire\Admin\Catalog\Pricing;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Services\Pricing\CatalogGroupDiscountService;
use App\Services\Pricing\LegacyGroupDiscountReferenceService;
use App\Services\Pricing\PriceCatalogGroupOverview;
use App\Services\Pricing\PriceCatalogResolver;
use App\Services\Pricing\PriceCatalogService;
use App\Services\Pricing\TaxPricingService;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class PriceCatalogManager extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $catalogId = null;

    #[Locked]
    public ?int $entryId = null;

    #[Locked]
    public ?int $ruleId = null;

    public string $tab = 'groups';

    #[Locked]
    public ?int $selectedGroupId = null;

    public string $groupPriceSearch = '';

    #[Locked]
    public ?int $groupEntryId = null;

    #[Locked]
    public ?array $groupEntryContext = null;

    public array $groupEntryForm = [];

    public array $legacyRuleWarnings = [];

    public bool $showRuleForm = false;

    public array $ruleForm = [];

    public ?array $rulePreview = null;

    public string $ruleSearch = '';

    public string $categorySearch = '';

    public string $excludedProductSearch = '';

    public string $groupProductId = '';

    public array $groupPrices = [];

    #[Locked]
    public array $groupPricesBaseline = [];

    #[Locked]
    public ?array $groupProduct = null;

    public string $name = '';

    public string $search = '';

    public string $productSearch = '';

    public string $customerSearch = '';

    public string $kindFilter = '';

    public array $form = [];

    public array $simulation = ['product_id' => '', 'user_id' => '', 'quantity' => 1, 'at' => ''];

    public ?array $preview = null;

    public function mount(): void
    {
        $this->ensurePricingAbility();
        $this->catalogId = app(PriceCatalogResolver::class)->activeCatalog()?->id ?? PriceCatalog::query()->latest('id')->value('id');
        $this->resetEntry();
        $this->resetRule();
    }

    public function selectCatalog(int $id): void
    {
        $this->ensurePricingAbility();
        $this->catalogId = PriceCatalog::query()->findOrFail($id)->id;
        $this->resetEntry();
        $this->resetPage();
        $this->preview = null;
        $this->resetRule();
        $this->groupProductId = '';
        $this->groupProduct = null;
        $this->groupPrices = [];
        $this->groupPricesBaseline = [];
        $this->cancelGroupEntry();
        $this->groupPriceSearch = '';
    }

    public function createDraft(): void
    {
        $catalog = app(PriceCatalogService::class)->createDraft($this->name, auth()->user());
        $this->name = '';
        $this->selectCatalog($catalog->id);
        $this->notice('Radni cjenik je kreiran.');
    }

    public function cloneCatalog(): void
    {
        $catalog = app(PriceCatalogService::class)->cloneToDraft($this->catalog(), auth()->user());
        $this->selectCatalog($catalog->id);
        $this->notice('Radna kopija je spremna. Objavljene cijene ostaju nepromijenjene.');
    }

    public function activate(): void
    {
        app(PriceCatalogService::class)->activate($this->catalog(), auth()->user());
        $this->resetEntry();
        $this->resetRule();
        $this->notice('Cjenik je objavljen. Prethodna verzija ostaje sačuvana.');
    }

    public function updatedFormKind(): void
    {
        $this->form['user_id'] = '';
        $this->form['customer_group_id'] = '';
    }

    public function switchTab(string $tab): void
    {
        $this->ensurePricingAbility();
        abort_unless(in_array($tab, ['groups', 'rules', 'product', 'check', 'advanced', 'history'], true), 404);
        $this->tab = $tab;
        $this->resetValidation();
        $this->resetPage();
    }

    public function openGroup(int $id): void
    {
        $this->ensurePricingAbility();
        $this->selectedGroupId = CustomerGroup::query()->findOrFail($id)->id;
        $this->groupPriceSearch = '';
        $this->cancelGroupEntry();
        $this->switchTab('groups');
        $this->resetPage('groupPricesPage');
    }

    public function backToGroups(): void
    {
        $this->ensurePricingAbility();
        $this->selectedGroupId = null;
        $this->cancelGroupEntry();
        $this->resetPage('groupPricesPage');
    }

    public function beginGroupEditing(): void
    {
        $this->ensurePricingAbility('update');
        $catalog = $this->catalog();
        if ($catalog->status === PriceCatalog::DRAFT) {
            return;
        }
        $draft = PriceCatalog::query()->where('status', PriceCatalog::DRAFT)
            ->where('metadata->cloned_from', $catalog->id)->latest('id')->first();
        $draft ??= app(PriceCatalogService::class)->cloneToDraft($catalog, auth()->user());
        $this->selectCatalog($draft->id);
        $this->notice('Otvorena je radna kopija. Objavljene cijene nisu promijenjene.');
    }

    public function newGroupRule(): void
    {
        $this->ensureDraft();
        $group = CustomerGroup::query()->where('is_active', true)->findOrFail($this->selectedGroupId);
        $this->newRule();
        $this->ruleForm['customer_group_ids'] = [$group->id];
        $this->ruleForm['name'] = $group->name.' – popust';
        $this->switchTab('rules');
    }

    public function prepareLegacyRule(int $id): void
    {
        $this->ensureDraft();
        $reference = app(LegacyGroupDiscountReferenceService::class)->draftData($this->catalog(), $id);
        abort_unless($this->selectedGroupId && in_array($this->selectedGroupId, $reference['form']['customer_group_ids'], true), 404);
        $this->newRule();
        $this->ruleForm = $reference['form'];
        $this->legacyRuleWarnings = $reference['warnings'];
        $this->switchTab('rules');
    }

    public function editGroupEntry(int $id): void
    {
        $this->ensureDraft();
        abort_unless($this->selectedGroupId, 404);
        $entry = $this->catalog()->entries()->where('customer_group_id', $this->selectedGroupId)->whereNull('user_id')->findOrFail($id);
        if ($entry->discount_rule_id) {
            $this->editRule($entry->discount_rule_id);
            $this->switchTab('rules');

            return;
        }
        $this->groupEntryId = $entry->id;
        $this->groupEntryContext = ['product_id' => $entry->product_id, 'sku' => $entry->product?->sku ?? $entry->product?->code,
            'source_key' => $entry->source_key, 'kind' => $entry->kind, 'minimum_quantity' => $entry->minimum_quantity];
        $this->groupEntryForm = $entry->only(['price', 'priority', 'is_active']);
        $this->groupEntryForm['starts_at'] = $entry->starts_at?->format('Y-m-d\TH:i:s') ?? '';
        $this->groupEntryForm['ends_at'] = $entry->ends_at?->format('Y-m-d\TH:i:s') ?? '';
        $this->resetValidation();
        $this->dispatch('group-price-editor-opened');
    }

    public function saveGroupEntry(): void
    {
        $this->ensureDraft();
        abort_unless($this->selectedGroupId && $this->groupEntryId, 404);
        $entry = $this->catalog()->entries()->where('customer_group_id', $this->selectedGroupId)->whereNull('user_id')->findOrFail($this->groupEntryId);
        // Never let a narrow group editor reassign a product, audience or entry kind.
        $data = $entry->only(['product_id', 'kind', 'customer_group_id', 'user_id', 'minimum_quantity', 'price', 'priority', 'starts_at', 'ends_at', 'is_active']);
        foreach (['price', 'priority', 'starts_at', 'ends_at', 'is_active'] as $field) {
            $data[$field] = $this->groupEntryForm[$field] ?? $data[$field];
        }
        $data['price'] = str_replace(',', '.', trim((string) $data['price']));
        app(PriceCatalogService::class)->saveEntry($this->catalog(), $data, auth()->user(), $entry->id);
        $this->cancelGroupEntry();
        $this->notice('Postojeća cijena je izmijenjena u radnoj kopiji. Objavljeni cjenik ostaje nepromijenjen.');
    }

    public function cancelGroupEntry(): void
    {
        $this->groupEntryId = null;
        $this->groupEntryContext = null;
        $this->groupEntryForm = [];
        $this->resetValidation();
    }

    public function openGroupRule(int $id): void
    {
        $this->ensureDraft();
        $rule = $this->catalog()->discountRules()->findOrFail($id);
        abort_unless($this->selectedGroupId && in_array($this->selectedGroupId, $rule->customer_group_ids, true), 404);
        $this->editRule($id);
    }

    public function openGroupProduct(int $id): void
    {
        $this->ensurePricingAbility();
        $this->groupProductId = (string) Product::query()->findOrFail($id)->id;
        $this->loadGroupPrices();
        $this->switchTab('product');
    }

    public function updatedGroupPriceSearch(): void
    {
        $this->resetPage('groupPricesPage');
    }

    public function newRule(): void
    {
        $this->ensureDraft();
        $this->resetRule();
        $this->showRuleForm = true;
        $this->switchTab('rules');
    }

    public function resetRule(): void
    {
        $this->ruleId = null;
        $this->legacyRuleWarnings = [];
        $this->showRuleForm = false;
        $this->rulePreview = null;
        $this->ruleForm = ['name' => '', 'customer_group_ids' => [], 'percent' => '', 'manufacturer_ids' => [], 'category_ids' => [], 'include_descendants' => true, 'excluded_product_ids' => [], 'starts_at' => '', 'ends_at' => '', 'priority' => 0, 'is_active' => true];
        $this->resetValidation();
    }

    public function editRule(int $id): void
    {
        $this->ensureDraft();
        $rule = $this->catalog()->discountRules()->findOrFail($id);
        $this->ruleId = $rule->id;
        $this->legacyRuleWarnings = [];
        $this->ruleForm = $rule->only(['name', 'customer_group_ids', 'percent', 'manufacturer_ids', 'category_ids', 'include_descendants', 'excluded_product_ids', 'priority', 'is_active']);
        $this->ruleForm['starts_at'] = $rule->starts_at?->format('Y-m-d\TH:i') ?? '';
        $this->ruleForm['ends_at'] = $rule->ends_at?->format('Y-m-d\TH:i') ?? '';
        $this->rulePreview = null;
        $this->showRuleForm = true;
        $this->resetValidation();
        $this->switchTab('rules');
    }

    public function updatedRuleForm(): void
    {
        $this->rulePreview = null;
    }

    public function previewRule(): void
    {
        $this->ensureDraft();
        $this->rulePreview = null;
        $this->rulePreview = app(CatalogGroupDiscountService::class)->previewRule($this->catalog(), $this->ruleData(), auth()->user());
    }

    public function saveRule(): void
    {
        $this->ensureDraft();
        app(CatalogGroupDiscountService::class)->saveRule($this->catalog(), $this->ruleData(), auth()->user(), $this->ruleId);
        $this->resetRule();
        $this->preview = null;
        $this->notice('Popust je spremljen u radni cjenik. Kupcima će vrijediti tek nakon objave.');
    }

    public function deleteRule(int $id): void
    {
        app(CatalogGroupDiscountService::class)->deleteRule($this->catalog(), $id, auth()->user());
        $this->resetRule();
        $this->preview = null;
        $this->notice('Pravilo je uklonjeno iz radne verzije. Uvezene cijene ostaju sačuvane.');
    }

    public function updatedGroupProductId(): void
    {
        $this->loadGroupPrices();
    }

    public function loadGroupPrices(): void
    {
        $this->ensurePricingAbility();
        $this->groupPrices = [];
        $this->groupPricesBaseline = [];
        $this->groupProduct = null;
        if ($this->groupProductId === '') {
            return;
        }
        $this->validate(['groupProductId' => 'required|integer|exists:products,id']);
        $product = Product::query()->with('translations')->findOrFail($this->groupProductId);
        $base = $this->catalog()->entries()->where('product_id', $product->id)->where('kind', PriceCatalogEntry::BASE)
            ->where('is_active', true)->orderBy('priority')->orderBy('price')->orderBy('id')->value('price') ?? $product->base_price;
        $this->groupProduct = ['id' => $product->id, 'sku' => $product->sku ?: $product->code, 'name' => $product->translations->firstWhere('locale', app()->getLocale())?->name ?? $product->code, 'base_price' => number_format((float) $base, 4, '.', '')];
        $entries = $this->catalog()->entries()->where('product_id', $product->id)->where('kind', PriceCatalogEntry::GROUP)
            ->where('is_active', true)->whereNull('user_id')->where('minimum_quantity', 1)
            ->whereNull('starts_at')->whereNull('ends_at')
            ->orderByRaw("CASE WHEN source_key LIKE 'manual-group:%' THEN 0 ELSE 1 END")
            ->orderBy('priority')->orderBy('price')->orderBy('id')->get();
        foreach (CustomerGroup::query()->where('is_active', true)->get() as $group) {
            $this->groupPrices[$group->id] = $entries->firstWhere('customer_group_id', $group->id)?->price ?? '';
        }
        $this->groupPricesBaseline = $this->groupPrices;
    }

    public function saveGroupPrices(): void
    {
        $this->ensureDraft();
        $this->validate(['groupProductId' => 'required|integer|exists:products,id']);
        if (! $this->groupProduct || $this->groupProduct['id'] !== (int) $this->groupProductId) {
            throw ValidationException::withMessages(['groupProductId' => 'Najprije učitajte cijene odabranog artikla.']);
        }
        $prices = array_map(fn ($price) => is_scalar($price) ? str_replace(',', '.', trim((string) $price)) : $price, $this->groupPrices);
        foreach ($prices as $groupId => $price) {
            if (is_string($price) && preg_match('/^\d{1,16}(\.\d{1,4})?$/', $price)
                && (string) BigDecimal::of($price)->toScale(4) === ($this->groupPricesBaseline[$groupId] ?? null)) {
                unset($prices[$groupId]);
            }
        }
        $count = app(PriceCatalogService::class)->saveGroupPrices($this->catalog(), (int) $this->groupProductId, $prices, auth()->user());
        $this->loadGroupPrices();
        $this->preview = null;
        $this->notice($count ? 'Cijene grupa su spremljene u radni cjenik.' : 'Nema promijenjenih cijena za spremanje.');
    }

    private function ruleData(): array
    {
        $data = $this->ruleForm;
        $data['percent'] = str_replace(',', '.', trim((string) ($data['percent'] ?? '')));
        foreach (['starts_at', 'ends_at'] as $key) {
            $data[$key] = ($data[$key] ?? '') === '' ? null : $data[$key];
        }

        return $data;
    }

    private function ensureDraft(): void
    {
        $this->ensurePricingAbility('update');
        if ($this->catalog()->status !== PriceCatalog::DRAFT) {
            throw ValidationException::withMessages(['catalog' => 'Objavljeni cjenik je zaključan. Najprije napravite novu radnu kopiju.']);
        }
    }

    public function editEntry(int $id): void
    {
        $this->ensurePricingAbility('update');
        $catalog = $this->catalog();
        if ($catalog->status !== PriceCatalog::DRAFT) {
            throw ValidationException::withMessages(['catalog' => 'Objavljeni cjenik je zaključan. Napravite radnu kopiju.']);
        }
        $entry = $catalog->entries()->findOrFail($id);
        $this->entryId = $entry->id;
        $this->form = $entry->only(['product_id', 'kind', 'customer_group_id', 'user_id', 'minimum_quantity', 'price', 'priority', 'is_active']);
        $this->form['starts_at'] = $entry->starts_at?->format('Y-m-d\TH:i') ?? '';
        $this->form['ends_at'] = $entry->ends_at?->format('Y-m-d\TH:i') ?? '';
    }

    public function resetEntry(): void
    {
        $this->entryId = null;
        $this->form = ['product_id' => '', 'kind' => PriceCatalogEntry::GROUP, 'customer_group_id' => '', 'user_id' => '', 'minimum_quantity' => 1, 'price' => '', 'priority' => 0, 'starts_at' => '', 'ends_at' => '', 'is_active' => true];
        $this->resetValidation();
    }

    public function saveEntry(): void
    {
        $data = $this->form;
        foreach (['customer_group_id', 'user_id', 'starts_at', 'ends_at'] as $key) {
            $data[$key] = $data[$key] === '' ? null : $data[$key];
        }
        app(PriceCatalogService::class)->saveEntry($this->catalog(), $data, auth()->user(), $this->entryId);
        $this->resetEntry();
        $this->notice('Cijena je spremljena u radni cjenik.');
    }

    public function deleteEntry(int $id): void
    {
        app(PriceCatalogService::class)->deleteEntry($this->catalog(), $id, auth()->user());
        $this->resetEntry();
        $this->notice('Stavka je uklonjena iz radnog cjenika.');
    }

    public function simulate(): void
    {
        $this->ensurePricingAbility();
        $this->preview = null;
        $this->validate(['simulation.product_id' => 'required|integer|exists:products,id', 'simulation.user_id' => 'required|integer|exists:users,id', 'simulation.quantity' => 'required|integer|min:1|max:999999', 'simulation.at' => 'nullable|date']);
        $product = Product::query()->findOrFail($this->simulation['product_id']);
        $user = User::query()->findOrFail($this->simulation['user_id']);
        $price = app(PriceCatalogResolver::class)->resolve($product, $user, (int) $this->simulation['quantity'], catalog: $this->catalog(), at: $this->simulation['at'] ?: null);
        if (! $price) {
            $this->preview = null;
            throw ValidationException::withMessages(['simulation.user_id' => 'Kupac nema važeći odobren B2B račun i aktivnu grupu.']);
        }
        $entry = $price->catalog_entry_id ? $this->catalog()->entries()->find($price->catalog_entry_id) : null;
        $this->preview = ['price' => number_format($price->price, 4, '.', ''), 'source' => $price->source_type, 'source_label' => PriceCatalogEntry::kindOptions()[str_replace('price_catalog_', '', $price->source_type)] ?? $price->source_type, 'source_key' => $entry?->source_key, 'entry_id' => $price->catalog_entry_id, 'customer_group_id' => $price->customer_group_id, 'group_name' => $user->b2bAccount?->customerGroup?->name ?? '#'.$price->customer_group_id, 'rule_name' => $entry?->discountRule?->name];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedKindFilter(): void
    {
        $this->resetPage();
    }

    public function updatedRuleSearch(): void
    {
        $this->resetPage('rulesPage');
    }

    public function render()
    {
        $this->ensurePricingAbility();
        $catalog = $this->catalogId ? $this->catalog() : null;
        $products = Product::query()->with('translations')->where(function ($q): void {
            $q->where('id', $this->form['product_id'] ?: 0)->orWhere('id', $this->simulation['product_id'] ?: 0);
            $q->orWhere('id', $this->groupProductId ?: 0);
            if ($this->productSearch !== '') {
                $q->orWhere('sku', 'like', '%'.$this->productSearch.'%')->orWhere('code', 'like', '%'.$this->productSearch.'%')->orWhereHas('translations', fn ($t) => $t->where('name', 'like', '%'.$this->productSearch.'%'));
            }
        })->limit(50)->get();
        $customers = User::query()->whereHas('b2bAccount')->where(function ($q): void {
            $q->where('id', $this->form['user_id'] ?: 0)->orWhere('id', $this->simulation['user_id'] ?: 0);
            if ($this->customerSearch !== '') {
                $q->orWhere('email', 'like', '%'.$this->customerSearch.'%')->orWhere('name', 'like', '%'.$this->customerSearch.'%')->orWhereHas('b2bAccount', fn ($a) => $a->where('company_name', 'like', '%'.$this->customerSearch.'%')->orWhere('oib', 'like', '%'.$this->customerSearch.'%'));
            }
        })->with('b2bAccount')->limit(50)->get();

        $categories = Category::query()->where('scope', Category::SCOPE_CATALOG)->with('translations')->orderBy('_lft')->get();
        $categoryIndex = $categories->keyBy('id');
        $categoryLabels = [];
        foreach ($categories as $category) {
            $parts = [];
            $visited = [];
            $node = $category;
            while ($node && ! isset($visited[$node->id])) {
                $visited[$node->id] = true;
                array_unshift($parts, $node->translations->firstWhere('locale', app()->getLocale())?->name ?? $node->code);
                $node = $categoryIndex->get($node->parent_id);
            }
            $categoryLabels[$category->id] = implode(' › ', $parts);
        }
        $actor = auth()->user();
        $groupProducts = null;
        $groupRows = [];
        $selectedGroup = $this->selectedGroupId ? CustomerGroup::query()->findOrFail($this->selectedGroupId) : null;
        if ($catalog && $this->tab === 'groups' && $selectedGroup) {
            $overview = app(PriceCatalogGroupOverview::class);
            $groupProducts = $overview->products($catalog, $selectedGroup->id, $this->groupPriceSearch)->paginate(20, pageName: 'groupPricesPage');
            $groupRows = $overview->rows($catalog, $selectedGroup->id, $groupProducts->getCollection());
        }

        return view('livewire.admin.catalog.pricing.price-catalog-manager', [
            'catalog' => $catalog, 'catalogs' => PriceCatalog::query()->latest('id')->limit(100)->get(),
            'selectedGroup' => $selectedGroup, 'groupProducts' => $groupProducts, 'groupRows' => $groupRows,
            'groupOverview' => $catalog && $this->tab === 'groups' && ! $selectedGroup ? app(PriceCatalogGroupOverview::class)->counts($catalog) : collect(),
            'legacyReferences' => $catalog && $this->tab === 'groups' && $selectedGroup ? app(LegacyGroupDiscountReferenceService::class)->forGroup($catalog, $selectedGroup->id) : collect(),
            'rows' => $this->tab === 'advanced' ? $catalog?->entries()->with(['product.translations', 'customerGroup', 'user.b2bAccount'])
                ->when($this->kindFilter !== '', fn ($q) => $q->where('kind', $this->kindFilter))
                ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('source_key', 'like', '%'.$this->search.'%')->orWhereHas('product', fn ($p) => $p->where('sku', 'like', '%'.$this->search.'%')->orWhere('code', 'like', '%'.$this->search.'%')->orWhereHas('translations', fn ($t) => $t->where('name', 'like', '%'.$this->search.'%')))->orWhereHas('user', fn ($u) => $u->where('email', 'like', '%'.$this->search.'%')->orWhere('name', 'like', '%'.$this->search.'%'))))
                ->orderByDesc('id')->paginate(30) : null,
            'discountRules' => $catalog?->discountRules()->when($this->ruleSearch !== '', fn ($q) => $q->where('name', 'like', '%'.$this->ruleSearch.'%'))->orderBy('priority')->orderByDesc('id')->paginate(15, pageName: 'rulesPage'),
            'products' => $products, 'customers' => $customers, 'groups' => CustomerGroup::query()->orderBy('name')->get(),
            'manufacturers' => Manufacturer::query()->with('translations')->orderBy('code')->get(),
            'categories' => $categories, 'categoryLabels' => $categoryLabels,
            'canCreatePricing' => $actor->isA('superadmin') || $actor->can('catalog.b2b_prices.create'),
            'canEditPricing' => $actor->isA('superadmin') || $actor->can('catalog.b2b_prices.update'),
            'canDeletePricing' => $actor->isA('superadmin') || $actor->can('catalog.b2b_prices.delete'),
            'excludedProducts' => Product::query()->with('translations')->where(function ($q): void {
                $q->whereIn('id', $this->ruleForm['excluded_product_ids'] ?? []);
                if ($this->excludedProductSearch !== '') {
                    $q->orWhere('sku', 'like', '%'.$this->excludedProductSearch.'%')->orWhere('code', 'like', '%'.$this->excludedProductSearch.'%')->orWhereHas('translations', fn ($t) => $t->where('name', 'like', '%'.$this->excludedProductSearch.'%'));
                }
            })->limit(50)->get(),
            'kinds' => PriceCatalogEntry::kindOptions(), 'manualKinds' => PriceCatalogEntry::manualKindOptions(), 'audits' => $catalog?->audits()->with('actor')->limit(15)->get() ?? collect(),
            'pricesIncludeTax' => app(TaxPricingService::class)->pricesIncludeTax(),
        ]);
    }

    private function catalog(): PriceCatalog
    {
        return PriceCatalog::query()->findOrFail($this->catalogId);
    }

    private function ensurePricingAbility(string $operation = 'view'): void
    {
        abort_unless(auth()->user(), 401);
        app(PriceCatalogService::class)->authorize(auth()->user(), $operation);
    }

    private function notice(string $message): void
    {
        $this->dispatch('notify', type: 'success', message: $message);
    }
}
