<?php

namespace App\Livewire\Admin\Content\Block;

use App\Models\Content\ContentBlock;
use App\Models\Content\ContentBlockSlot;
use App\Services\Content\ContentBlockResolver;
use App\Services\Settings\SystemSettingsService;
use App\Support\HerreraHomeWidgets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
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

    public ?int $previewSlotId = null;

    public string $locale;

    public function mount(): void
    {
        $this->authorizeAccess('view');
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

    public function openPreview(int $id, ?int $slotId = null): void
    {
        $this->authorizeAccess('view');
        $exists = ContentBlock::query()->whereKey($id)->exists();
        $this->previewBlockId = $exists ? $id : null;
        $this->previewSlotId = $exists ? $slotId : null;
    }

    public function closePreview(): void
    {
        $this->previewBlockId = null;
        $this->previewSlotId = null;
    }

    public function moveHomeWidget(int $slotId, string $direction): void
    {
        $this->authorizeAccess('update');
        abort_unless(HerreraHomeWidgets::isHerreraStore(), 404);
        if (! in_array($direction, ['up', 'down'], true)) {
            return;
        }

        $changed = DB::transaction(function () use ($slotId, $direction): bool {
            $slot = $this->homeSlots()->with('block')->find($slotId);
            abort_unless($slot, 404);
            $slots = $this->homeSlots()->where('placement', $slot->placement)
                ->orderBy('id')->lockForUpdate()->get()->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();
            $position = $slots->search(fn (ContentBlockSlot $candidate): bool => (int) $candidate->id === $slotId);
            if ($position === false) {
                return false;
            }
            $next = $position + ($direction === 'up' ? -1 : 1);
            if (! $slots->has($next)) {
                return false;
            }

            $ordered = $slots->all();
            [$ordered[$position], $ordered[$next]] = [$ordered[$next], $ordered[$position]];
            foreach ($ordered as $index => $orderedSlot) {
                $sortOrder = ($index + 1) * 10;
                if ((int) $orderedSlot->sort_order !== $sortOrder) {
                    $orderedSlot->update(['sort_order' => $sortOrder, 'updated_by' => auth()->id()]);
                }
            }
            $this->logHomeWidgetChange($slot, 'Homepage block order changed.', ['direction' => $direction]);

            return true;
        }, 3);

        if ($changed) {
            ContentBlockResolver::bumpCacheVersion();
        }
    }

    public function toggleHomeWidget(int $slotId): void
    {
        $this->authorizeAccess('update');
        abort_unless(HerreraHomeWidgets::isHerreraStore(), 404);

        DB::transaction(function () use ($slotId): void {
            $slot = $this->homeSlots()->with('block')->lockForUpdate()->find($slotId);
            abort_unless($slot, 404);
            abort_unless($slot->block->is_active, 422);
            $active = ! $slot->is_active;
            $slot->update(['is_active' => $active, 'updated_by' => auth()->id()]);
            $this->logHomeWidgetChange($slot, 'Homepage block visibility changed.', ['is_active' => $active]);
        }, 3);

        ContentBlockResolver::bumpCacheVersion();
    }

    public function delete(int $id): void
    {
        $this->authorizeAccess('delete');
        $block = ContentBlock::query()->with('slots')->findOrFail($id);
        $code = (string) $block->code;
        abort_if(in_array($code, HerreraHomeWidgets::FIXED_CODES, true), 422, __('Isključite widget u postavkama prikaza.'));
        $databaseManaged = HerreraHomeWidgets::usesDatabaseEditor((string) $block->type, $code, '')
            || $block->slots->contains(fn (ContentBlockSlot $slot): bool => HerreraHomeWidgets::usesDatabaseEditor((string) $block->type, $code, $slot->placement));

        activity('content_blocks')
            ->performedOn($block)
            ->causedBy(auth()->user())
            ->event('deleted')
            ->withProperties(['code' => $code])
            ->log('Content block deleted');

        $block->delete();
        if (! $databaseManaged) {
            $this->deleteTemplateFile($code);
        }
        ContentBlockResolver::bumpCacheVersion();

        $this->dispatch('notify', type: 'success', message: __('Content block deleted.'));
    }

    public function render()
    {
        $this->authorizeAccess('view');
        $herreraManager = HerreraHomeWidgets::isHerreraStore();
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
            ->when($herreraManager, fn (Builder $query) => $query->where(fn (Builder $blocks) => $blocks
                ->whereDoesntHave('slots')->orWhereHas('slots', fn (Builder $slots) => $this->otherSlots($slots))))
            ->when($this->search !== '', fn (Builder $query) => $this->searchBlocks($query))
            ->when(in_array($this->surface, ['desktop', 'mobile'], true), fn (Builder $query) => $query
                ->whereHas('slots', function (Builder $slots) use ($herreraManager): void {
                    if ($herreraManager) {
                        $this->otherSlots($slots);
                    }
                    $this->filterSurface($slots);
                }))
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], self::PAGE_NAME);

        $homeWidgetGroups = collect();
        if ($herreraManager) {
            $homeSlots = $this->homeSlots()->with('block')
                ->orderBy('sort_order')->orderBy('id')->get();
            $shownSlotIds = $homeSlots->modelKeys();
            if ($this->search !== '' || in_array($this->surface, ['desktop', 'mobile'], true)) {
                $shownSlotIds = $this->homeSlots()
                    ->when($this->search !== '', fn (Builder $query) => $query
                        ->whereHas('block', fn (Builder $blocks) => $this->searchBlocks($blocks)))
                    ->when(in_array($this->surface, ['desktop', 'mobile'], true), fn (Builder $query) => $this->filterSurface($query))
                    ->pluck('id')->all();
            }

            $homeWidgetGroups = collect(config('herrera_home_widgets.placements'))->map(function (string $label, string $placement) use ($homeSlots, $shownSlotIds): array {
                $slots = $homeSlots->where('placement', $placement)->values();
                $rows = $slots->map(fn (ContentBlockSlot $slot, int $index): array => [
                    'slot' => $slot,
                    'canMoveUp' => $index > 0,
                    'canMoveDown' => $index < $slots->count() - 1,
                ])->filter(fn (array $row): bool => in_array($row['slot']->id, $shownSlotIds, true));

                return ['label' => $label, 'rows' => $rows];
            })->filter(fn (array $group): bool => $group['rows']->isNotEmpty());
        }

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
            'herreraManager' => $herreraManager,
            'homeWidgetGroups' => $homeWidgetGroups,
            'canCreateBlocks' => $this->canAccess('create'),
            'canUpdateBlocks' => $this->canAccess('update'),
            'canDeleteBlocks' => $this->canAccess('delete'),
        ]);
    }

    private function homeSlots(): Builder
    {
        return ContentBlockSlot::query()->whereIn('placement', array_keys(config('herrera_home_widgets.placements')))
            ->whereNull('target_type')->whereNull('target_ref')->whereHas('block');
    }

    private function otherSlots(Builder $query): Builder
    {
        return $query->where(fn (Builder $slot) => $slot
            ->whereNotIn('placement', array_keys(config('herrera_home_widgets.placements')))
            ->orWhereNotNull('target_type')->orWhereNotNull('target_ref'));
    }

    private function filterSurface(Builder $query): Builder
    {
        return $query->where(fn (Builder $slot) => $slot->whereNull('frontend_variant')
            ->orWhere('frontend_variant', 'all')->orWhere('frontend_variant', $this->surface));
    }

    private function searchBlocks(Builder $query): Builder
    {
        $search = '%'.$this->search.'%';

        return $query->where(fn (Builder $block) => $block->where('name', 'like', $search)
            ->orWhere('code', 'like', $search)->orWhere('type', 'like', $search)
            ->orWhereHas('translations', fn (Builder $translation) => $translation->where('title', 'like', $search)));
    }

    private function logHomeWidgetChange(ContentBlockSlot $slot, string $message, array $properties): void
    {
        activity('content_blocks')->performedOn($slot->block)->causedBy(auth()->user())
            ->event('updated')->withProperties(['code' => $slot->block->code, 'slot_id' => $slot->id, 'placement' => $slot->placement] + $properties)
            ->log($message);
    }

    private function canAccess(string $action): bool
    {
        $user = auth()->user();

        return $user && ($user->account_type !== 'staff' || $user->admin_login_enabled)
            && ($user->isA('superadmin') || ($user->can('admin.access') && $user->can('content.blocks.'.$action)));
    }

    private function authorizeAccess(string $action): void
    {
        abort_unless($this->canAccess($action), 403);
    }

    private function deleteTemplateFile(string $code): void
    {
        $path = resource_path('views/front/content-blocks/instances/'.$code.'.blade.php');
        if (File::exists($path)) {
            File::delete($path);
        }
    }
}
