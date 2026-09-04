<?php

namespace App\Livewire\Admin\Catalog\Attribute;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Attribute\AttributeGroup;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class Manager extends Component
{
    use WithPagination;

    public string $search = '';

    public string $locale = 'en';

    public ?int $groupId = null;

    public function mount(?int $groupId = null): void
    {
        $this->groupId = $groupId;
        $this->locale = (string) (request()->query('locale') ?: config('app.locale', 'en'));

        if ($this->groupId) {
            AttributeGroup::query()->findOrFail($this->groupId);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedLocale(): void
    {
        $this->resetPage();
    }

    public function delete(int $attributeId): void
    {
        $attribute = Attribute::query()
            ->when(
                $this->groupId,
                fn ($query) => $query->where('attribute_group_id', $this->groupId),
            )
            ->find($attributeId);
        if (! $attribute) {
            $this->dispatch('notify', type: 'warning', message: __('Attribute not found.'));

            return;
        }

        DB::transaction(function () use ($attribute): void {
            $attribute->products()->detach();
            $attribute->translations()->delete();
            $attribute->delete();
        });

        activity('catalog_attributes')
            ->performedOn($attribute)
            ->causedBy(auth()->user())
            ->event('deleted')
            ->withProperties([
                'attribute_id' => $attributeId,
                'code' => $attribute->code,
                'group_code' => $attribute->group_code,
            ])
            ->log('Attribute deleted');

        $this->dispatch('notify', type: 'success', message: __('Attribute deleted.'));
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

        $rows = Attribute::query()
            ->when($this->groupId, fn ($query) => $query->where('attribute_group_id', $this->groupId))
            ->withCount('products')
            ->with([
                'translations' => fn ($q) => $q->where('locale', $this->locale),
            ])
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($q): void {
                    $q->where('code', 'like', '%'.$this->search.'%')
                        ->orWhere('type', 'like', '%'.$this->search.'%')
                        ->orWhereHas('translations', function ($tq): void {
                            $tq->where('group_name', 'like', '%'.$this->search.'%')
                                ->orWhere('name', 'like', '%'.$this->search.'%')
                                ->orWhere('slug', 'like', '%'.$this->search.'%');
                        });
                });
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage);

        $group = $this->groupId
            ? AttributeGroup::query()
                ->with(['translations' => fn ($query) => $query->where('locale', $this->locale)])
                ->findOrFail($this->groupId)
            : null;

        return view('livewire.admin.catalog.attribute.manager', [
            'rows' => $rows,
            'perPage' => $perPage,
            'group' => $group,
        ]);
    }
}
