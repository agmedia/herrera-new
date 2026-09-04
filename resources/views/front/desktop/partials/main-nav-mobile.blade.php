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
                <details class="group/nav desktop-mobile-menu-group" data-mobile-menu-accordion>
                    <summary class="desktop-mobile-menu-row relative flex min-h-[60px] cursor-pointer list-none items-center px-4 py-3 hover:bg-slate-50">
                        <a
                            href="{{ $item['url'] ?? '#' }}"
                            class="min-w-0 flex-1 truncate pr-12 text-[16px] font-bold tracking-[-0.01em]"
                            data-mobile-nav-link
                            @if($target) target="{{ $target }}" rel="{{ $rel }}" @endif
                        >
                            {{ $item['label'] }}
                        </a>
                        <button
                            type="button"
                            class="absolute right-3 top-1/2 z-10 inline-flex h-10 w-10 -translate-y-1/2 touch-manipulation items-center justify-center border border-slate-300 bg-white p-0 text-slate-600"
                            aria-label="{{ __('ui.front.desktop.open_navigation') }}: {{ $item['label'] }}"
                            aria-expanded="false"
                            data-mobile-menu-toggle
                            data-mobile-menu-toggle-open
                        >
                            <x-fa-icon name="plus" class="h-[18px] w-[18px]" />
                        </button>
                        <button
                            type="button"
                            class="absolute right-3 top-1/2 z-10 hidden h-10 w-10 -translate-y-1/2 touch-manipulation items-center justify-center border border-slate-300 bg-white p-0 text-slate-600"
                            aria-label="{{ __('ui.front.desktop.close_navigation') }}: {{ $item['label'] }}"
                            aria-expanded="true"
                            data-mobile-menu-toggle
                            data-mobile-menu-toggle-close
                        >
                            <x-fa-icon name="minus" class="h-[18px] w-[18px]" />
                        </button>
                    </summary>
                    <ul class="desktop-mobile-menu-children text-[13px]">
                        @foreach ($children as $child)
                            @include('front.desktop.partials.main-nav-mobile-child', ['child' => $child, 'level' => 0])
                        @endforeach
                    </ul>
                </details>
            @else
                <a href="{{ $item['url'] ?? '#' }}" class="desktop-mobile-menu-row flex min-h-[60px] items-center px-4 py-3 text-[16px] font-bold tracking-[-0.01em] hover:bg-slate-50" @if($target) target="{{ $target }}" rel="{{ $rel }}" @endif>{{ $item['label'] }}</a>
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
