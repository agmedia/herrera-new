@php
    $hasNavigation = !empty($mainNavigation ?? []);
    try {
        $showBlog = app(\App\Services\Catalog\CatalogFeatureService::class)->useBlog();
    } catch (\Throwable) {
        $showBlog = (bool) config('catalog_features.flags.catalog_use_blog', true);
    }
@endphp

@if ($hasNavigation)
    <div class="desktop-mobile-menu-list overflow-y-auto px-0 text-slate-900">
        @foreach ($mainNavigation as $item)
            @php
                $children = collect($item['children'] ?? []);
                $hasChildren = $children->isNotEmpty();
                $target = !empty($item['open_in_new_tab']) ? '_blank' : null;
                $rel = !empty($item['open_in_new_tab']) ? 'noopener noreferrer' : null;
            @endphp

            @if ($hasChildren)
                <details class="group/nav desktop-mobile-menu-group" data-mobile-menu-accordion @if (($item['type'] ?? '') === 'catalog') data-mobile-menu-catalog @endif>
                    <summary class="desktop-mobile-menu-row desktop-mobile-menu-row--section relative flex min-h-[60px] cursor-pointer list-none items-center px-4 py-3 hover:bg-slate-50">
                        <a
                            href="{{ $item['url'] ?? '#' }}"
                            class="desktop-mobile-menu-category-link min-w-0 flex-1 pr-12 text-[16px] font-bold tracking-[-0.01em]"
                            data-mobile-nav-link
                            @if($target) target="{{ $target }}" rel="{{ $rel }}" @endif
                        >
                            {{ $item['label'] }}
                        </a>
                        <button
                            type="button"
                            class="desktop-mobile-menu-toggle"
                            aria-label="{{ __('ui.front.desktop.open_navigation') }}: {{ $item['label'] }}"
                            aria-expanded="false"
                            data-mobile-menu-toggle
                            data-mobile-menu-toggle-open
                        >
                            <x-fa-icon name="chevron-down" class="h-[18px] w-[18px]" />
                        </button>
                        <button
                            type="button"
                            class="desktop-mobile-menu-toggle"
                            aria-label="{{ __('ui.front.desktop.close_navigation') }}: {{ $item['label'] }}"
                            aria-expanded="true"
                            data-mobile-menu-toggle
                            data-mobile-menu-toggle-close
                        >
                            <x-fa-icon name="chevron-up" class="h-[18px] w-[18px]" />
                        </button>
                    </summary>
                    <ul class="desktop-mobile-menu-children text-[13px]">
                        @foreach ($children as $child)
                            @include('front.desktop.partials.main-nav-mobile-child', ['child' => $child, 'level' => 0])
                        @endforeach
                    </ul>
                </details>
            @else
                <a href="{{ $item['url'] ?? '#' }}" class="desktop-mobile-menu-row desktop-mobile-menu-row--section flex min-h-[60px] items-center px-4 py-3 text-[16px] font-bold tracking-[-0.01em] hover:bg-slate-50" @if($target) target="{{ $target }}" rel="{{ $rel }}" @endif>{{ $item['label'] }}</a>
            @endif
        @endforeach
    </div>
@else
    <nav class="desktop-mobile-menu-list overflow-y-auto px-0 text-sm uppercase tracking-[0.03em] text-slate-900">
        <a href="{{ route('shop.index') }}" class="desktop-mobile-menu-row flex min-h-[56px] items-center px-4 py-3 text-[14px] font-semibold hover:bg-slate-50">{{ __('ui.front.desktop.nav.new') }}</a>
        <a href="{{ route('categories.index') }}" class="desktop-mobile-menu-row flex min-h-[56px] items-center px-4 py-3 text-[14px] font-semibold hover:bg-slate-50">Kategorije</a>
        @if ($showBlog)
            <a href="{{ route('blog.index') }}" class="desktop-mobile-menu-row flex min-h-[56px] items-center px-4 py-3 text-[14px] font-semibold hover:bg-slate-50">{{ __('ui.front.desktop.nav.blog') }}</a>
        @endif
        <a href="{{ route('faq.index') }}" class="desktop-mobile-menu-row flex min-h-[56px] items-center px-4 py-3 text-[14px] font-semibold hover:bg-slate-50">{{ __('ui.front.desktop.nav.faq') }}</a>
        <a href="{{ route('contact.create') }}" class="desktop-mobile-menu-row flex min-h-[56px] items-center px-4 py-3 text-[14px] font-semibold hover:bg-slate-50">{{ __('ui.front.desktop.nav.contact') }}</a>
    </nav>
@endif
