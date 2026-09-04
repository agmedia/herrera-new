<?php

namespace App\Livewire\Admin\Catalog\Action;

use App\Models\Catalog\Action\CatalogAction;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class Manager extends Component
{
    use WithPagination;

    public string $search = '';
    public string $locale = 'en';
    public string $scopeFilter = 'all';
    public string $typeFilter = 'all';
    public string $stateFilter = 'active';

    public function mount(): void
    {
        $this->locale = (string) (request()->query('locale') ?: config('app.locale', 'en'));
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedLocale(): void
    {
        $this->resetPage();
    }

    public function updatedScopeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStateFilter(): void
    {
        $this->resetPage();
    }

    public function delete(int $actionId): void
    {
        $action = CatalogAction::query()->find($actionId);
        if (! $action) {
            $this->dispatch('notify', type: 'warning', message: __('Action not found.'));

            return;
        }

        $properties = [
            'action_id' => $actionId,
            'code' => $action->code,
            'scope' => $action->scope,
            'type' => $action->type,
        ];

        DB::transaction(function () use ($action): void {
            $action->delete();
        });

        activity('catalog_actions')
            ->performedOn($action)
            ->causedBy(auth()->user())
            ->event('deleted')
            ->withProperties($properties)
            ->log('Catalog action deleted');

        $this->dispatch('notify', type: 'success', message: __('Action deleted.'));
        $this->resetPage();
    }

    public function render()
    {
        $perPage = app(SystemSettingsService::class)->getInt(
            'admin_items_per_page',
            (int) config('admin_ui.pagination.admin_items_per_page', 20),
            5,
            200
        );

        $rows = CatalogAction::query()
            ->withCount('targets')
            ->with([
                'translations' => fn ($q) => $q->where('locale', $this->locale),
                'audienceCustomerGroup:id,name',
                'audienceUser:id,name,email',
            ])
            ->when($this->scopeFilter !== 'all', function ($query): void {
                $query->where('scope', $this->scopeFilter);
            })
            ->when($this->typeFilter !== 'all', function ($query): void {
                $query->where('type', $this->typeFilter);
            })
            ->when($this->stateFilter === 'active', function ($query): void {
                $query->where('is_active', true);
            })
            ->when($this->stateFilter === 'inactive', function ($query): void {
                $query->where('is_active', false);
            })
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($q): void {
                    $q->where('code', 'like', '%'.$this->search.'%')
                        ->orWhere('coupon_code', 'like', '%'.$this->search.'%')
                        ->orWhereHas('translations', function ($tq): void {
                            $tq->where('title', 'like', '%'.$this->search.'%');
                        });
                });
            })
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->paginate($perPage);

        return view('livewire.admin.catalog.action.manager', [
            'rows' => $rows,
            'perPage' => $perPage,
            'scopeLabels' => $this->scopeLabels(),
            'typeLabels' => $this->typeLabels(),
            'stateLabels' => [
                'active' => __('Active'),
                'inactive' => __('Inactive'),
                'all' => __('All'),
            ],
            'targetLabels' => $this->targetLabels(),
            'audienceLabels' => $this->audienceLabels(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function scopeLabels(): array
    {
        return [
            CatalogAction::SCOPE_PRODUCT => __('Product'),
            CatalogAction::SCOPE_CART => __('Cart'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function typeLabels(): array
    {
        return [
            CatalogAction::TYPE_PERCENTAGE => __('Percentage'),
            CatalogAction::TYPE_FIXED => __('Fixed Amount'),
            CatalogAction::TYPE_BUY_X_GET_Y => __('Buy X Get Y'),
            CatalogAction::TYPE_GIFT_ON_AMOUNT => __('Gift On Amount'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function targetLabels(): array
    {
        return [
            CatalogAction::TARGET_ALL => __('All'),
            CatalogAction::TARGET_PRODUCT => __('Products'),
            CatalogAction::TARGET_CATEGORY => __('Categories'),
            CatalogAction::TARGET_MANUFACTURER => __('Manufacturers'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function audienceLabels(): array
    {
        return [
            CatalogAction::AUDIENCE_ALL => __('All users'),
            CatalogAction::AUDIENCE_USER_GROUP => __('User group'),
            CatalogAction::AUDIENCE_USER => __('Single user'),
            CatalogAction::AUDIENCE_ROLE => __('Legacy role'),
        ];
    }
}
