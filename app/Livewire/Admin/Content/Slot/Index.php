<?php

namespace App\Livewire\Admin\Content\Slot;

use App\Models\Content\ContentBlockSlot;
use App\Services\Settings\SystemSettingsService;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    private const PAGE_NAME = 'contentSlotsPage';

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function delete(int $id): void
    {
        $slot = ContentBlockSlot::query()->findOrFail($id);

        activity('content_blocks')
            ->performedOn($slot)
            ->causedBy(auth()->user())
            ->event('deleted')
            ->withProperties([
                'placement' => $slot->placement,
                'target_type' => $slot->target_type,
                'target_ref' => $slot->target_ref,
            ])
            ->log(__('Content slot deleted'));

        $slot->delete();

        $this->dispatch('notify', type: 'success', message: __('Content slot deleted.'));
    }

    public function render()
    {
        $perPage = app(SystemSettingsService::class)->getInt(
            'admin_items_per_page',
            (int) config('admin_ui.pagination.admin_items_per_page', 20),
            5,
            200
        );

        $rows = ContentBlockSlot::query()
            ->with('block')
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($q): void {
                    $q->where('placement', 'like', '%'.$this->search.'%')
                        ->orWhere('target_type', 'like', '%'.$this->search.'%')
                        ->orWhere('target_ref', 'like', '%'.$this->search.'%')
                        ->orWhereHas('block', function ($bq): void {
                            $bq->where('name', 'like', '%'.$this->search.'%')
                                ->orWhere('code', 'like', '%'.$this->search.'%');
                        });
                });
            })
            ->orderBy('placement')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage, ['*'], self::PAGE_NAME);

        return view('livewire.admin.content.slot.index', [
            'rows' => $rows,
            'perPage' => $perPage,
        ]);
    }
}
