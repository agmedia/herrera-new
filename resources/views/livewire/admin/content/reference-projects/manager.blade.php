<div class="space-y-6">
    <div class="admin-panel p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-3xl">
                <h1 class="text-xl font-semibold tracking-tight">{{ __('reference_projects.title') }}</h1>
                <p class="mt-2 text-sm text-slate-600">{{ __('reference_projects.admin_intro') }}</p>
            </div>
            @if ($canCreate)
                <button type="button" wire:click="create" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">{{ __('reference_projects.create') }}</button>
            @endif
        </div>
    </div>

    @if ($showForm)
        <form wire:submit="save" class="admin-panel space-y-6 p-6" wire:key="reference-form-{{ $projectId ?? 'new' }}">
            <h2 class="admin-section-title">{{ $projectId ? __('reference_projects.edit') : __('reference_projects.create') }}</h2>
            @if ($errors->any())
                <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
                    <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif
            <div class="grid gap-6 lg:grid-cols-2">
                @foreach (['hr' => 'croatian', 'en' => 'english'] as $locale => $localeLabel)
                    <fieldset class="space-y-4 rounded-xl border border-slate-200 p-4">
                        <legend class="px-2 text-sm font-semibold text-slate-700">{{ __('reference_projects.'.$localeLabel) }}</legend>
                        <div>
                            <label for="reference-title-{{ $locale }}" class="mb-1 block text-sm font-medium text-slate-700">{{ __('reference_projects.title_field') }}{{ $locale === 'hr' ? ' *' : '' }}</label>
                            <input id="reference-title-{{ $locale }}" wire:model="form.{{ $locale }}.title" type="text" maxlength="255" class="admin-search-input w-full rounded-lg border px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label for="reference-slug-{{ $locale }}" class="mb-1 block text-sm font-medium text-slate-700">{{ __('reference_projects.slug') }}</label>
                            <div class="flex gap-2">
                                <input id="reference-slug-{{ $locale }}" wire:model="form.{{ $locale }}.slug" type="text" maxlength="191" class="admin-search-input min-w-0 flex-1 rounded-lg border px-3 py-2 text-sm">
                                <button type="button" wire:click="generateSlug('{{ $locale }}')" class="shrink-0 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold">{{ __('reference_projects.generate_slug') }}</button>
                            </div>
                        </div>
                        <div>
                            <label for="reference-excerpt-{{ $locale }}" class="mb-1 block text-sm font-medium text-slate-700">{{ __('reference_projects.excerpt') }}</label>
                            <textarea id="reference-excerpt-{{ $locale }}" wire:model="form.{{ $locale }}.excerpt" rows="3" maxlength="1500" class="admin-search-input w-full rounded-lg border px-3 py-2 text-sm"></textarea>
                        </div>
                        <div>
                            <label for="reference-description-{{ $locale }}" class="mb-1 block text-sm font-medium text-slate-700">{{ __('reference_projects.description') }}</label>
                            <textarea id="reference-description-{{ $locale }}" wire:model="form.{{ $locale }}.description" rows="8" maxlength="30000" class="admin-search-input w-full rounded-lg border px-3 py-2 text-sm"></textarea>
                            <p class="mt-1 text-xs text-slate-500">{{ __('reference_projects.description_help') }}</p>
                        </div>
                    </fieldset>
                @endforeach
            </div>

            <fieldset class="rounded-xl border border-slate-200 p-4">
                <legend class="px-2 text-sm font-semibold text-slate-700">{{ __('reference_projects.settings') }}</legend>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div><label for="reference-location" class="mb-1 block text-sm font-medium">{{ __('reference_projects.location') }}</label><input id="reference-location" wire:model="form.location" type="text" maxlength="255" class="admin-search-input w-full rounded-lg border px-3 py-2 text-sm"></div>
                    <div><label for="reference-year" class="mb-1 block text-sm font-medium">{{ __('reference_projects.year') }}</label><input id="reference-year" wire:model="form.completed_year" type="number" min="1900" max="{{ now()->year + 1 }}" class="admin-search-input w-full rounded-lg border px-3 py-2 text-sm"></div>
                    <div><label for="reference-type" class="mb-1 block text-sm font-medium">{{ __('reference_projects.type') }}</label><input id="reference-type" wire:model="form.project_type" type="text" maxlength="120" class="admin-search-input w-full rounded-lg border px-3 py-2 text-sm"></div>
                    <div><label for="reference-order" class="mb-1 block text-sm font-medium">{{ __('reference_projects.sort_order') }}</label><input id="reference-order" wire:model="form.sort_order" type="number" min="0" class="admin-search-input w-full rounded-lg border px-3 py-2 text-sm"></div>
                    <div><label for="reference-published-at" class="mb-1 block text-sm font-medium">{{ __('reference_projects.publish_at') }}</label><input id="reference-published-at" wire:model="form.published_at" type="datetime-local" class="admin-search-input w-full rounded-lg border px-3 py-2 text-sm"></div>
                    <label class="flex items-center gap-2 self-end rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium"><input wire:model="form.is_active" type="checkbox" class="rounded border-slate-300 text-cyan-700">{{ __('reference_projects.publish') }}</label>
                </div>
            </fieldset>

            <div class="space-y-3">
                <h3 class="text-sm font-semibold text-slate-800">{{ __('reference_projects.photos') }}</h3>
                <p class="text-xs text-slate-500">{{ __('reference_projects.photo_help') }}</p>
                <input wire:model="photos" type="file" multiple accept="image/jpeg,image/png,image/webp" aria-label="{{ __('reference_projects.photos') }}" class="admin-search-input w-full rounded-lg border px-3 py-3 text-sm">
                <div wire:loading wire:target="photos" role="status" class="text-sm text-cyan-700">{{ __('Uploading…') }}</div>
                @if ($photos)
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                        @foreach ($photos as $photo)
                            @if (in_array($photo->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true))
                                <img src="{{ $photo->temporaryUrl() }}" alt="{{ __('reference_projects.photo') }}" class="aspect-[4/3] w-full rounded-lg object-cover">
                            @endif
                        @endforeach
                    </div>
                @endif
                @if ($gallery->isNotEmpty())
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($gallery as $photo)
                            <div class="space-y-2 rounded-xl border border-slate-200 p-3" wire:key="reference-photo-{{ $photo->id }}">
                                <img src="{{ $project->photoUrl($photo) }}" alt="{{ $photo->getCustomProperty('caption') ?: __('reference_projects.photo') }}" class="aspect-[4/3] w-full rounded-lg object-cover" loading="lazy">
                                @if ($loop->first)<span class="inline-flex rounded-full bg-cyan-50 px-2 py-1 text-xs font-semibold text-cyan-800">{{ __('reference_projects.cover') }}</span>@endif
                                <label for="reference-caption-{{ $photo->id }}" class="block text-xs font-medium">{{ __('reference_projects.caption') }}</label>
                                <input id="reference-caption-{{ $photo->id }}" wire:model="captions.{{ $photo->id }}" maxlength="500" type="text" class="admin-search-input w-full rounded-lg border px-3 py-2 text-sm">
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" wire:click="saveCaption({{ $photo->id }})" class="rounded-lg border border-slate-300 px-2 py-1 text-xs">{{ __('reference_projects.caption_save') }}</button>
                                    @if (! $loop->first)<button type="button" wire:click="movePhoto({{ $photo->id }}, 'up')" aria-label="{{ __('reference_projects.move_up') }}" class="rounded-lg border border-slate-300 px-2 py-1 text-xs">←</button>@endif
                                    @if (! $loop->last)<button type="button" wire:click="movePhoto({{ $photo->id }}, 'down')" aria-label="{{ __('reference_projects.move_down') }}" class="rounded-lg border border-slate-300 px-2 py-1 text-xs">→</button>@endif
                                    @if ($canDelete)<button type="button" wire:click="deletePhoto({{ $photo->id }})" wire:confirm="{{ __('reference_projects.remove_photo_confirm') }}" class="rounded-lg border border-rose-200 px-2 py-1 text-xs text-rose-700">{{ __('reference_projects.remove_photo') }}</button>@endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="flex flex-wrap gap-3 border-t border-slate-200 pt-5">
                <button type="submit" wire:loading.attr="disabled" wire:target="save,photos" class="rounded-xl bg-cyan-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-cyan-800 disabled:opacity-50"><span wire:loading.remove wire:target="save">{{ __('reference_projects.save') }}</span><span wire:loading wire:target="save">{{ __('reference_projects.saving') }}</span></button>
                <button type="button" wire:click="cancel" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">{{ __('reference_projects.cancel') }}</button>
            </div>
        </form>
    @endif

    <div class="admin-panel space-y-4 p-5">
        <div class="grid gap-3 sm:grid-cols-[1fr_14rem]">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('reference_projects.search') }}" aria-label="{{ __('reference_projects.search') }}" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm">
            <select wire:model.live="status" aria-label="{{ __('Status') }}" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm"><option value="">{{ __('reference_projects.all') }}</option><option value="published">{{ __('reference_projects.published') }}</option><option value="draft">{{ __('reference_projects.drafts') }}</option></select>
        </div>
        <div class="overflow-x-auto">
            <table class="admin-items-table min-w-full text-sm">
                <thead><tr><th class="px-3 py-2 text-left">{{ __('reference_projects.title_field') }}</th><th class="px-3 py-2 text-left">{{ __('reference_projects.location') }}</th><th class="px-3 py-2 text-left">{{ __('reference_projects.photos') }}</th><th class="px-3 py-2 text-left">{{ __('Status') }}</th><th class="px-3 py-2"></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        @php
                            $tr = $row->localizedTranslation(app()->getLocale(), 'hr');
                            $rowPhotos = $row->getMedia(\App\Models\Content\Reference\ReferenceProject::GALLERY);
                        @endphp
                        <tr wire:key="reference-row-{{ $row->id }}">
                            <td class="px-3 py-3 font-semibold">{{ $tr?->title ?? '#'.$row->id }}</td>
                            <td class="px-3 py-3">{{ $row->location ?: '—' }}{{ $row->completed_year ? ' · '.$row->completed_year : '' }}</td>
                            <td class="px-3 py-3">{{ $rowPhotos->count() }}</td>
                            <td class="px-3 py-3"><span class="admin-chip">{{ ! $row->is_active ? __('reference_projects.draft') : ($row->published_at?->isFuture() ? __('reference_projects.scheduled') : __('reference_projects.published')) }}</span></td>
                            <td class="px-3 py-3 text-right">
                                <div class="inline-flex flex-wrap gap-2">
                                    @if ($row->is_active && ! $row->published_at?->isFuture() && $tr && $rowPhotos->isNotEmpty())<a href="{{ route('references.show', $tr->slug) }}" target="_blank" rel="noopener" class="rounded-lg border border-slate-300 px-2 py-1 text-xs">{{ __('reference_projects.preview') }}</a>@endif
                                    @if ($canUpdate)<button type="button" wire:click="edit({{ $row->id }})" class="rounded-lg border border-slate-300 px-2 py-1 text-xs">{{ __('reference_projects.edit') }}</button>@endif
                                    @if ($canDelete)<button type="button" wire:click="delete({{ $row->id }})" wire:confirm="{{ __('reference_projects.delete_confirm') }}" class="rounded-lg border border-rose-200 px-2 py-1 text-xs text-rose-700">{{ __('reference_projects.delete') }}</button>@endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-8 text-center text-slate-500">{{ __('reference_projects.no_projects') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $rows->links() }}
    </div>
</div>
