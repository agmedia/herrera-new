@php
    $locale = app()->getLocale();
    $fallbackLocale = config('app.locale');
    $translationPayload = is_array($translation?->payload ?? null) ? $translation->payload : [];
    $customClasses = trim((string) ($translationPayload['custom_classes'] ?? ''));
    $preferWebp = (bool) ($storeSettings['images']['use_webp'] ?? false);
    $slides = $block->getMedia('block_slides')->take(2);
    $displayTitle = trim((string) ($translation?->title ?? ''));
    $displaySubtitle = trim((string) ($translation?->subtitle ?? ''));

    if ($displayTitle === '') {
        $allTranslations = $block->translations()->get(['locale', 'title']);

        $displayTitle = trim((string) ($allTranslations->firstWhere('locale', $locale)?->title ?? ''));
        if ($displayTitle === '') {
            $displayTitle = trim((string) ($allTranslations->firstWhere('locale', $fallbackLocale)?->title ?? ''));
        }
        if ($displayTitle === '') {
            $displayTitle = trim((string) ($allTranslations->first(
                static fn ($row): bool => trim((string) ($row->title ?? '')) !== ''
            )?->title ?? ''));
        }
    }
@endphp

@if ($slides->isNotEmpty())
    <section class="relative left-1/2 w-screen -translate-x-1/2 {{ $customClasses }}">
        <div class="mx-auto w-full max-w-[1180px] px-5 sm:px-8 lg:px-10 xl:px-12">
            @if ($displayTitle !== '' || $displaySubtitle !== '')
                <div class="mb-8 text-center sm:mb-10">
                    @if ($displayTitle !== '')
                        <div class="mx-auto flex max-w-3xl items-center gap-4 md:gap-6">
                            @include('front.partials.section-heading-line', ['side' => 'left'])
                            <h2 class="text-[1.35rem] leading-[1.95rem] font-semibold uppercase text-slate-900 sm:text-[1.7rem] sm:leading-[2.5rem]">{{ $displayTitle }}</h2>
                            @include('front.partials.section-heading-line', ['side' => 'right'])
                        </div>
                    @endif

                    @if ($displaySubtitle !== '')
                        <p class="mx-auto mt-2 max-w-2xl text-sm text-slate-600 md:text-base">{{ $displaySubtitle }}</p>
                    @endif
                </div>
            @endif

            <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
            @foreach ($slides as $media)
                @php
                    $imageUrl1200 = \App\Support\Media\MediaUrl::conversionOrNull($media, 'hero_1200w', $preferWebp);
                    $imageUrl960 = \App\Support\Media\MediaUrl::conversionOrNull($media, 'hero_960w', $preferWebp);
                    $imageUrl800 = \App\Support\Media\MediaUrl::conversionOrNull($media, 'hero_800w', $preferWebp);
                    $imageUrl720 = \App\Support\Media\MediaUrl::conversionOrNull($media, 'hero_720w', $preferWebp);
                    $imageUrl540 = \App\Support\Media\MediaUrl::conversionOrNull($media, 'hero_540w', $preferWebp);
                    $imageUrl = $imageUrl1200 ?? $imageUrl960 ?? $imageUrl800 ?? $imageUrl720 ?? $imageUrl540 ?? $media->getUrl();
                    $imageSrcset = collect([
                        $imageUrl540 ? $imageUrl540.' 540w' : null,
                        $imageUrl720 ? $imageUrl720.' 720w' : null,
                        $imageUrl800 ? $imageUrl800.' 800w' : null,
                        $imageUrl960 ? $imageUrl960.' 960w' : null,
                        $imageUrl1200 ? $imageUrl1200.' 1200w' : null,
                    ])->filter()->unique()->implode(', ');
                    $imageWidth = max(1, (int) ($media->width ?? 1200));
                    $imageHeight = max(1, (int) ($media->height ?? 700));
                    $props = (array) ($media->custom_properties ?? []);
                    $slideTitle = trim((string) (
                        data_get($props, "block_title.$locale")
                        ?: data_get($props, "block_title.$fallbackLocale")
                    ));

                    $cta1Label = trim((string) (
                        data_get($props, "cta_1_label.$locale")
                        ?: data_get($props, "cta_1_label.$fallbackLocale")
                        ?: __('ui.content_blocks.dual_image_cta.default_cta_1')
                    ));
                    $cta1UrlMap = (array) data_get($props, 'cta_1_url', []);
                    $cta1Url = trim((string) (
                        data_get($props, "cta_1_url.$locale")
                        ?: data_get($props, "cta_1_url.$fallbackLocale")
                        ?: data_get($props, 'cta_1_url_value')
                        ?: collect($cta1UrlMap)->first(fn ($value) => trim((string) $value) !== '')
                        ?: '#'
                    ));

                    $cta2Label = trim((string) (
                        data_get($props, "cta_2_label.$locale")
                        ?: data_get($props, "cta_2_label.$fallbackLocale")
                        ?: __('ui.content_blocks.dual_image_cta.default_cta_2')
                    ));
                    $cta2UrlMap = (array) data_get($props, 'cta_2_url', []);
                    $cta2Url = trim((string) (
                        data_get($props, "cta_2_url.$locale")
                        ?: data_get($props, "cta_2_url.$fallbackLocale")
                        ?: data_get($props, 'cta_2_url_value')
                        ?: collect($cta2UrlMap)->first(fn ($value) => trim((string) $value) !== '')
                        ?: '#'
                    ));
                @endphp

                <article class="group relative overflow-hidden">
                    <img
                        src="{{ $imageUrl }}"
                        @if ($imageSrcset !== '') srcset="{{ $imageSrcset }}" @endif
                        sizes="(max-width: 767px) 100vw, (max-width: 1536px) 45vw, 680px"
                        alt="{{ $slideTitle !== '' ? $slideTitle : $block->name }}"
                        class="h-auto w-full bg-slate-100 object-contain transition duration-500 group-hover:scale-[1.02]"
                        width="{{ $imageWidth }}"
                        height="{{ $imageHeight }}"
                        @if ($loop->first)
                            loading="eager"
                            fetchpriority="high"
                        @else
                            loading="lazy"
                        @endif
                        decoding="async"
                    >
                    <div class="absolute inset-0 bg-gradient-to-t from-black/55 via-black/20 to-transparent"></div>

                    <div class="absolute inset-x-0 bottom-12 px-8 text-center text-white md:bottom-16 md:px-10">
                        @if ($slideTitle !== '')
                            <h3 class="text-3xl font-black uppercase tracking-[0.02em] md:text-4xl">{{ $slideTitle }}</h3>
                        @endif

                        <div class="mx-auto mt-5 flex max-w-[460px] flex-col justify-center gap-2.5 sm:flex-row">
                            @if ($cta1Label !== '')
                                <a href="{{ $cta1Url !== '' ? $cta1Url : '#' }}" class="inline-flex h-11 min-w-[145px] items-center justify-center border border-white bg-white px-5 text-base font-black uppercase tracking-[0.02em] text-slate-900 transition hover:bg-slate-100">
                                    {{ $cta1Label }}
                                </a>
                            @endif

                            @if ($cta2Label !== '')
                                <a href="{{ $cta2Url !== '' ? $cta2Url : '#' }}" class="inline-flex h-11 min-w-[145px] items-center justify-center border border-white bg-white px-5 text-base font-black uppercase tracking-[0.02em] text-slate-900 transition hover:bg-slate-100">
                                    {{ $cta2Label }}
                                </a>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
            </div>
        </div>
    </section>
@endif
