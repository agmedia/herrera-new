<?php

namespace App\Livewire\Admin\User;

use App\Models\Sales\Order\Order;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Services\Admin\OrderManagerAccess;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Settings\SystemSettingsService;
use App\Services\User\CustomerManagementService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Role;

class Manager extends Component
{
    use WithPagination;

    private const PAGE_NAME = 'adminUsersPage';

    public string $search = '';

    public string $role = '';

    public string $segment = '';

    public string $representative = '';

    public string $city = '';

    public string $postalCode = '';

    public string $county = '';

    public string $customerType = '';

    public string $visit = '';

    public string $visitSince = '';

    public string $turnoverCurrency = 'EUR';

    public string $turnoverYear = '';

    public array $selectedCustomers = [];

    public string $bulkRepresentative = '';

    public string $bulkSegment = '';

    public string $segmentMode = 'add';

    public string $sortBy = 'created_at';

    public string $sortDir = 'desc';

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->visit = in_array(request()->query('visit'), ['visited', 'unvisited'], true) ? request()->query('visit') : '';
        $this->visitSince = request()->query('visitSince', now()->subDays(60)->format('Y-m-d'));
        $this->representative = ctype_digit((string) request()->query('representative', '')) ? (string) request()->query('representative') : '';
    }

    public function updatedSearch(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updatedRole(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updatedSegment(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function sort(string $field): void
    {
        $allowed = ['id', 'name', 'email', 'email_verified_at', 'created_at', 'webshop_turnover'];
        if (! in_array($field, $allowed, true)) {
            return;
        }

        if ($this->sortBy === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $field;
            $this->sortDir = $field === 'name' || $field === 'email' ? 'asc' : 'desc';
        }

        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updated($property): void
    {
        if (in_array($property, ['representative', 'city', 'postalCode', 'county', 'customerType', 'visit', 'visitSince', 'turnoverCurrency', 'turnoverYear'], true)) {
            $this->resetPage(pageName: self::PAGE_NAME);
        }
    }

    public function assignSelectedRepresentatives(): void
    {
        $this->authorizeAccess();
        $this->validate(['selectedCustomers' => ['required', 'array', 'min:1'], 'bulkRepresentative' => ['required', 'integer', 'min:1']]);
        app(CustomerManagementService::class)->assignRepresentatives($this->selectedCustomers, [(int) $this->bulkRepresentative], auth()->user());
        $this->selectedCustomers = [];
        $this->dispatch('notify', type: 'success', message: __('Komercijalist je dodijeljen odabranim kupcima.'));
    }

    public function assignSelectedSegments(): void
    {
        $this->authorizeAccess();
        $this->validate(['selectedCustomers' => ['required', 'array', 'min:1'], 'bulkSegment' => ['required', 'integer', 'min:1'], 'segmentMode' => ['required', 'in:add,remove']]);
        app(CustomerManagementService::class)->assignBusinessSegments($this->selectedCustomers, [(int) $this->bulkSegment], $this->segmentMode, auth()->user());
        $this->selectedCustomers = [];
        $this->dispatch('notify', type: 'success', message: __('Razvrstavanje odabranih kupaca je spremljeno.'));
    }

    public function render()
    {
        $this->authorizeAccess();
        $this->validate([
            'visitSince' => ['nullable', 'date'], 'visit' => ['nullable', 'in:visited,unvisited'],
            'turnoverCurrency' => ['required', 'string', 'size:3'], 'turnoverYear' => ['nullable', 'digits:4', 'integer', 'min:1900', 'max:2200'],
            'sortBy' => ['required', 'in:id,name,email,email_verified_at,created_at,webshop_turnover'], 'sortDir' => ['required', 'in:asc,desc'],
        ]);
        $turnover = Order::query()->withoutCancelled()->selectRaw('COALESCE(SUM(grand_total - tax_total), 0)')
            ->whereColumn('orders.user_id', 'users.id')->whereRaw('UPPER(currency_code) = ?', [strtoupper($this->turnoverCurrency)])
            ->when($this->turnoverYear !== '', fn ($query) => $query->whereRaw('COALESCE(placed_at, created_at) >= ?', [$this->turnoverYear.'-01-01 00:00:00'])->whereRaw('COALESCE(placed_at, created_at) <= ?', [$this->turnoverYear.'-12-31 23:59:59']));
        $settings = app(SystemSettingsService::class);
        $perPage = $settings->getInt(
            'admin_items_per_page',
            (int) config('admin_ui.pagination.admin_items_per_page', 20),
            5,
            200
        );
        $loyaltyService = app(LoyaltyService::class);
        $loyaltyEnabled = $loyaltyService->enabled();

        $rowsQuery = app(OrderManagerAccess::class)->scopeCustomers(User::query())
            ->select('users.*')->addSelect(['webshop_turnover' => $turnover])->with([
                'salesRepresentatives:id,name',
                'roles:id,name,title',
                'customerGroups:id,name,is_active',
                'b2bAccount:id,user_id,company_name,oib,status,customer_group_id,contract_starts_at,contract_ends_at,city,postal_code',
                'b2bAccount.customerGroup:id,is_active',
                'profile:id,user_id,company,oib,county,customer_type',
                'addresses' => fn ($query) => $query->where('type', 'billing'),
            ])
            ->when($this->search !== '', function (Builder $query): void {
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($this->search)).'%';
                $query->where(function (Builder $q) use ($term): void {
                    $q->whereRaw("users.name LIKE ? ESCAPE '!'", [$term])
                        ->orWhereRaw("users.email LIKE ? ESCAPE '!'", [$term])
                        ->orWhereHas('b2bAccount', fn (Builder $account) => $account
                            ->whereRaw("company_name LIKE ? ESCAPE '!'", [$term])
                            ->orWhereRaw("oib LIKE ? ESCAPE '!'", [$term]))
                        ->orWhereHas('profile', fn (Builder $profile) => $profile
                            ->whereRaw("company LIKE ? ESCAPE '!'", [$term])
                            ->orWhereRaw("oib LIKE ? ESCAPE '!'", [$term]))
                        ->orWhereHas('addresses', fn (Builder $address) => $address
                            ->where('type', 'billing')
                            ->where(fn (Builder $fields) => $fields
                                ->whereRaw("company LIKE ? ESCAPE '!'", [$term])
                                ->orWhereRaw("oib LIKE ? ESCAPE '!'", [$term])));
                });
            })
            ->when($this->role !== '', function (Builder $query): void {
                $query->whereHas('roles', fn (Builder $q) => $q->where('name', $this->role));
            })
            ->when($this->segment !== '', function (Builder $query): void {
                $segmentId = (int) $this->segment;
                if ($segmentId > 0) {
                    $query->whereHas('customerGroups', fn (Builder $q) => $q->where('customer_groups.id', $segmentId));
                }
            })
            ->when($this->representative !== '', function (Builder $query): void {
                if ($this->representative === 'unassigned') {
                    $query->where('account_type', 'customer')->whereDoesntHave('salesRepresentatives');
                } else {
                    $query->whereHas('salesRepresentatives', fn (Builder $query) => $query->whereKey((int) $this->representative));
                }
            })
            ->when($this->city !== '', fn (Builder $query) => $this->filterLocation($query, 'city', $this->city))
            ->when($this->postalCode !== '', fn (Builder $query) => $this->filterLocation($query, 'postal_code', $this->postalCode))
            ->when($this->county !== '', function (Builder $query): void {
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($this->county)).'%';
                $query->where(fn (Builder $query) => $query->whereHas('profile', fn (Builder $profile) => $profile->whereRaw("county LIKE ? ESCAPE '!'", [$term]))
                    ->orWhereHas('addresses', fn (Builder $address) => $address->where('type', 'billing')->whereRaw("state LIKE ? ESCAPE '!'", [$term])));
            })
            ->when($this->customerType !== '', fn (Builder $query) => $query->whereHas('profile', fn (Builder $profile) => $profile->where('customer_type', $this->customerType)))
            ->when($this->visit !== '', function (Builder $query): void {
                $condition = fn (Builder $visits) => $visits->where('contact_type', 'visit')->when($this->visitSince !== '', fn (Builder $visits) => $visits->where('visited_at', '>=', \Carbon\Carbon::parse($this->visitSince)->startOfDay()));
                $query->where('account_type', 'customer');
                if ($this->visit === 'visited') {
                    $query->whereHas('customerVisits', $condition);
                } else {
                    $query->whereDoesntHave('customerVisits', $condition);
                }
            });

        if ($loyaltyEnabled) {
            $rowsQuery
                ->withSum('loyaltyTransactions as loyalty_points_balance', 'points')
                ->withCount('loyaltyTransactions as loyalty_transactions_count');
        }

        $rows = $rowsQuery
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate($perPage, ['*'], self::PAGE_NAME);

        foreach ($rows as $row) {
            $available = $loyaltyService->availableForUser($row);
            $row->setAttribute('loyalty_available', $available);

            if (! $available) {
                $row->setAttribute('loyalty_points_balance', null);
                $row->setAttribute('loyalty_transactions_count', null);
            }
        }

        $roles = Role::query()
            ->when(! $this->canSeeSuperadminRole(), fn ($query) => $query->where('name', '!=', 'superadmin'))
            ->orderBy('name')
            ->get(['name', 'title']);

        $segments = CustomerGroup::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('livewire.admin.user.manager', [
            'rows' => $rows,
            'roles' => $roles,
            'segments' => $segments,
            'perPage' => $perPage,
            'loyaltyEnabled' => $loyaltyEnabled,
            'representatives' => app(CustomerManagementService::class)->representatives()->when(app(OrderManagerAccess::class)->isRestricted(), fn ($query) => $query->whereKey(auth()->id()))->orderBy('name')->get(['id', 'name']),
            'businessSegments' => CustomerGroup::query()->businessSegments()->where('is_active', true)->orderBy('name')->get(),
            'customerTypes' => \App\Models\User\UserProfile::query()->whereIn('user_id', app(OrderManagerAccess::class)->scopeCustomers(User::query())->select('users.id'))->whereNotNull('customer_type')->where('customer_type', '!=', '')->distinct()->orderBy('customer_type')->pluck('customer_type'),
            'canAssign' => app(CustomerManagementService::class)->canManageAssignments(),
            'canSegment' => auth()->user()->isA('superadmin') || auth()->user()->can('users.groups.manage'),
        ]);
    }

    private function filterLocation(Builder $query, string $field, string $value): void
    {
        $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($value)).'%';
        $query->whereRaw("COALESCE(
            (SELECT NULLIF(TRIM(b2b_accounts.$field), '') FROM b2b_accounts WHERE b2b_accounts.user_id = users.id LIMIT 1),
            (SELECT NULLIF(TRIM(user_addresses.$field), '') FROM user_addresses WHERE user_addresses.user_id = users.id AND user_addresses.type = 'billing' ORDER BY user_addresses.is_default DESC, user_addresses.id ASC LIMIT 1)
        ) LIKE ? ESCAPE '!'", [$term]);
    }

    private function authorizeAccess(): void
    {
        $user = auth()->user();
        abort_unless(
            $user && (Bouncer::is($user)->an('superadmin') || $user->can('users.list.view')),
            403
        );
    }

    private function canSeeSuperadminRole(): bool
    {
        $current = auth()->user();

        return $current && Bouncer::is($current)->an('superadmin');
    }
}
