<?php

namespace App\Livewire\Admin\Content\Block;

use App\Models\Content\ContentBlock;
use App\Services\Content\ContentBlockResolver;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\File;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    private const PAGE_NAME = 'contentBlocksPage';

    public string $search = '';
    public string $surface = 'all';
    public ?int $previewBlockId = null;
    public string $locale;

    public function mount(): void
    {
        $this->surface = 'all';
        $this->locale = (string) (request()->query('locale') ?: config('app.locale'));
    }

    public function updatedSearch(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updatedSurface(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function openPreview(int $id): void
    {
        $exists = ContentBlock::query()->whereKey($id)->exists();
        $this->previewBlockId = $exists ? $id : null;
    }

    public function closePreview(): void
    {
        $this->previewBlockId = null;
    }

    public function delete(int $id): void
    {
        $block = ContentBlock::query()->findOrFail($id);
        $code = (string) $block->code;

        activity('content_blocks')
            ->performedOn($block)
            ->causedBy(auth()->user())
            ->event('deleted')
            ->withProperties(['code' => $code])
            ->log('Content block deleted');

        $block->delete();
        $this->deleteTemplateFile($code);
        ContentBlockResolver::bumpCacheVersion();

        $this->dispatch('notify', type: 'success', message: __('Content block deleted.'));
    }

    public function render()
    {
        $perPage = app(SystemSettingsService::class)->getInt(
            'admin_items_per_page',
            (int) config('admin_ui.pagination.admin_items_per_page', 20),
            5,
            200
        );

        $rows = ContentBlock::query()
            ->withCount(['slots', 'items'])
            ->with([
                'translations' => fn ($q) => $q->where('locale', $this->locale),
                'slots' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            ])
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($q): void {
                    $q->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('code', 'like', '%'.$this->search.'%')
                        ->orWhere('type', 'like', '%'.$this->search.'%')
                        ->orWhereHas('translations', function ($tq): void {
                            $tq->where('title', 'like', '%'.$this->search.'%');
                        });
                });
            })
            ->when(in_array($this->surface, ['desktop', 'mobile', 'all'], true), function ($query): void {
                if ($this->surface === 'all') {
                    return;
                }

                $query->whereHas('slots', function ($slotQuery): void {
                    $slotQuery->where(function ($variantQuery): void {
                        $variantQuery->whereNull('frontend_variant')
                            ->orWhere('frontend_variant', 'all')
                            ->orWhere('frontend_variant', $this->surface);
                    });
                });
            })
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], self::PAGE_NAME);

        $previewBlock = null;
        if ($this->previewBlockId) {
            $previewBlock = ContentBlock::query()
                ->withCount(['slots', 'items'])
                ->with([
                    'translations' => fn ($q) => $q->whereIn('locale', [$this->locale, config('app.locale')]),
                    'slots' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                ])
                ->find($this->previewBlockId);
        }

        return view('livewire.admin.content.block.index', [
            'rows' => $rows,
            'perPage' => $perPage,
            'previewBlock' => $previewBlock,
        ]);
    }

    private function deleteTemplateFile(string $code): void
    {
        $path = resource_path('views/front/content-blocks/instances/'.$code.'.blade.php');
        if (File::exists($path)) {
            File::delete($path);
        }
    }
}
