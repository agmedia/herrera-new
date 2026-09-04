<?php

namespace App\Livewire\Admin\Catalog\Option;

use App\Models\Catalog\Option\Option;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class Manager extends Component
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

    public function delete(int $optionId): void
    {
        $option = Option::query()
            ->with('values:id,option_id,payload')
            ->find($optionId);

        if (! $option) {
            $this->dispatch('notify', type: 'warning', message: __('Option not found.'));

            return;
        }

        $swatchPaths = collect($option->values)
            ->map(fn ($value): string => trim((string) data_get($value->payload, 'swatch_image_path', '')))
            ->filter(fn (string $path): bool => $this->canDeleteStoredSwatchPath($path))
            ->unique()
            ->values()
            ->all();

        $properties = [
            'option_id' => $optionId,
            'code' => $option->code,
            'type' => $option->type,
            'value_count' => $option->values->count(),
        ];

        DB::transaction(function () use ($option): void {
            $option->delete();
        });

        if ($swatchPaths !== []) {
            Storage::disk('public')->delete($swatchPaths);
        }

        activity('catalog_options')
            ->performedOn($option)
            ->causedBy(auth()->user())
            ->event('deleted')
            ->withProperties($properties)
            ->log('Option deleted');

        $this->dispatch('notify', type: 'success', message: __('Option deleted.'));
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

        $rows = Option::query()
            ->withCount(['values', 'products'])
            ->with([
                'translations' => fn ($q) => $q->where('locale', $this->locale),
            ])
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($q): void {
                    $q->where('code', 'like', '%'.$this->search.'%')
                        ->orWhere('type', 'like', '%'.$this->search.'%')
                        ->orWhereHas('translations', function ($tq): void {
                            $tq->where('name', 'like', '%'.$this->search.'%')
                                ->orWhere('slug', 'like', '%'.$this->search.'%');
                        });
                });
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage);

        return view('livewire.admin.catalog.option.manager', [
            'rows' => $rows,
            'perPage' => $perPage,
        ]);
    }

    private function canDeleteStoredSwatchPath(?string $path): bool
    {
        $path = trim((string) $path);

        if ($path === '') {
            return false;
        }

        return ! Str::startsWith($path, ['http://', 'https://', '//', '/']);
    }
}
