@php
    $hasNavigation = !empty($mainNavigation ?? []);
    try {
        $showBlog = app(\App\Services\Catalog\CatalogFeatureService::class)->useBlog();
    } catch (\Throwable) {
        $showBlog = (bool) config('catalog_features.flags.catalog_use_blog', true);
    }
@endphp

@if ($hasNavigation)
    @foreach ($mainNavigation as $item)
        @php
            $children = collect($item['children'] ?? []);
            $hasChildren = $children->isNotEmpty();
            $href = (string) ($item['url'] ?? '#');
            $target = !empty($item['open_in_new_tab']) ? '_blank' : null;
            $rel = !empty($item['open_in_new_tab']) ? 'noopener noreferrer' : null;
            $isCatalogItem = (string) ($item['type'] ?? '') === 'catalog';
            $itemClass = !empty($item['is_highlighted']) ? 'is-highlighted' : '';
        @endphp

        @if ($hasChildren)
            <div class="group/nav h-full min-w-0">
                @php
                    $megaMenuId = 'site-main-nav-mega-'.$loop->index;
                @endphp
                <a
                    href="{{ $href }}"
                    class="site-main-nav-link {{ !empty($herreraCatalogHeader) ? 'herrera-catalog-control' : '' }} {{ $itemClass }} inline-flex h-full w-full items-center justify-center gap-2 px-3 text-[15px] font-bold tracking-[-0.01em] transition focus-visible:outline-none"
                    aria-haspopup="true"
                    aria-controls="{{ $megaMenuId }}"
                    aria-expanded="false"
                    @if ($isCatalogItem) data-catalog-mega-trigger @endif
                    @if($target) target="{{ $target }}" rel="{{ $rel }}" @endif
                >
                    @if (!empty($herreraCatalogHeader))
                        <span class="herrera-catalog-icon" aria-hidden="true"><x-fa-icon :inline="!empty($herreraCatalogHeader)" name="bars" class="h-4 w-4" /></span>
                    @endif
                    <span>{{ $item['label'] }}</span>
                    @if ($isCatalogItem)
                        <x-fa-icon :inline="!empty($herreraCatalogHeader)" name="chevron-down" class="h-4 w-4 transition-transform group-hover/nav:rotate-180 group-focus-within/nav:rotate-180" />
                    @endif
                </a>

                @php
                    $promo = is_array($item['mega_promo'] ?? null) ? $item['mega_promo'] : [];
                    $promoImage = trim((string) ($promo['image_url'] ?? ''));
                    $promoTitle = trim((string) ($promo['title'] ?? ''));
                    $promoSubtitle = trim((string) ($promo['subtitle'] ?? ''));
                    $promoCtaLabel = trim((string) ($promo['cta_label'] ?? ''));
                    $promoCtaUrl = trim((string) ($promo['cta_url'] ?? ''));
                    $hasPromo = $promoImage !== '' || $promoTitle !== '' || $promoSubtitle !== '' || ($promoCtaLabel !== '' && $promoCtaUrl !== '');

                    $categoryColumnCount = 1;
                    if ($isCatalogItem) {
                        $measureTreeDepth = function ($nodes, int $depth = 1) use (&$measureTreeDepth): int {
                            $deepest = $depth;

                            foreach (collect($nodes) as $node) {
                                $nested = collect($node['children'] ?? []);
                                if ($nested->isNotEmpty()) {
                                    $deepest = max($deepest, $measureTreeDepth($nested, $depth + 1));
                                }
                            }

                            return $deepest;
                        };

                        // Glavne kategorije + najviše četiri sljedeće razine.
                        $categoryColumnCount = min(5, max(1, $measureTreeDepth($children)));
                    }
                @endphp

                @if ($isCatalogItem)
                    @if (!empty($deferCatalogMega))
                        @push('deferred-header-menus')
                            <template data-catalog-mega-template="{{ $megaMenuId }}">
                                @include('front.desktop.partials.catalog-mega')
                            </template>
                        @endpush
                    @else
                        @include('front.desktop.partials.catalog-mega')
                    @endif
                @else
                    @php
                        $megaBlocks = collect();
                        foreach ($children as $child) {
                            $megaBlocks->push([
                                'title' => (string) ($child['label'] ?? ''),
                                'url' => (string) ($child['url'] ?? '#'),
                                'items' => collect($child['children'] ?? []),
                            ]);
                        }
                    @endphp

                    <div id="{{ $megaMenuId }}" class="site-main-nav-mega invisible absolute left-0 top-full z-50 mt-0 border border-slate-200 bg-white px-7 pb-6 pt-4 opacity-0 shadow-[0_28px_60px_-26px_rgba(15,23,42,0.48)] transition-all duration-150 group-hover/nav:visible group-hover/nav:opacity-100 group-focus-within/nav:visible group-focus-within/nav:opacity-100">
                        <div class="mb-4 flex items-center justify-between border-b border-slate-200 pb-3">
                            <p class="text-[12px] font-black uppercase tracking-[0.15em] text-slate-500">{{ $item['label'] }}</p>
                            <a href="{{ $href }}" class="text-xs font-bold text-slate-700 transition hover:text-black hover:underline">{{ __('Prikaži sve') }}</a>
                        </div>
                        <div class="grid items-start gap-4 {{ $hasPromo ? 'grid-cols-[minmax(0,4fr)_minmax(240px,1fr)]' : 'grid-cols-1' }}">
                            <div class="max-h-[60vh] overflow-y-auto pr-2">
                                <div class="mx-auto columns-2 gap-7 lg:columns-3 xl:columns-4">
                                    @foreach ($megaBlocks as $block)
                                        <div class="mb-4 break-inside-avoid">
                                            <a href="{{ $block['url'] }}" class="text-[14px] font-black uppercase tracking-[0.035em] text-slate-950 transition hover:underline">
                                                {{ $block['title'] }}
                                            </a>
                                            <ul class="mt-1.5 space-y-0.5">
                                                @foreach ($block['items'] as $subChild)
                                                    @include('front.desktop.partials.main-nav-child', ['child' => $subChild, 'level' => 0])
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            @if ($hasPromo)
                                <aside class="hidden border border-slate-300 bg-white p-2 xl:block">
                                    @if ($promoCtaUrl !== '')
                                        <a href="{{ $promoCtaUrl }}" class="group/promo block">
                                    @endif
                                        <div class="relative aspect-[4/5] overflow-hidden bg-slate-200">
                                            @if ($promoImage !== '')
                                                <img src="{{ $promoImage }}" alt="{{ $promoTitle !== '' ? $promoTitle : $item['label'] }}" class="h-full w-full object-cover transition duration-300 group-hover/promo:scale-[1.02]" loading="lazy" decoding="async">
                                            @else
                                                <div class="h-full w-full bg-gradient-to-br from-slate-200 via-slate-100 to-slate-300"></div>
                                            @endif
                                            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/90 via-black/60 to-transparent p-3 text-white">
                                                @if ($promoTitle !== '')
                                                    <p class="text-sm font-extrabold uppercase tracking-[0.06em]">{{ $promoTitle }}</p>
                                                @endif
                                                @if ($promoSubtitle !== '')
                                                    <p class="mt-0.5 text-[11px] text-white/90">{{ $promoSubtitle }}</p>
                                                @endif
                                                @if ($promoCtaLabel !== '' && $promoCtaUrl !== '')
                                                    <span class="mt-3 inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-white/95">
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
                @endif
            </div>
        @else
            <a href="{{ $href }}" class="site-main-nav-link {{ !empty($herreraCatalogHeader) ? 'herrera-catalog-control' : '' }} {{ $itemClass }} inline-flex h-full min-w-0 items-center justify-center px-3 text-center text-[15px] font-bold tracking-[-0.01em] transition focus-visible:outline-none" @if($target) target="{{ $target }}" rel="{{ $rel }}" @endif>
                @if (!empty($herreraCatalogHeader))
                    <span class="herrera-catalog-icon" aria-hidden="true"><x-fa-icon :inline="true" name="bars" class="h-4 w-4" /></span>
                @endif
                <span>{{ $item['label'] }}</span>
            </a>
        @endif
    @endforeach
@else
    <a href="{{ route('shop.index') }}" class="inline-flex items-center py-6 hover:text-slate-600"><span class="border-b border-transparent pb-0.5 transition hover:border-slate-400">{{ __('ui.front.desktop.nav.new') }}</span></a>
    <a href="{{ route('categories.index') }}" class="inline-flex items-center py-6 hover:text-slate-600"><span class="border-b border-transparent pb-0.5 transition hover:border-slate-400">Kategorije</span></a>
    @if ($showBlog)
        <a href="{{ route('blog.index') }}" class="inline-flex items-center py-6 hover:text-slate-600"><span class="border-b border-transparent pb-0.5 transition hover:border-slate-400">{{ __('ui.front.desktop.nav.blog') }}</span></a>
    @endif
    <a href="{{ route('faq.index') }}" class="inline-flex items-center py-6 hover:text-slate-600"><span class="border-b border-transparent pb-0.5 transition hover:border-slate-400">{{ __('ui.front.desktop.nav.faq') }}</span></a>
    <a href="{{ route('contact.create') }}" class="inline-flex items-center py-6 hover:text-slate-600"><span class="border-b border-transparent pb-0.5 transition hover:border-slate-400">{{ __('ui.front.desktop.nav.contact') }}</span></a>
@endif
