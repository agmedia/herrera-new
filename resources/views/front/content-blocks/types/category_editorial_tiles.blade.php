@php
    $locale = app()->getLocale();
    $fallbackLocale = config('app.locale');
    $translationPayload = is_array($translation?->payload ?? null) ? $translation->payload : [];
    $preferWebp = (bool) ($storeSettings['images']['use_webp'] ?? false);
    $slides = $block->getMedia('block_slides')->take(3)->values();

    $resolveLocalizedMediaValue = static function (array $props, string $key) use ($locale, $fallbackLocale): string {
        $values = (array) data_get($props, $key, []);

        return trim((string) (
            data_get($props, $key.'.'.$locale)
            ?: data_get($props, $key.'.'.$fallbackLocale)
            ?: collect($values)->first(fn ($value) => trim((string) $value) !== '')
            ?: data_get($props, $key.'_value', '')
        ));
    };

    $cards = $slides->map(function ($media, int $index) use ($preferWebp, $resolveLocalizedMediaValue, $block) {
        $props = (array) ($media->custom_properties ?? []);
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
        $cardTitle = $resolveLocalizedMediaValue($props, 'block_title');
        $cardUrl = $resolveLocalizedMediaValue($props, 'link_url');
        $cardAlt = $resolveLocalizedMediaValue($props, 'alt');
        $cardAlt = $cardAlt !== '' ? $cardAlt : ($cardTitle !== '' ? $cardTitle : ($block->name.' '.($index + 1)));

        return [
            'title' => $cardTitle,
            'url' => $cardUrl,
            'alt' => $cardAlt,
            'image_url' => $imageUrl,
            'image_srcset' => $imageSrcset,
            'width' => max(1, (int) ($media->width ?? 1200)),
            'height' => max(1, (int) ($media->height ?? 900)),
            'is_primary' => $index === 0,
        ];
    });

    $emptyMessage = trim((string) ($translationPayload['empty_message'] ?? 'Add 3 block slides to render this category editorial block.'));
@endphp

@if ($cards->isEmpty())
    <section class="border border-dashed border-slate-300 bg-slate-50 px-5 py-10 text-center text-sm text-slate-500">
        {{ $emptyMessage }}
    </section>
@else
    <section class="pt-3 sm:pt-4">
        <div class="grid gap-4 lg:grid-cols-[minmax(0,1.04fr)_minmax(0,1fr)] lg:grid-rows-2">
            @foreach ($cards as $card)
                @php
                    $articleClasses = $card['is_primary']
                        ? 'lg:row-span-2 min-h-[25rem] sm:min-h-[30rem] lg:min-h-[43rem]'
                        : 'min-h-[15rem] sm:min-h-[18rem] lg:min-h-[20.875rem]';
                    $titleClasses = 'text-[1.2rem] leading-[1.05] sm:text-[1.45rem] lg:text-[1.65rem]';
                @endphp

                <article class="group {{ $articleClasses }}">
                    @if ($card['url'] !== '')
                        <a href="{{ $card['url'] }}" class="block h-full">
                    @endif
                        <div class="relative h-full overflow-hidden bg-slate-100">
                            <img
                                src="{{ $card['image_url'] }}"
                                @if ($card['image_srcset'] !== '') srcset="{{ $card['image_srcset'] }}" @endif
                                sizes="{{ $card['is_primary'] ? '(max-width: 1023px) 100vw, 52vw' : '(max-width: 1023px) 100vw, 42vw' }}"
                                alt="{{ $card['alt'] }}"
                                class="absolute inset-0 h-full w-full object-cover transition duration-500 {{ $card['url'] !== '' ? 'group-hover:scale-[1.02]' : '' }}"
                                width="{{ $card['width'] }}"
                                height="{{ $card['height'] }}"
                                loading="{{ $loop->first ? 'eager' : 'lazy' }}"
                                @if ($loop->first) fetchpriority="high" @endif
                                decoding="async"
                            >
                            <div class="absolute inset-0 bg-gradient-to-t from-black/42 via-black/10 to-transparent"></div>
                            <div class="absolute inset-0 bg-gradient-to-t from-black/78 via-black/34 to-black/8 opacity-0 transition duration-300 group-hover:opacity-100"></div>

                            @if ($card['title'] !== '')
                                <div class="absolute inset-x-0 bottom-0 p-4 sm:p-5 lg:p-6">
                                    <h3 class="max-w-[20rem] font-semibold tracking-[-0.025em] text-white {{ $titleClasses }}">
                                        {{ $card['title'] }}
                                    </h3>
                                </div>
                            @endif
                        </div>
                    @if ($card['url'] !== '')
                        </a>
                    @endif
                </article>
            @endforeach
        </div>
    </section>
@endif
