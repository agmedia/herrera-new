<div
    id="{{ $megaMenuId }}"
    class="site-main-nav-mega site-catalog-mega invisible absolute left-0 top-full z-50 mt-0 border border-slate-200 bg-white opacity-0 shadow-[0_28px_60px_-26px_rgba(15,23,42,0.48)] transition-all duration-150 group-hover/nav:visible group-hover/nav:opacity-100 group-focus-within/nav:visible group-focus-within/nav:opacity-100"
    data-catalog-mega
    data-catalog-mega-label="{{ $item['label'] }}"
    data-catalog-mega-url="{{ $href }}"
    data-catalog-mega-root-title="{{ __('Kategorije') }}"
    data-catalog-mega-max-columns="{{ $categoryColumnCount }}"
    role="region"
    aria-label="{{ $item['label'] }}"
>
    <script type="application/json" data-catalog-mega-tree>@json($children->values()->all(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>

    <div class="catalog-mega-layout {{ $hasPromo ? 'has-promo' : '' }}">
        <div
            class="catalog-mega-columns catalog-mega-columns-{{ $categoryColumnCount }}"
            data-catalog-mega-columns
        >
            @for ($columnIndex = 0; $columnIndex < $categoryColumnCount; $columnIndex++)
                <section
                    class="catalog-mega-column"
                    data-catalog-mega-column="{{ $columnIndex }}"
                    @if ($columnIndex > 0) hidden @endif
                >
                    <div class="catalog-mega-column-header">
                        <p class="catalog-mega-column-title" data-catalog-mega-column-title>
                            {{ $columnIndex === 0 ? __('Kategorije') : '' }}
                        </p>
                        <a
                            href="{{ $columnIndex === 0 ? $href : '#' }}"
                            class="catalog-mega-view-all"
                            data-catalog-mega-column-link
                            @if ($columnIndex > 0) hidden @endif
                        >
                            {{ __('Prikaži sve') }}
                        </a>
                    </div>
                    <ul class="catalog-mega-list" data-catalog-mega-list>
                        @if ($columnIndex === 0)
                            @foreach ($children as $category)
                                @php
                                    $categoryChildren = collect($category['children'] ?? []);
                                    $categoryImageUrl = trim((string) ($category['image_url'] ?? ''));
                                @endphp
                                <li>
                                    <a
                                        href="{{ $category['url'] ?? '#' }}"
                                        class="catalog-mega-item {{ $categoryImageUrl !== '' ? 'has-image' : '' }}"
                                        @if ($categoryChildren->isNotEmpty()) aria-haspopup="true" aria-expanded="false" @endif
                                    >
                                        <span class="catalog-mega-item-main">
                                            @if ($categoryImageUrl !== '')
                                                <span class="catalog-mega-item-thumb" aria-hidden="true">
                                                    <img src="{{ $categoryImageUrl }}" alt="" loading="lazy" decoding="async">
                                                </span>
                                            @endif
                                            <span class="catalog-mega-item-label">{{ $category['label'] ?? '' }}</span>
                                        </span>
                                        @if ($categoryChildren->isNotEmpty())
                                            <x-fa-icon name="chevron-right" />
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                        @endif
                    </ul>
                </section>
            @endfor
        </div>

        @if ($hasPromo)
            <aside class="catalog-mega-promo">
                @if ($promoCtaUrl !== '')
                    <a href="{{ $promoCtaUrl }}" class="group/promo block h-full">
                @endif
                    <div class="relative h-full min-h-[360px] overflow-hidden bg-slate-200">
                        @if ($promoImage !== '')
                            <img src="{{ $promoImage }}" alt="{{ $promoTitle !== '' ? $promoTitle : $item['label'] }}" class="h-full w-full object-cover transition duration-300 group-hover/promo:scale-[1.02]" loading="lazy" decoding="async">
                        @else
                            <div class="h-full w-full bg-gradient-to-br from-slate-200 via-slate-100 to-slate-300"></div>
                        @endif
                        <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/90 via-black/60 to-transparent p-5 text-white">
                            @if ($promoTitle !== '')
                                <p class="text-sm font-extrabold uppercase tracking-[0.06em]">{{ $promoTitle }}</p>
                            @endif
                            @if ($promoSubtitle !== '')
                                <p class="mt-1 text-xs text-white/90">{{ $promoSubtitle }}</p>
                            @endif
                            @if ($promoCtaLabel !== '' && $promoCtaUrl !== '')
                                <span class="mt-4 inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-white/95">
                                    {{ $promoCtaLabel }}
                                    <span aria-hidden="true">→</span>
                                </span>
                            @endif
                        </div>
                    </div>
                @if ($promoCtaUrl !== '')
                    </a>
                @endif
            </aside>
        @endif
    </div>
</div>
