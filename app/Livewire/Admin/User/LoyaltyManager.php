<?php

namespace App\Livewire\Admin\User;

use App\Models\Sales\Order\Order;
use App\Models\User;
use App\Models\User\LoyaltyTransaction;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;
use Silber\Bouncer\BouncerFacade as Bouncer;

class LoyaltyManager extends Component
{
    use WithPagination;

    private const PAGE_NAME = 'userLoyaltyPage';

    public string $search = '';

    public string $userId = '';

    public string $type = 'all';

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $minPoints = '';

    public string $maxPoints = '';

    public string $adjustUserSearch = '';

    public string $adjustOrderSearch = '';

    /**
     * @var array<string, mixed>
     */
    public array $adjustment = [
        'user_id' => null,
        'order_id' => null,
        'points' => 0,
        'reason' => '',
    ];

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->search = (string) request()->query('search', '');
        $queryUserId = (string) request()->query('user_id', '');
        if ($queryUserId !== '' && ctype_digit($queryUserId) && (int) $queryUserId > 0) {
            $this->userId = $queryUserId;
            $this->adjustment['user_id'] = (int) $queryUserId;
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updatedType(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updatedUserId(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updatedDateTo(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updatedMinPoints(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updatedMaxPoints(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function saveManualAdjustment(): void
    {
        $current = auth()->user();
        abort_unless(
            $current && (Bouncer::is($current)->an('superadmin') || $current->can('users.loyalty.adjust')),
            403
        );

        $loyaltyService = app(LoyaltyService::class);
        if (! $loyaltyService->enabled()) {
            $this->dispatch('notify', type: 'warning', message: __('Loyalty system is disabled.'));

            return;
        }

        $validated = $this->validate([
            'adjustment.user_id' => ['required', 'integer', 'exists:users,id'],
            'adjustment.order_id' => [
                'nullable',
                'integer',
                Rule::exists('orders', 'id')->where(
                    fn ($query) => $query->where('user_id', (int) ($this->adjustment['user_id'] ?? 0))
                ),
            ],
            'adjustment.points' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'],
            'adjustment.reason' => ['required', 'string', 'min:3', 'max:400'],
        ]);

        $userId = (int) $validated['adjustment']['user_id'];
        $orderId = $validated['adjustment']['order_id'] ? (int) $validated['adjustment']['order_id'] : null;
        $points = (int) $validated['adjustment']['points'];
        $reason = trim((string) $validated['adjustment']['reason']);

        if (! $loyaltyService->availableForUser($userId)) {
            $this->addError('adjustment.user_id', __('Selected user is not eligible for loyalty.'));
            $this->dispatch('notify', type: 'warning', message: __('Selected user is not eligible for loyalty.'));

            return;
        }

        LoyaltyTransaction::query()->create([
            'user_id' => $userId,
            'order_id' => $orderId,
            'event_key' => 'manual:'.Str::uuid()->toString(),
            'type' => 'manual_adjustment',
            'points' => $points,
            'note' => $reason,
            'payload' => [
                'source' => 'admin',
            ],
            'created_by' => auth()->id(),
        ]);

        activity('loyalty')
            ->causedBy(auth()->user())
            ->event('manual_adjustment')
            ->withProperties([
                'user_id' => $userId,
                'order_id' => $orderId,
                'points' => $points,
                'reason' => $reason,
            ])
            ->log('Manual loyalty adjustment created.');

        $this->adjustment = [
            'user_id' => null,
            'order_id' => null,
            'points' => 0,
            'reason' => '',
        ];

        $this->dispatch('notify', type: 'success', message: __('Manual loyalty adjustment saved.'));
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function render()
    {
        $perPage = app(SystemSettingsService::class)->getInt(
            'admin_items_per_page',
            (int) config('admin_ui.pagination.admin_items_per_page', 20),
            5,
            200
        );

        $query = LoyaltyTransaction::query()
            ->with([
                'user:id,name,email',
                'order:id,order_number,grand_total,currency_code',
                'creator:id,name,email',
            ])
            ->when($this->userId !== '' && ctype_digit($this->userId), function (Builder $builder): void {
                $builder->where('user_id', (int) $this->userId);
            })
            ->when($this->search !== '', function (Builder $builder): void {
                $builder->where(function (Builder $q): void {
                    $q->where('event_key', 'like', '%'.$this->search.'%')
                        ->orWhere('type', 'like', '%'.$this->search.'%')
                        ->orWhere('note', 'like', '%'.$this->search.'%')
                        ->orWhereHas('user', function (Builder $uq): void {
                            $uq->where('name', 'like', '%'.$this->search.'%')
                                ->orWhere('email', 'like', '%'.$this->search.'%');
                        });
                });
            })
            ->when($this->type !== 'all', function (Builder $builder): void {
                $builder->where('type', $this->type);
            })
            ->when($this->dateFrom !== '', function (Builder $builder): void {
                $builder->whereDate('created_at', '>=', $this->dateFrom);
            })
            ->when($this->dateTo !== '', function (Builder $builder): void {
                $builder->whereDate('created_at', '<=', $this->dateTo);
            })
            ->when($this->minPoints !== '' && is_numeric($this->minPoints), function (Builder $builder): void {
                $builder->where('points', '>=', (int) $this->minPoints);
            })
            ->when($this->maxPoints !== '' && is_numeric($this->maxPoints), function (Builder $builder): void {
                $builder->where('points', '<=', (int) $this->maxPoints);
            });

        $rows = (clone $query)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], self::PAGE_NAME);

        $stats = [
            'rows' => (clone $query)->count(),
            'points_sum' => (int) ((clone $query)->sum('points')),
            'users_count' => (clone $query)->distinct('user_id')->count('user_id'),
        ];

        $selectedUser = null;
        $selectedUserBalance = null;
        if ($this->userId !== '' && ctype_digit($this->userId)) {
            $selectedUser = User::query()
                ->whereKey((int) $this->userId)
                ->first(['id', 'name', 'email']);

            if ($selectedUser) {
                $selectedUserBalance = (int) LoyaltyTransaction::query()
                    ->where('user_id', $selectedUser->id)
                    ->sum('points');
            }
        }

        return view('livewire.admin.user.loyalty-manager', [
            'rows' => $rows,
            'perPage' => $perPage,
            'stats' => $stats,
            'typeOptions' => $this->typeOptions(),
            'selectedUser' => $selectedUser,
            'selectedUserBalance' => $selectedUserBalance,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function typeOptions(): array
    {
        return [
            'all' => __('All Types'),
            'order_settlement' => __('Order Settlement'),
            'order_redemption' => __('Order Redemption'),
            'order_reversal' => __('Order Reversal'),
            'manual_adjustment' => __('Manual Adjustment'),
        ];
    }

    public function getAdjustUserOptionsProperty()
    {
        $eligibleGroupIds = app(LoyaltyService::class)->eligibleCustomerGroupIds();

        return User::query()
            ->when($eligibleGroupIds !== [], function (Builder $builder) use ($eligibleGroupIds): void {
                $builder->whereHas('customerGroups', function (Builder $groupQuery) use ($eligibleGroupIds): void {
                    $groupQuery->whereIn('customer_groups.id', $eligibleGroupIds)
                        ->where('customer_groups.is_active', true);
                });
            })
            ->when($this->adjustUserSearch !== '', function (Builder $builder): void {
                $builder->where(function (Builder $q): void {
                    $q->where('name', 'like', '%'.$this->adjustUserSearch.'%')
                        ->orWhere('email', 'like', '%'.$this->adjustUserSearch.'%');
                });
            })
            ->orderBy('name')
            ->limit(80)
            ->get(['id', 'name', 'email']);
    }

    public function getAdjustOrderOptionsProperty()
    {
        $selectedUserId = is_numeric($this->adjustment['user_id'] ?? null)
            ? (int) $this->adjustment['user_id']
            : 0;

        return Order::query()
            ->where('user_id', $selectedUserId)
            ->when($this->adjustOrderSearch !== '', function (Builder $builder): void {
                $builder->where(function (Builder $q): void {
                    $q->where('order_number', 'like', '%'.$this->adjustOrderSearch.'%')
                        ->orWhere('customer_name', 'like', '%'.$this->adjustOrderSearch.'%')
                        ->orWhere('customer_email', 'like', '%'.$this->adjustOrderSearch.'%');
                });
            })
            ->orderByDesc('id')
            ->limit(80)
            ->get(['id', 'order_number', 'customer_name', 'customer_email']);
    }

    private function authorizeAccess(): void
    {
        $user = auth()->user();
        abort_unless(
            $user && (Bouncer::is($user)->an('superadmin') || $user->can('users.loyalty.view')),
            403
        );
    }
}
