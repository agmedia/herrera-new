<?php

namespace App\Livewire\Admin\Catalog\Attribute;

use App\Models\Catalog\Attribute\AttributeGroup;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class GroupManager extends Component
{
    use WithPagination;

    public string $search = '';

    public string $locale = 'en';

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

    public function delete(int $groupId): void
    {
        try {
            $result = DB::transaction(function () use ($groupId): array {
                $group = AttributeGroup::query()->lockForUpdate()->find($groupId);
                if (! $group) {
                    return ['status' => 'not_found'];
                }

                if ($group->attributes()->exists()) {
                    return ['status' => 'used'];
                }

                $properties = [
                    'attribute_group_id' => $group->id,
                    'code' => $group->code,
                ];

                $group->delete();

                return [
                    'status' => 'deleted',
                    'group' => $group,
                    'properties' => $properties,
                ];
            });
        } catch (QueryException $exception) {
            $group = AttributeGroup::query()->find($groupId);

            if ($group?->attributes()->exists()) {
                $this->dispatchGroupInUseNotification();

                return;
            }

            if (! $group) {
                $this->dispatchGroupNotFoundNotification();

                return;
            }

            throw $exception;
        }

        if ($result['status'] === 'not_found') {
            $this->dispatchGroupNotFoundNotification();

            return;
        }

        if ($result['status'] === 'used') {
            $this->dispatchGroupInUseNotification();

            return;
        }

        /** @var AttributeGroup $group */
        $group = $result['group'];

        activity('catalog_attributes')
            ->performedOn($group)
            ->causedBy(auth()->user())
            ->event('group_deleted')
            ->withProperties($result['properties'])
            ->log('Attribute group deleted');

        $this->dispatch('notify', type: 'success', message: __('Attribute group deleted.'));
        $this->resetPage();
    }

    private function dispatchGroupNotFoundNotification(): void
    {
        $this->dispatch('notify', type: 'warning', message: __('Attribute group not found.'));
    }

    private function dispatchGroupInUseNotification(): void
    {
        $this->dispatch(
            'notify',
            type: 'warning',
            message: __('Delete the attributes in this group first.')
        );
    }

    public function render()
    {
        $perPage = app(SystemSettingsService::class)->getInt(
            'admin_items_per_page',
            (int) config('admin_ui.pagination.admin_items_per_page', 20),
            5,
            200
        );

        $productCount = DB::table('catalog_attribute_product as links')
            ->join('catalog_attributes as linked_attributes', 'linked_attributes.id', '=', 'links.attribute_id')
            ->whereColumn('linked_attributes.attribute_group_id', 'catalog_attribute_groups.id')
            ->selectRaw('count(distinct links.product_id)');

        $rows = AttributeGroup::query()
            ->withCount('attributes')
            ->selectSub($productCount, 'products_count')
            ->with([
                'attributes:id,attribute_group_id,payload',
                'translations' => fn ($query) => $query->where('locale', $this->locale),
            ])
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($nested): void {
                    $nested->where('code', 'like', '%'.$this->search.'%')
                        ->orWhereHas('translations', function ($translations): void {
                            $translations->where('name', 'like', '%'.$this->search.'%');
                        })
                        ->orWhereHas('attributes', function ($attributes): void {
                            $attributes->where('code', 'like', '%'.$this->search.'%')
                                ->orWhereHas('translations', function ($translations): void {
                                    $translations->where('name', 'like', '%'.$this->search.'%');
                                });
                        });
                });
            })
            ->orderBy('sort_order')
            ->orderBy('code')
            ->paginate($perPage);

        return view('livewire.admin.catalog.attribute.group-manager', [
            'rows' => $rows,
            'perPage' => $perPage,
        ]);
    }
}
