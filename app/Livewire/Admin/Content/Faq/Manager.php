<?php

namespace App\Livewire\Admin\Content\Faq;

use App\Models\Content\Support\Faq;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class Manager extends Component
{
    use WithPagination;

    public string $search = '';
    public string $locale = 'en';
    public string $group = 'all';
    public string $state = 'active';

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

    public function updatedGroup(): void
    {
        $this->resetPage();
    }

    public function updatedState(): void
    {
        $this->resetPage();
    }

    public function delete(int $faqId): void
    {
        $faq = Faq::query()->find($faqId);
        if (! $faq) {
            $this->dispatch('notify', type: 'danger', message: (string) __('admin.content.faq.manager.notify_not_found'));
            return;
        }

        DB::transaction(function () use ($faq): void {
            $faq->translations()->delete();
            $faq->delete();
        });

        $this->dispatch('notify', type: 'success', message: (string) __('admin.content.faq.manager.notify_deleted'));
        $this->resetPage();
    }

    public function getGroupOptionsProperty(): Collection
    {
        return Faq::query()
            ->select('group_code')
            ->distinct()
            ->orderBy('group_code')
            ->pluck('group_code');
    }

    public function render()
    {
        $perPage = app(SystemSettingsService::class)->getInt(
            'admin_items_per_page',
            (int) config('admin_ui.pagination.admin_items_per_page', 20),
            5,
            200
        );

        $rows = Faq::query()
            ->withCount('comments')
            ->with([
                'translations' => fn ($q) => $q->where('locale', $this->locale),
            ])
            ->when($this->group !== 'all', function ($query): void {
                $query->where('group_code', $this->group);
            })
            ->when($this->state === 'active', function ($query): void {
                $query->where('is_active', true);
            })
            ->when($this->state === 'inactive', function ($query): void {
                $query->where('is_active', false);
            })
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($q): void {
                    $q->where('code', 'like', '%'.$this->search.'%')
                        ->orWhere('group_code', 'like', '%'.$this->search.'%')
                        ->orWhereHas('translations', function ($tq): void {
                            $tq->where('question', 'like', '%'.$this->search.'%')
                                ->orWhere('slug', 'like', '%'.$this->search.'%');
                        });
                });
            })
            ->orderBy('group_code')
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate($perPage);

        return view('livewire.admin.content.faq.manager', [
            'rows' => $rows,
            'perPage' => $perPage,
        ]);
    }
}
