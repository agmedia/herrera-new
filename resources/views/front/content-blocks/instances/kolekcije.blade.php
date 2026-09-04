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

{{-- The "kolekcije" override uses only the block-level header above the cards. --}}
@if ($slides->isNotEmpty())
    <section class="relative left-1/2 w-screen -translate-x-1/2 overflow-hidden bg-[linear-gradient(180deg,_#eef2f3_0%,_#e8edf0_46%,_#f6f7f7_100%)] py-8 sm:py-10 lg:py-14 {{ $customClasses }}">
        <div class="pointer-events-none absolute inset-0">
            <div class="absolute inset-x-0 top-0 h-px bg-black/8"></div>
            <div class="absolute inset-x-0 bottom-0 h-px bg-black/6"></div>
            <div
                class="absolute inset-0 opacity-[0.58]"
                style="background-image: linear-gradient(rgba(255,255,255,0.34) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.34) 1px, transparent 1px); background-size: 34px 34px;"
            ></div>
            <div
                class="absolute inset-0"
                style="background: radial-gradient(58% 92% at 12% 20%, rgba(255,255,255,0.58) 0%, rgba(255,255,255,0) 48%), radial-gradient(42% 74% at 88% 22%, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0) 44%), linear-gradient(180deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0) 34%, rgba(15,23,42,0.03) 100%);"
            ></div>
        </div>
        <div class="relative mx-auto w-full max-w-[1180px] px-5 sm:px-8 lg:px-10 xl:px-12">
            @if ($displayTitle !== '' || $displaySubtitle !== '')
                <div class="mb-8 text-center sm:mb-10">
                    @if ($displayTitle !== '')
                        <div class="mx-auto flex max-w-3xl items-center gap-4 md:gap-6">
                            @include('front.partials.section-heading-line', ['side' => 'left'])
                            <h2 class="text-[1.35rem] leading-[1.95rem] sm:text-[1.7rem] sm:leading-[2.5rem] uppercase font-semibold text-slate-900">{{ $displayTitle }}</h2>
                            @include('front.partials.section-heading-line', ['side' => 'right'])
                        </div>
                    @endif

                    @if ($displaySubtitle !== '')
                        <p class="mx-auto mt-2 max-w-2xl text-sm text-slate-600 md:text-base">{{ $displaySubtitle }}</p>
                    @endif
                </div>
            @endif

            <div class="grid grid-cols-2 gap-6 md:grid-cols-2 md:items-start lg:gap-10 xl:gap-12">
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
                        $blockTitleMap = (array) data_get($props, 'block_title', []);
                        $linkUrlMap = (array) data_get($props, 'link_url', []);
                        $cta1LabelMap = (array) data_get($props, 'cta_1_label', []);
                        $cta1UrlMap = (array) data_get($props, 'cta_1_url', []);
                        $cta2LabelMap = (array) data_get($props, 'cta_2_label', []);
                        $cta2UrlMap = (array) data_get($props, 'cta_2_url', []);
                        $slideTitle = '';
                        $slideLinkUrl = trim((string) (
                            data_get($props, "link_url.$locale")
                            ?: data_get($props, "link_url.$fallbackLocale")
                            ?: collect($linkUrlMap)->first(fn ($value) => trim((string) $value) !== '')
                            ?: data_get($props, 'link_url_value', '')
                        ));

                        $cta1Label = trim((string) (
                            data_get($props, "cta_1_label.$locale")
                            ?: data_get($props, "cta_1_label.$fallbackLocale")
                            ?: collect($cta1LabelMap)->first(fn ($value) => trim((string) $value) !== '')
                        ));
                        $cta1Url = trim((string) (
                            data_get($props, "cta_1_url.$locale")
                            ?: data_get($props, "cta_1_url.$fallbackLocale")
                            ?: collect($cta1UrlMap)->first(fn ($value) => trim((string) $value) !== '')
                        ));

                        $cta2Label = trim((string) (
                            data_get($props, "cta_2_label.$locale")
                            ?: data_get($props, "cta_2_label.$fallbackLocale")
                            ?: collect($cta2LabelMap)->first(fn ($value) => trim((string) $value) !== '')
                        ));
                        $cta2Url = trim((string) (
                            data_get($props, "cta_2_url.$locale")
                            ?: data_get($props, "cta_2_url.$fallbackLocale")
                            ?: collect($cta2UrlMap)->first(fn ($value) => trim((string) $value) !== '')
                        ));
                        $genderProbe = \Illuminate\Support\Str::of(implode(' ', [
                            $cta1Label,
                            $cta1Url,
                            $cta2Label,
                            $cta2Url,
                            (string) ($media->name ?? ''),
                            (string) ($media->file_name ?? ''),
                        ]))->ascii()->lower()->toString();
                        $overlayGender = null;

                        if (str_contains($genderProbe, 'muskar') || str_contains($genderProbe, 'men') || str_contains($genderProbe, 'man')) {
                            $overlayGender = 'male';
                        } elseif (str_contains($genderProbe, 'zene') || str_contains($genderProbe, 'zena') || str_contains($genderProbe, 'women') || str_contains($genderProbe, 'woman')) {
                            $overlayGender = 'female';
                        }

                        if ($overlayGender === null) {
                            $overlayGender = $loop->first ? 'female' : 'male';
                        }

                        $overlayPositionClass = 'inset-0 items-center';
                        $overlayIconClass = $overlayGender === 'female'
                            ? 'w-[43%] max-w-[285px] md:w-[39%] md:max-w-[315px]'
                            : 'w-[38%] max-w-[250px] md:w-[34%] md:max-w-[280px]';
                        $overlayAsset = asset(
                            'front-theme/images/overlays/'.($overlayGender === 'female' ? 'women-gender.png' : 'man-gender.png')
                        );
                        $hasSlideContent = ($cta1Label !== '' && $cta1Url !== '')
                            || ($cta2Label !== '' && $cta2Url !== '');
                        $useWholeSlideLink = $slideLinkUrl !== '' && ! $hasSlideContent;
                        $articleAlignClass = $loop->first
                            ? 'md:justify-self-end md:pt-6'
                            : 'md:justify-self-start md:pt-14';
                        $showBackplate = true;
                        $backplateClass = $loop->first
                            ? '-left-7 -bottom-7'
                            : '-right-7 top-6';
                        $backplateFillStyle = 'background: linear-gradient(180deg, rgba(255,255,255,0.46) 0%, rgba(232,237,242,0.78) 100%);';
                        $backplatePatternStyle = 'background-image: linear-gradient(180deg, rgba(255,255,255,0.22) 0%, rgba(255,255,255,0) 48%);';
                    @endphp

                    <article class="group relative isolate mx-auto w-full max-w-[500px] {{ $articleAlignClass }}">
                        @if ($showBackplate)
                            <div class="pointer-events-none absolute inset-0 -z-10 hidden md:block">
                                <div class="absolute {{ $backplateClass }} h-[88%] w-[92%] overflow-hidden border border-black/6 shadow-[0_28px_60px_-46px_rgba(15,23,42,0.45)]">
                                    <div class="absolute inset-0" style="{{ $backplateFillStyle }}"></div>
                                    <div class="absolute inset-0 opacity-90" style="{{ $backplatePatternStyle }}"></div>
                                </div>
                            </div>
                        @endif
                        @if ($useWholeSlideLink)
                            <a href="{{ $slideLinkUrl }}" class="block">
                        @endif
                        <div class="relative overflow-hidden shadow-[0_32px_64px_-40px_rgba(15,23,42,0.42)]">
                            <img
                                src="{{ $imageUrl }}"
                                @if ($imageSrcset !== '') srcset="{{ $imageSrcset }}" @endif
                                sizes="(max-width: 767px) calc(100vw - 1rem), (max-width: 1023px) calc(100vw - 3rem), (max-width: 1536px) calc((100vw - 4rem) / 2), 680px"
                                alt="{{ $slideTitle !== '' ? $slideTitle : $block->name }}"
                                class="relative z-0 h-auto w-full bg-transparent object-contain transition duration-500 group-hover:scale-[1.02]"
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
                            <div class="pointer-events-none absolute inset-0 z-[1] bg-black opacity-0 transition duration-300 group-hover:opacity-50"></div>

                            @if ($overlayGender !== null)
                                <div class="pointer-events-none absolute {{ $overlayPositionClass }} z-10 flex justify-center opacity-0 transition duration-500 group-hover:opacity-100">
                                    <img src="{{ $overlayAsset }}" alt="" class="h-auto object-contain {{ $overlayIconClass }} opacity-36 drop-shadow-[0_14px_34px_rgba(255,255,255,0.1)] transition duration-500" loading="lazy" decoding="async" aria-hidden="true">
                                </div>
                            @endif
                        </div>
                        @if ($useWholeSlideLink)
                            </a>
                        @endif

                        @if ($hasSlideContent)
                            <div class="absolute inset-0 z-20 flex items-center justify-center px-8 text-center text-white md:px-10">
                                <div class="w-full max-w-[520px]">
                                    @if ($slideTitle !== '')
                                        <h3 class="text-3xl font-black uppercase tracking-[0.02em] md:text-4xl">{{ $slideTitle }}</h3>
                                    @endif

                                    @if (($cta1Label !== '' && $cta1Url !== '') || ($cta2Label !== '' && $cta2Url !== ''))
                                        <div class="mx-auto mt-5 flex max-w-[460px] flex-col justify-center gap-2.5 sm:flex-row">
                                            @if ($cta1Label !== '' && $cta1Url !== '')
                                                <a href="{{ $cta1Url }}" class="inline-flex h-11 min-w-[145px] items-center justify-center border border-white bg-white px-5 text-base font-black uppercase tracking-[0.02em] text-slate-900 transition hover:bg-slate-100">
                                                    {{ $cta1Label }}
                                                </a>
                                            @endif

                                            @if ($cta2Label !== '' && $cta2Url !== '')
                                                <a href="{{ $cta2Url }}" class="inline-flex h-11 min-w-[145px] items-center justify-center border border-white bg-white px-5 text-base font-black uppercase tracking-[0.02em] text-slate-900 transition hover:bg-slate-100">
                                                    {{ $cta2Label }}
                                                </a>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        </div>
    </section>
@endif
