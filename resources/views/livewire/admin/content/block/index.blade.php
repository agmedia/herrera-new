<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">{{ __('Content Blocks') }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ __('Unified builder: block, primary slot, and selected items.') }}</p>
                <p class="mt-2 text-xs text-slate-500">{{ __('Items per page') }}: <span class="admin-chip">{{ $perPage }}</span></p>
            </div>
            <div class="flex w-full gap-2 sm:w-auto sm:items-end">
                <div class="w-full sm:w-80">
                    <label for="content-block-search" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Search') }}</label>
                    <input
                        id="content-block-search"
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Code, name or type...') }}"
                        class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm"
                    />
                </div>
                <div class="w-full sm:w-44">
                    <label for="content-block-surface" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Surface') }}</label>
                    <select id="content-block-surface" wire:model="surface" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border px-3 py-2 text-sm">
                        <option value="all">{{ __('All') }}</option>
                        <option value="desktop">{{ __('Desktop') }}</option>
                        <option value="mobile">{{ __('Mobile') }}</option>
                    </select>
                </div>
                <a href="{{ route('admin.content.blocks.create') }}" class="inline-flex h-10 items-center rounded-xl bg-cyan-700 px-4 text-sm font-semibold text-white hover:bg-cyan-800">{{ __('Create') }}</a>
            </div>
        </div>
    </div>

    <div class="admin-panel admin-panel-soft p-5">
        <h2 class="admin-section-title">{{ __('Items') }}</h2>

        <div class="mt-4 overflow-x-auto">
            <table class="admin-items-table min-w-full text-sm">
                <thead class="text-slate-600">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Code') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Name') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Type') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Placement') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Surface') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Preview') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('Items') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('Slots') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('State') }}</th>
                        <th class="px-3 py-2 text-right font-semibold">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        @php
                            $title = $row->translations->first()?->title;
                            $primarySlot = $row->slots->first();
                        @endphp
                        <tr>
                            <td class="px-3 py-2 font-mono text-xs text-slate-700">{{ $row->code }}</td>
                            <td class="px-3 py-2 text-slate-800">
                                <div class="font-medium">{{ $row->name }}</div>
                                @if ($title)
                                    <div class="text-xs text-slate-500">{{ $title }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-slate-700">{{ config('content_blocks.types.'.$row->type, $row->type) }}</td>
                            <td class="px-3 py-2 text-xs text-slate-600">
                                <div>{{ $primarySlot?->placement ?: __('n/a') }}</div>
                                @if (!empty($primarySlot?->target_type))
                                    <div class="mt-1 text-slate-500">{{ $primarySlot->target_type }}: {{ $primarySlot->target_ref ?: '*' }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs text-slate-700">
                                @php
                                    $surface = (string) ($primarySlot?->frontend_variant ?? 'all');
                                @endphp
                                @if ($surface === 'desktop')
                                    {{ __('Desktop') }}
                                @elseif ($surface === 'mobile')
                                    {{ __('Mobile') }}
                                @else
                                    {{ __('All') }}
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                @include('admin.content.partials.block-type-preview', ['type' => $row->type, 'size' => 'xs'])
                            </td>
                            <td class="px-3 py-2 text-center text-slate-700">{{ $row->items_count }}</td>
                            <td class="px-3 py-2 text-center text-slate-700">{{ $row->slots_count }}</td>
                            <td class="px-3 py-2 text-center">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $row->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' }}">
                                    {{ $row->is_active ? __('Active') : __('Inactive') }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <div class="inline-flex items-center gap-1">
                                    <button type="button" wire:click="openPreview({{ $row->id }})" class="rounded-lg border border-cyan-200 px-2 py-1 text-xs font-semibold text-cyan-800 hover:bg-cyan-50">{{ __('Preview') }}</button>
                                    <a href="{{ route('admin.content.blocks.edit', ['block' => $row->id]) }}" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Edit') }}</a>
                                    <button type="button" wire:click="delete({{ $row->id }})" wire:confirm="{{ __('Delete this content block?') }}" class="rounded-lg border border-rose-200 px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50">{{ __('Delete') }}</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-3 py-8 text-center text-sm text-slate-500">{{ __('No content blocks yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $rows->links() }}
        </div>
    </div>

    @if ($previewBlock)
        @php
            $previewTranslation = $previewBlock->translations->firstWhere('locale', $locale)
                ?? $previewBlock->translations->firstWhere('locale', config('app.locale'));
            $previewPlacement = (string) ($previewBlock->slots->first()?->placement ?? 'home.hero');
            $previewVariant = (string) ($previewBlock->slots->first()?->frontend_variant ?? 'all');
            $frontVariant = in_array($previewVariant, ['desktop', 'mobile'], true) ? $previewVariant : 'desktop';
            $frontPreviewUrl = route('home', [
                'preview_block' => $previewBlock->id,
                'preview_placement' => $previewPlacement,
                'frontend_variant' => $frontVariant,
            ]);
        @endphp
        <div wire:click="closePreview" class="fixed inset-0 z-[72] bg-slate-900/45 p-4 md:p-6">
            <div wire:click.stop class="mx-auto flex h-full max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
                <div class="flex items-start justify-between gap-3 border-b border-slate-200 bg-gradient-to-r from-slate-50 to-cyan-50 px-5 py-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Block Preview') }}</p>
                        <h3 class="mt-1 text-lg font-semibold tracking-tight text-slate-900">{{ $previewBlock->name }}</h3>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ __('Code:') }} <span class="font-mono">{{ $previewBlock->code }}</span>
                            <span class="mx-1.5">|</span>
                            {{ __('Type:') }} {{ config('content_blocks.types.'.$previewBlock->type, $previewBlock->type) }}
                            <span class="mx-1.5">|</span>
                            {{ __('Slots:') }} {{ $previewBlock->slots_count }}
                        </p>
                    </div>
                    <div class="inline-flex items-center gap-2">
                        <a href="{{ $frontPreviewUrl }}" target="_blank" rel="noopener" class="rounded-lg border border-cyan-200 bg-cyan-50 px-3 py-1.5 text-xs font-semibold text-cyan-800 hover:bg-cyan-100">
                            {{ __('Open Front') }}
                        </a>
                        <button type="button" wire:click="closePreview" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Close') }}</button>
                    </div>
                </div>

                <div class="grid flex-1 gap-4 overflow-y-auto p-5 lg:grid-cols-[18rem_1fr]">
                    <div class="space-y-3">
                        @include('admin.content.partials.block-type-preview', ['type' => $previewBlock->type, 'size' => 'md'])
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                            <p>
                                {{ __('State:') }}
                                <span class="font-semibold {{ $previewBlock->is_active ? 'text-emerald-700' : 'text-slate-500' }}">
                                    {{ $previewBlock->is_active ? __('Active') : __('Inactive') }}
                                </span>
                            </p>
                            <p class="mt-1">
                                {{ __('Locale:') }}
                                <span class="font-semibold text-slate-700">{{ $previewTranslation?->locale ?? __('n/a') }}</span>
                            </p>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div class="rounded-xl border border-slate-200 bg-white p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Content Snapshot') }}</p>
                            <h4 class="mt-2 text-base font-semibold text-slate-900">{{ $previewTranslation?->title ?: __('(no title)') }}</h4>
                            @if (!empty($previewTranslation?->subtitle))
                                <p class="mt-1 text-sm text-slate-600">{{ $previewTranslation->subtitle }}</p>
                            @endif
                            @if (!empty($previewTranslation?->cta_label) || !empty($previewTranslation?->cta_url))
                                <p class="mt-2 text-xs text-slate-500">{{ __('CTA:') }} {{ $previewTranslation?->cta_label ?: '-' }} {{ !empty($previewTranslation?->cta_url) ? '-> '.$previewTranslation->cta_url : '' }}</p>
                            @endif
                        </div>

                        <div class="rounded-xl border border-slate-200 bg-white p-4 text-xs text-slate-600">
                            <p>{{ __('Placement:') }} <span class="font-semibold text-slate-800">{{ $previewPlacement }}</span></p>
                            <p class="mt-1">{{ __('Surface:') }} <span class="font-semibold text-slate-800">{{ $frontVariant }}</span></p>
                            <p class="mt-1">{{ __('Selected items:') }} <span class="font-semibold text-slate-800">{{ $previewBlock->items_count }}</span></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
