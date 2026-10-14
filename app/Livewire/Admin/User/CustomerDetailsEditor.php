<?php

namespace App\Livewire\Admin\User;

use App\Models\Catalog\Pricing\B2BPriceRule;
use App\Models\Catalog\Pricing\CatalogGroupDiscountRule;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Services\Admin\OrderManagerAccess;
use App\Services\User\CustomerManagementService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CustomerDetailsEditor extends Component
{
    #[Locked]
    public int $userId;

    public array $details = [];

    public array $representativeIds = [];

    public array $segmentIds = [];

    public function mount(int $userId): void
    {
        $this->userId = $userId;
        $this->authorizeView();
        $this->loadDetails();
    }

    public function save(): void
    {
        $customer = $this->authorizeView();
        app(CustomerManagementService::class)->assertCustomer($customer, auth()->user());
        $validated = $this->validate([
            'details.city' => ['nullable', 'string', 'max:120'], 'details.postal_code' => ['nullable', 'string', 'max:32'],
            'details.county' => ['nullable', 'string', 'max:120'], 'details.customer_type' => ['nullable', 'string', 'max:120'],
        ]);
        DB::transaction(function () use ($customer, $validated): void {
            $data = array_map(fn ($value) => trim((string) $value) ?: null, $validated['details']);
            $customer->profile()->updateOrCreate(['user_id' => $customer->id], ['county' => $data['county'], 'customer_type' => $data['customer_type']]);
            $location = ['city' => $data['city'], 'postal_code' => $data['postal_code']];
            if ($customer->b2bAccount) {
                $customer->b2bAccount->update($location);
            }
            $address = $customer->addresses()->where('type', 'billing')->first();
            if ($address) {
                $address->update($location);
            } elseif ($location['city'] || $location['postal_code']) {
                $customer->addresses()->create([...$location, 'type' => 'billing', 'is_default' => true]);
            }
            activity('customer-crm')->performedOn($customer)->causedBy(auth()->user())->event('directory_details_updated')
                ->withProperties($data)->log('Ažurirani podaci kupca u imeniku.');
        });
        $this->dispatch('notify', type: 'success', message: __('Podaci kupca su spremljeni.'));
    }

    public function saveRepresentatives(): void
    {
        $this->authorizeView();
        app(CustomerManagementService::class)->assignRepresentatives([$this->userId], $this->representativeIds, auth()->user());
        $this->dispatch('notify', type: 'success', message: __('Komercijalisti su ažurirani.'));
    }

    public function saveSegments(): void
    {
        $customer = $this->authorizeView();
        $service = app(CustomerManagementService::class);
        $current = $customer->customerGroups()->businessSegments()->where('is_active', true)->pluck('customer_groups.id')->all();
        $add = array_values(array_diff($this->segmentIds, $current));
        $remove = array_values(array_diff($current, $this->segmentIds));
        DB::transaction(function () use ($service, $add, $remove): void {
            if ($add) {
                $service->assignBusinessSegments([$this->userId], $add, 'add', auth()->user());
            }
            if ($remove) {
                $service->assignBusinessSegments([$this->userId], $remove, 'remove', auth()->user());
            }
        });
        $this->dispatch('notify', type: 'success', message: __('Djelatnosti i divizije su spremljene.'));
    }

    public function render()
    {
        $customer = $this->authorizeView();
        $groupId = $customer->b2bAccount?->customer_group_id;
        $catalogRules = $groupId ? CatalogGroupDiscountRule::query()->whereHas('catalog', fn ($query) => $query->where('status', 'active'))
            ->where('is_active', true)->whereJsonContains('customer_group_ids', (int) $groupId)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))->orderBy('priority')->get() : collect();
        $extraRules = B2BPriceRule::query()->activeAt()->with('targets')
            ->where(fn ($query) => $query->where('user_id', $this->userId)->orWhere(fn ($query) => $query->whereNull('user_id')->where('customer_group_id', $groupId)->whereNotNull('customer_group_id')))
            ->orderBy('priority')->get();

        return view('livewire.admin.user.customer-details-editor', [
            'customer' => $customer, 'catalogRules' => $catalogRules, 'extraRules' => $extraRules,
            'categories' => \App\Models\Catalog\Category\Category::query()->with('translations')->whereIn('id', $catalogRules->flatMap(fn ($rule) => $rule->category_ids ?? [])->unique())->get()->keyBy('id'),
            'canUpdate' => auth()->user()->isA('superadmin', 'admin') || auth()->user()->can('users.profile.update'),
            'canAssign' => app(CustomerManagementService::class)->canManageAssignments(),
            'canSegment' => auth()->user()->isA('superadmin') || auth()->user()->can('users.groups.manage'),
            'representatives' => app(CustomerManagementService::class)->representatives()->orderBy('name')->get(['id', 'name']),
            'segments' => CustomerGroup::query()->businessSegments()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    private function loadDetails(): void
    {
        $customer = User::query()->with(['profile', 'b2bAccount', 'addresses', 'salesRepresentatives', 'customerGroups'])->findOrFail($this->userId);
        $billing = $customer->addresses->firstWhere('type', 'billing');
        $this->details = [
            'city' => $customer->b2bAccount?->city ?: $billing?->city ?: '',
            'postal_code' => $customer->b2bAccount?->postal_code ?: $billing?->postal_code ?: '',
            'county' => $customer->profile?->county ?: $billing?->state ?: '', 'customer_type' => $customer->profile?->customer_type ?: '',
        ];
        $this->representativeIds = $customer->salesRepresentatives->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->segmentIds = $customer->customerGroups->filter(fn ($group) => $group->isBusinessSegment() && $group->is_active)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    private function authorizeView(): User
    {
        $actor = auth()->user();
        abort_unless($actor && ($actor->isA('superadmin', 'admin') || $actor->can('users.list.view')), 403);
        $customer = User::query()->with(['profile', 'b2bAccount', 'addresses', 'salesRepresentatives'])->findOrFail($this->userId);
        abort_unless($customer->account_type === 'customer' && ! $customer->can('admin.access'), 403);
        app(OrderManagerAccess::class)->assertCustomer($customer, $actor);

        return $customer;
    }
}
