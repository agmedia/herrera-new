<div class="admin-panel admin-form-panel p-6">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="admin-section-title">{{ __('Images') }}</p>
            <p class="mt-1 text-sm text-slate-600">{{ __('Manage main, banner/icon and gallery images with per-locale alt/caption metadata.') }}</p>
        </div>
        <span class="admin-chip">{{ __('Locale:') }} {{ $locale }}</span>
    </div>

    @if (! $recordExists)
        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-600">
            {{ __('Save this record first, then upload and organize images.') }}
        </div>
    @else
        <div class="space-y-4">
            @foreach ($collections as $collectionName => $collectionConfig)
                @php
                    $collectionMedia = $mediaByCollection[$collectionName] ?? collect();
                    $isSingle = (bool) ($collectionConfig['single_file'] ?? false);
                    $acceptMime = (array) ($collectionConfig['accept_mime_types'] ?? []);
                    $maxUploadKb = max(1, (int) ($collectionConfig['max_upload_kb'] ?? 8192));
                    $previewConversion = (string) ($collectionConfig['preview_conversion'] ?? '');
                    $mainCollection = (string) ($modelProfile['main_collection'] ?? '');
                    $isMainCollection = $mainCollection !== '' && $mainCollection === $collectionName;
                    $mayPromoteToMain = (bool) ($collectionConfig['promote_to_main'] ?? true);
                    $isDesktopSliderCollection = $isLinkableSliderBlock && $collectionName === 'block_slides';
                    $isMobileSliderCollection = $isLinkableSliderBlock && $collectionName === 'block_slides_mobile';
                    $collectionLabel = $isDesktopSliderCollection
                        ? __('admin.media.desktop_slider_images')
                        : ($isMobileSliderCollection
                            ? __('admin.media.mobile_slider_images')
                            : ($collectionConfig['label'] ?? $collectionName));
                @endphp

                <section class="rounded-xl border border-slate-200 bg-white p-4">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-semibold text-slate-800">{{ $collectionLabel }}</h3>
                            <span class="admin-chip">{{ $collectionName }}</span>
                            <span class="admin-chip">{{ $collectionMedia->count() }} {{ $collectionMedia->count() === 1 ? __('image') : __('images') }}</span>
                            @if ($isMainCollection)
                                <span class="admin-chip">{{ __('Main') }}</span>
                            @endif
                        </div>
                    </div>

                    @if ($isDesktopSliderCollection || $isMobileSliderCollection)
                        <div class="mb-3 rounded-lg border border-cyan-100 bg-cyan-50 px-3 py-2 text-xs text-cyan-900">
                            {{ $isDesktopSliderCollection
                                ? __('admin.media.desktop_slider_hint')
                                : __('admin.media.mobile_slider_hint') }}
                        </div>
                    @endif

                    <div class="grid gap-3 lg:grid-cols-[1fr_auto]">
                        @if ($isInstagramCuratedBlock && $collectionName === 'block_slides')
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                                {{ __('Add a new Instagram slide, then paste the Instagram post URL below and click Save Meta to import the preview image and text.') }}
                            </div>
                            <div class="flex items-start">
                                <button
                                    type="button"
                                    wire:click="addInstagramPostSlide('{{ $collectionName }}')"
                                    class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800"
                                >
                                    {{ __('Add New Post') }}
                                </button>
                            </div>
                        @else
                            <div>
                                <input
                                    type="file"
                                    wire:model="uploads.{{ $collectionName }}"
                                    class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-700 hover:file:bg-slate-200"
                                    accept="{{ implode(',', $acceptMime) }}"
                                    @if (! $isSingle) multiple @endif
                                />
                                @error("uploads.$collectionName") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                @error("uploads.$collectionName.*") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ __('Max upload:') }} {{ number_format($maxUploadKb / 1024, 1) }} MB
                                    @if ($acceptMime !== [])
                                        | {{ implode(', ', $acceptMime) }}
                                    @endif
                                </p>
                            </div>
                            <div class="flex items-start">
                                <button
                                    type="button"
                                    wire:click="uploadCollection('{{ $collectionName }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="uploads.{{ $collectionName }}"
                                    @disabled(empty($uploads[$collectionName]))
                                    class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {{ __('Upload') }}
                                </button>
                            </div>
                        @endif
                    </div>

                    <div class="mt-4 overflow-x-auto">
                        <table class="admin-items-table min-w-full">
                            <thead>
                                <tr>
                                    <th class="px-3 py-2 text-left">{{ __('Preview') }}</th>
                                    <th class="px-3 py-2 text-left">{{ __('Meta') }}</th>
                                    <th class="px-3 py-2 text-center">{{ __('Sort') }}</th>
                                    <th class="px-3 py-2 text-right">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($collectionMedia as $media)
                                    @php
                                        $mediaMeta = (array) ($meta[$media->id] ?? []);
                                        $hotspots = collect((array) ($mediaMeta['hotspots'] ?? []))
                                            ->map(function ($row): array {
                                                $row = is_array($row) ? $row : [];

                                                return [
                                                    'product_id' => (int) ($row['product_id'] ?? 0),
                                                    'x' => max(0, min(100, (float) ($row['x'] ?? 50))),
                                                    'y' => max(0, min(100, (float) ($row['y'] ?? 50))),
                                                ];
                                            })
                                            ->filter(fn (array $row): bool => (int) ($row['product_id'] ?? 0) > 0)
                                            ->values()
                                            ->take(3);
                                        $focalX = (float) ($mediaMeta['focal_x'] ?? 50);
                                        $focalY = (float) ($mediaMeta['focal_y'] ?? 50);
                                        $cropEnabled = (bool) ($mediaMeta['crop_enabled'] ?? false);
                                        $cropX = (float) ($mediaMeta['crop_x'] ?? 0);
                                        $cropY = (float) ($mediaMeta['crop_y'] ?? 0);
                                        $cropWidth = (float) ($mediaMeta['crop_width'] ?? 100);
                                        $cropHeight = (float) ($mediaMeta['crop_height'] ?? 100);
                                        $cacheBuster = trim((string) (($media->updated_at?->timestamp ?? time()).'-'.($media->size ?? 0)));
                                        $previewUrl = $previewConversion !== '' && $media->hasGeneratedConversion($previewConversion)
                                            ? $media->getUrl($previewConversion)
                                            : $media->getUrl();
                                        $previewUrl .= (str_contains($previewUrl, '?') ? '&' : '?').'v='.$cacheBuster;
                                        $isImageMedia = str_starts_with(strtolower((string) $media->mime_type), 'image/');
                                    @endphp
                                    <tr wire:key="media-{{ $collectionName }}-{{ $media->id }}">
                                        <td class="px-3 py-3 align-top">
                                            @if ($isImageMedia && (($isBlogPostMedia && $collectionName === 'blog_gallery') || ($isLinkableSliderBlock && $collectionName === 'block_slides')))
                                                <div
                                                    class="relative overflow-hidden rounded-lg border border-slate-200 bg-slate-100"
                                                    style="width: 280px; max-width: 100%;"
                                                    data-hotspot-preview-root
                                                    data-media-id="{{ $media->id }}"
                                                >
                                                    <img
                                                        src="{{ $previewUrl }}"
                                                        alt=""
                                                        class="block h-auto w-full cursor-crosshair object-cover"
                                                        draggable="false"
                                                        data-hotspot-preview-image
                                                        data-media-id="{{ $media->id }}"
                                                        data-active-index="0"
                                                    />
                                                    @for ($hotspotIndex = 0; $hotspotIndex < 3; $hotspotIndex++)
                                                        @php
                                                            $previewPin = $hotspots->get($hotspotIndex);
                                                            $previewX = max(3, min(97, (float) ($previewPin['x'] ?? 50)));
                                                            $previewY = max(3, min(97, (float) ($previewPin['y'] ?? 50)));
                                                            $hasPreviewPin = (int) ($previewPin['product_id'] ?? 0) > 0;
                                                        @endphp
                                                        <button
                                                            type="button"
                                                            class="absolute z-10 inline-flex h-6 w-6 -translate-x-1/2 -translate-y-1/2 cursor-grab touch-none items-center justify-center rounded-full border border-white bg-slate-900 text-[10px] font-semibold text-white shadow"
                                                            style="left: {{ $previewX }}%; top: {{ $previewY }}%; display: {{ $hasPreviewPin ? 'inline-flex' : 'none' }};"
                                                            data-hotspot-preview-pin
                                                            data-media-id="{{ $media->id }}"
                                                            data-pin-index="{{ $hotspotIndex }}"
                                                            aria-label="Pin {{ $hotspotIndex + 1 }}"
                                                        >
                                                            {{ $hotspotIndex + 1 }}
                                                        </button>
                                                    @endfor
                                                </div>
                                            @elseif ($isImageMedia)
                                                <img src="{{ $previewUrl }}" alt="" class="h-20 w-28 rounded-lg border border-slate-200 bg-slate-100 object-cover" />
                                            @else
                                                <a href="{{ $media->getUrl() }}" target="_blank" rel="noopener noreferrer" class="flex h-20 w-28 flex-col items-center justify-center rounded-lg border border-slate-200 bg-slate-50 px-2 text-center text-[10px] font-semibold uppercase text-slate-600 hover:bg-slate-100">
                                                    <span aria-hidden="true" class="text-lg">PDF</span>
                                                    <span>{{ __('Open document') }}</span>
                                                </a>
                                            @endif
                                            <p class="mt-1 text-[11px] text-slate-500">{{ $media->file_name }}</p>
                                        </td>
                                        <td class="px-3 py-3 align-top">
                                            @if ($isDualImageCtaBlock && $collectionName === 'block_slides')
                                                <div class="grid gap-2 md:grid-cols-2">
                                                    <input type="text" wire:model.blur="meta.{{ $media->id }}.block_title" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs md:col-span-2" placeholder="{{ __('Block title') }} ({{ $locale }})" />
                                                    <input type="text" wire:model.blur="meta.{{ $media->id }}.link_url" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs md:col-span-2" placeholder="{{ __('Image link URL') }} ({{ $locale }})" />
                                                    <input type="text" wire:model.blur="meta.{{ $media->id }}.cta_1_label" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs" placeholder="{{ __('CTA 1 label') }} ({{ $locale }})" />
                                                    <input type="text" wire:model.blur="meta.{{ $media->id }}.cta_1_url" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs" placeholder="{{ __('CTA 1 URL') }} ({{ $locale }})" />
                                                    <input type="text" wire:model.blur="meta.{{ $media->id }}.cta_2_label" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs" placeholder="{{ __('CTA 2 label') }} ({{ $locale }})" />
                                                    <input type="text" wire:model.blur="meta.{{ $media->id }}.cta_2_url" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs" placeholder="{{ __('CTA 2 URL') }} ({{ $locale }})" />
                                                </div>
                                                <p class="mt-2 text-[11px] text-slate-500">{{ __('These fields save automatically when you leave the input.') }}</p>
                                            @elseif ($isCategoryEditorialTilesBlock && $collectionName === 'block_slides')
                                                <div class="grid gap-2 md:grid-cols-2">
                                                    <input type="text" wire:model.blur="meta.{{ $media->id }}.block_title" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs" placeholder="{{ __('Card title') }} ({{ $locale }})" />
                                                    <input type="text" wire:model.blur="meta.{{ $media->id }}.link_url" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs" placeholder="{{ __('Category / link URL') }} ({{ $locale }})" />
                                                </div>
                                                <div class="mt-2 grid gap-2 md:grid-cols-3">
                                                    <input type="text" wire:model.defer="meta.{{ $media->id }}.name" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs" placeholder="{{ __('Name') }}" />
                                                    <input type="text" wire:model.defer="meta.{{ $media->id }}.alt" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs" placeholder="{{ __('Alt') }} ({{ $locale }})" />
                                                    <input type="text" wire:model.defer="meta.{{ $media->id }}.caption" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs" placeholder="{{ __('Caption') }} ({{ $locale }})" />
                                                </div>
                                                <p class="mt-2 text-[11px] text-slate-500">{{ __('Card title and link save automatically when you leave the input.') }}</p>
                                            @elseif ($isInstagramCuratedBlock && $collectionName === 'block_slides')
                                                <div class="grid gap-2 md:grid-cols-2">
                                                    <input type="text" wire:model.blur="meta.{{ $media->id }}.block_title" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs" placeholder="{{ __('Post label') }} ({{ $locale }})" />
                                                    <input type="text" wire:model.blur="meta.{{ $media->id }}.link_url" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs" placeholder="{{ __('Instagram post URL') }} ({{ $locale }})" />
                                                </div>
                                                <div class="mt-2 grid gap-2 md:grid-cols-3">
                                                    <input type="text" wire:model.defer="meta.{{ $media->id }}.name" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs" placeholder="{{ __('Name') }}" />
                                                    <input type="text" wire:model.defer="meta.{{ $media->id }}.alt" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs" placeholder="{{ __('Alt') }} ({{ $locale }})" />
                                                    <input type="text" wire:model.defer="meta.{{ $media->id }}.caption" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs" placeholder="{{ __('Caption / excerpt') }} ({{ $locale }})" />
                                                </div>
                                                <p class="mt-2 text-[11px] text-slate-500">{{ __('Post label and post URL save automatically when you leave the input. Click Save meta to import caption and preview from the Instagram URL.') }}</p>
                                            @else
                                                <div class="grid gap-2 md:grid-cols-3">
                                                    <input type="text" wire:model.defer="meta.{{ $media->id }}.name" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs" placeholder="{{ __('Name') }}" />
                                                    <input type="text" wire:model.defer="meta.{{ $media->id }}.alt" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs" placeholder="{{ __('Alt') }} ({{ $locale }})" />
                                                    <input type="text" wire:model.defer="meta.{{ $media->id }}.caption" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs" placeholder="{{ __('Caption') }} ({{ $locale }})" />
                                                </div>

                                                @if ($isDesktopSliderCollection)
                                                    <div class="mt-2 grid gap-2 md:grid-cols-2">
                                                        <input
                                                            type="text"
                                                            wire:model.blur="meta.{{ $media->id }}.button_label"
                                                            class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs"
                                                            placeholder="{{ __('admin.media.slider_button_label') }} ({{ $locale }})"
                                                        />
                                                        <input
                                                            type="text"
                                                            wire:model.blur="meta.{{ $media->id }}.link_url"
                                                            class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs"
                                                            placeholder="{{ __('admin.media.slider_button_url') }} ({{ $locale }})"
                                                        />
                                                    </div>
                                                    <p class="mt-2 text-[11px] text-slate-500">{{ __('admin.media.slider_button_hint') }}</p>
                                                @endif

                                                @if ($supportsProductHotspots && (($isBlogPostMedia && $collectionName === 'blog_gallery') || ($isLinkableSliderBlock && $collectionName === 'block_slides')))
                                                    <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                                        <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Product Pins (max 3)') }}</p>
                                                        <p class="mt-1 text-[11px] text-slate-500">{{ __('Select pin slot, then click on image preview to set exact pin position.') }}</p>
                                                        <div class="mt-2 space-y-2">
                                                            @for ($hotspotIndex = 0; $hotspotIndex < 3; $hotspotIndex++)
                                                                <div
                                                                    class="grid items-center gap-2 md:grid-cols-[90px_minmax(0,1fr)_78px_78px]"
                                                                    data-hotspot-row
                                                                    data-media-id="{{ $media->id }}"
                                                                    data-pin-index="{{ $hotspotIndex }}"
                                                                >
                                                                    <button
                                                                        type="button"
                                                                        class="rounded border border-slate-300 bg-white px-2 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-100"
                                                                        data-hotspot-select
                                                                        data-media-id="{{ $media->id }}"
                                                                        data-pin-index="{{ $hotspotIndex }}"
                                                                    >
                                                                        {{ __('Pin') }} {{ $hotspotIndex + 1 }}
                                                                    </button>
                                                                    <select
                                                                        wire:model.defer="meta.{{ $media->id }}.hotspots.{{ $hotspotIndex }}.product_id"
                                                                        class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs"
                                                                        data-hotspot-product
                                                                        data-media-id="{{ $media->id }}"
                                                                        data-pin-index="{{ $hotspotIndex }}"
                                                                    >
                                                                        <option value="">{{ __('No product') }}</option>
                                                                        @foreach ($hotspotProductOptions as $option)
                                                                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                                                        @endforeach
                                                                    </select>
                                                                    <input
                                                                        type="number"
                                                                        min="0"
                                                                        max="100"
                                                                        step="0.1"
                                                                        wire:model.defer="meta.{{ $media->id }}.hotspots.{{ $hotspotIndex }}.x"
                                                                        class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs"
                                                                        placeholder="X %"
                                                                        data-hotspot-x
                                                                        data-media-id="{{ $media->id }}"
                                                                        data-pin-index="{{ $hotspotIndex }}"
                                                                    />
                                                                    <input
                                                                        type="number"
                                                                        min="0"
                                                                        max="100"
                                                                        step="0.1"
                                                                        wire:model.defer="meta.{{ $media->id }}.hotspots.{{ $hotspotIndex }}.y"
                                                                        class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs"
                                                                        placeholder="Y %"
                                                                        data-hotspot-y
                                                                        data-media-id="{{ $media->id }}"
                                                                        data-pin-index="{{ $hotspotIndex }}"
                                                                    />
                                                                </div>
                                                            @endfor
                                                        </div>
                                                    </div>
                                                @endif
                                            @endif
                                            <p class="mt-1 text-[11px] text-slate-500">
                                                {{ number_format($media->size / 1024, 0) }} KB
                                                @if ($media->width && $media->height)
                                                    | {{ $media->width }}x{{ $media->height }}
                                                @endif
                                            </p>
                                            @if ($isImageMedia)
                                                <div class="mt-2 flex flex-wrap items-center gap-1 text-[11px]">
                                                    <span class="admin-chip">{{ __('Focal:') }} {{ number_format($focalX, 1) }} / {{ number_format($focalY, 1) }}</span>
                                                    @if ($cropEnabled)
                                                        <span class="admin-chip">{{ __('Crop:') }} {{ number_format($cropX, 1) }},{{ number_format($cropY, 1) }} / {{ number_format($cropWidth, 1) }}x{{ number_format($cropHeight, 1) }}</span>
                                                    @else
                                                        <span class="admin-chip">{{ __('Crop: Off') }}</span>
                                                    @endif
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 align-top text-center">
                                            <div class="inline-flex items-center gap-1">
                                                <button type="button" wire:click="moveUp({{ $media->id }})" class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-100">↑</button>
                                                <button type="button" wire:click="moveDown({{ $media->id }})" class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-100">↓</button>
                                            </div>
                                        </td>
                                        <td class="px-3 py-3 align-top">
                                            <div class="flex flex-wrap justify-end gap-1">
                                                <button type="button" wire:click="saveMeta({{ $media->id }})" class="rounded border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Save Meta') }}</button>
                                                @if ($isImageMedia)
                                                    <button
                                                        type="button"
                                                        data-image-edit-open
                                                        data-media-id="{{ $media->id }}"
                                                        data-image-url="{{ $media->getUrl() }}"
                                                        data-focal-x="{{ $focalX }}"
                                                        data-focal-y="{{ $focalY }}"
                                                        data-crop-enabled="{{ $cropEnabled ? '1' : '0' }}"
                                                        data-crop-x="{{ $cropX }}"
                                                        data-crop-y="{{ $cropY }}"
                                                        data-crop-width="{{ $cropWidth }}"
                                                        data-crop-height="{{ $cropHeight }}"
                                                        class="rounded border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100"
                                                    >
                                                        {{ __('Edit Crop/Focus') }}
                                                    </button>
                                                @endif
                                                @if ($mayPromoteToMain && $isImageMedia && $mainCollection !== '' && ! $isMainCollection)
                                                    <button type="button" wire:click="copyToMain({{ $media->id }})" class="rounded border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Copy to Main') }}</button>
                                                @endif
                                                <a href="{{ $media->getUrl() }}" target="_blank" rel="noopener noreferrer" class="rounded border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Open') }}</a>
                                                <button
                                                    type="button"
                                                    wire:click="delete({{ $media->id }})"
                                                    wire:confirm="{{ __('Delete this image?') }}"
                                                    class="rounded border border-rose-200 px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50"
                                                >
                                                    {{ __('Delete') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-3 py-5 text-center text-sm text-slate-500">
                                            {{ __('No images in this collection yet.') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>

@once
    <script>
        (function () {
            if (window.__adminHotspotBindingsReady === true) {
                return;
            }
            window.__adminHotspotBindingsReady = true;

            const init = function () {
            const activeByMedia = {};
            let dragState = null;
            const closestFromEvent = function (event, selector) {
                const target = event.target;
                if (!(target instanceof Element)) {
                    return null;
                }

                return target.closest(selector);
            };

            const syncPreviewPin = function (mediaId, pinIndex) {
                const pin = document.querySelector('[data-hotspot-preview-pin][data-media-id="' + mediaId + '"][data-pin-index="' + pinIndex + '"]');
                const xInput = document.querySelector('[data-hotspot-x][data-media-id="' + mediaId + '"][data-pin-index="' + pinIndex + '"]');
                const yInput = document.querySelector('[data-hotspot-y][data-media-id="' + mediaId + '"][data-pin-index="' + pinIndex + '"]');
                const productSelect = document.querySelector('[data-hotspot-product][data-media-id="' + mediaId + '"][data-pin-index="' + pinIndex + '"]');
                if (!pin || !xInput || !yInput || !productSelect) {
                    return;
                }

                const hasProduct = Number(productSelect.value || 0) > 0;
                if (!hasProduct) {
                    pin.style.display = 'none';
                    return;
                }

                const x = Math.max(3, Math.min(97, Number(xInput.value || 50)));
                const y = Math.max(3, Math.min(97, Number(yInput.value || 50)));
                pin.style.display = 'inline-flex';
                pin.style.left = x + '%';
                pin.style.top = y + '%';
            };

            const setActivePin = function (mediaId, pinIndex) {
                activeByMedia[mediaId] = String(pinIndex);
                document.querySelectorAll('[data-hotspot-row][data-media-id="' + mediaId + '"]').forEach(function (row) {
                    const isActive = row.dataset.pinIndex === String(pinIndex);
                    row.classList.toggle('ring-2', isActive);
                    row.classList.toggle('ring-cyan-500', isActive);
                    row.classList.toggle('rounded-lg', isActive);
                });
                document.querySelectorAll('[data-hotspot-preview-pin][data-media-id="' + mediaId + '"]').forEach(function (pin) {
                    const isActive = pin.dataset.pinIndex === String(pinIndex);
                    pin.classList.toggle('ring-2', isActive);
                    pin.classList.toggle('ring-cyan-300', isActive);
                });
            };

            const updatePinFromPoint = function (mediaId, pinIndex, clientX, clientY) {
                const image = document.querySelector('[data-hotspot-preview-image][data-media-id="' + mediaId + '"]');
                const xInput = document.querySelector('[data-hotspot-x][data-media-id="' + mediaId + '"][data-pin-index="' + pinIndex + '"]');
                const yInput = document.querySelector('[data-hotspot-y][data-media-id="' + mediaId + '"][data-pin-index="' + pinIndex + '"]');
                if (!image || !xInput || !yInput) {
                    return;
                }

                const bounds = image.getBoundingClientRect();
                if (bounds.width <= 0 || bounds.height <= 0) {
                    return;
                }

                const x = Math.max(0, Math.min(100, ((clientX - bounds.left) / bounds.width) * 100));
                const y = Math.max(0, Math.min(100, ((clientY - bounds.top) / bounds.height) * 100));

                xInput.value = x.toFixed(2);
                yInput.value = y.toFixed(2);
                xInput.dispatchEvent(new Event('input', { bubbles: true }));
                yInput.dispatchEvent(new Event('input', { bubbles: true }));
                syncPreviewPin(mediaId, pinIndex);
            };

            const initRows = function () {
                document.querySelectorAll('[data-hotspot-preview-root]').forEach(function (root) {
                    const mediaId = root.dataset.mediaId;
                    if (!mediaId) {
                        return;
                    }

                    if (typeof activeByMedia[mediaId] === 'undefined') {
                        setActivePin(mediaId, '0');
                    } else {
                        setActivePin(mediaId, activeByMedia[mediaId]);
                    }

                    ['0', '1', '2'].forEach(function (pinIndex) {
                        syncPreviewPin(mediaId, pinIndex);
                    });
                });
            };

            const bindDragHandlers = function () {
                document.querySelectorAll('[data-hotspot-preview-root]').forEach(function (root) {
                    if (root.dataset.dragBound === '1') {
                        return;
                    }
                    root.dataset.dragBound = '1';

                    root.addEventListener('pointerdown', function (event) {
                        const targetPin = closestFromEvent(event, '[data-hotspot-preview-pin]');
                        const targetImage = closestFromEvent(event, '[data-hotspot-preview-image]');
                        if (!targetPin && !targetImage) {
                            return;
                        }

                        if ((event.button ?? 0) !== 0) {
                            return;
                        }

                        const mediaId = (targetPin?.dataset.mediaId || targetImage?.dataset.mediaId || root.dataset.mediaId || '');
                        const pinIndex = targetPin?.dataset.pinIndex || String(activeByMedia[mediaId] ?? '0');
                        startDrag(mediaId, pinIndex, event.clientX, event.clientY, event.pointerId);
                        try {
                            root.setPointerCapture(event.pointerId);
                        } catch (_e) {
                        }
                        event.preventDefault();
                    });

                    root.addEventListener('pointermove', function (event) {
                        if (!dragState) {
                            return;
                        }
                        if (dragState.pointerId !== null && event.pointerId !== dragState.pointerId) {
                            return;
                        }
                        updatePinFromPoint(dragState.mediaId, dragState.pinIndex, event.clientX, event.clientY);
                    });

                    const finishPointer = function (event) {
                        if (!dragState) {
                            return;
                        }
                        if (dragState.pointerId !== null && event.pointerId !== dragState.pointerId) {
                            return;
                        }
                        finishDrag(dragState);
                    };

                    root.addEventListener('pointerup', finishPointer);
                    root.addEventListener('pointercancel', finishPointer);
                    root.addEventListener('lostpointercapture', finishPointer);
                });
            };

            document.addEventListener('click', function (event) {
                const selectButton = closestFromEvent(event, '[data-hotspot-select]');
                if (selectButton) {
                    setActivePin(selectButton.dataset.mediaId || '', selectButton.dataset.pinIndex || '0');
                    return;
                }

                const previewPin = closestFromEvent(event, '[data-hotspot-preview-pin]');
                if (previewPin) {
                    setActivePin(previewPin.dataset.mediaId || '', previewPin.dataset.pinIndex || '0');
                    return;
                }

                const previewImage = closestFromEvent(event, '[data-hotspot-preview-image]');
                if (previewImage) {
                    const mediaId = previewImage.dataset.mediaId || '';
                    const pinIndex = String(activeByMedia[mediaId] ?? '0');
                    updatePinFromPoint(mediaId, pinIndex, event.clientX, event.clientY);
                }
            });

            document.addEventListener('input', function (event) {
                const target = event.target;
                if (!(target instanceof HTMLElement)) {
                    return;
                }

                if (target.matches('[data-hotspot-x],[data-hotspot-y],[data-hotspot-product]')) {
                    syncPreviewPin(target.dataset.mediaId || '', target.dataset.pinIndex || '0');
                }
            });

            document.addEventListener('change', function (event) {
                const target = event.target;
                if (!(target instanceof HTMLElement)) {
                    return;
                }

                if (target.matches('[data-hotspot-x],[data-hotspot-y],[data-hotspot-product]')) {
                    syncPreviewPin(target.dataset.mediaId || '', target.dataset.pinIndex || '0');
                }
            });

            const finishDrag = function (state) {
                const current = state || dragState;
                if (!current) {
                    return;
                }

                const xInput = document.querySelector('[data-hotspot-x][data-media-id="' + current.mediaId + '"][data-pin-index="' + current.pinIndex + '"]');
                const yInput = document.querySelector('[data-hotspot-y][data-media-id="' + current.mediaId + '"][data-pin-index="' + current.pinIndex + '"]');
                if (xInput) {
                    xInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
                if (yInput) {
                    yInput.dispatchEvent(new Event('change', { bubbles: true }));
                }

                const pin = document.querySelector('[data-hotspot-preview-pin][data-media-id="' + current.mediaId + '"][data-pin-index="' + current.pinIndex + '"]');
                if (pin) {
                    pin.style.cursor = '';
                }

                dragState = null;
            };

            const startDrag = function (mediaId, pinIndex, clientX, clientY, pointerId) {
                setActivePin(mediaId, pinIndex);
                dragState = { mediaId: mediaId, pinIndex: pinIndex, pointerId: pointerId ?? null };
                const pin = document.querySelector('[data-hotspot-preview-pin][data-media-id="' + mediaId + '"][data-pin-index="' + pinIndex + '"]');
                if (pin) {
                    pin.style.cursor = 'grabbing';
                }
                updatePinFromPoint(mediaId, pinIndex, clientX, clientY);
            };

            document.addEventListener('dragstart', function (event) {
                const pin = closestFromEvent(event, '[data-hotspot-preview-pin]');
                const image = closestFromEvent(event, '[data-hotspot-preview-image]');
                if (pin || image) {
                    event.preventDefault();
                }
            });

            initRows();
            bindDragHandlers();
            const observer = new MutationObserver(function () {
                initRows();
                bindDragHandlers();
            });
            observer.observe(document.body, { childList: true, subtree: true });
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init, { once: true });
            } else {
                init();
            }
        })();
    </script>
@endonce
