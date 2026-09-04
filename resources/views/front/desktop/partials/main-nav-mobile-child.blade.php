@php
    $children = collect($child['children'] ?? []);
    $depthClass = 'desktop-mobile-depth-'.min(4, max(0, (int) $level));
    $labelWeightClass = $level === 0 ? 'font-semibold' : 'font-medium';
    $leafWeightClass = $level === 0 ? 'font-medium' : 'font-light';
    $target = !empty($child['open_in_new_tab']) ? '_blank' : null;
    $rel = !empty($child['open_in_new_tab']) ? 'noopener noreferrer' : null;
    $imageUrl = trim((string) ($child['image_url'] ?? ''));
    $categoryRowClass = $level === 0
        ? 'desktop-mobile-menu-row--main-category'
        : 'desktop-mobile-menu-row--nested-category';
@endphp

<li>
@if ($children->isNotEmpty())
        <details class="group/subnav desktop-mobile-menu-group" data-mobile-menu-accordion>
            <summary class="{{ $depthClass }} {{ $categoryRowClass }} desktop-mobile-menu-row relative flex min-h-[52px] cursor-pointer list-none items-center py-3 pr-3 text-slate-700 hover:bg-slate-50 hover:text-slate-900">
                <a
                    href="{{ $child['url'] ?? '#' }}"
                    class="min-w-0 flex-1 truncate pr-11 {{ $labelWeightClass }}"
                    data-mobile-nav-link
                    @if($target) target="{{ $target }}" rel="{{ $rel }}" @endif
                >
                    <span class="desktop-mobile-menu-item-main">
                        @if ($imageUrl !== '')
                            <span class="desktop-mobile-menu-thumb" aria-hidden="true">
                                <img src="{{ $imageUrl }}" alt="" loading="lazy" decoding="async">
                            </span>
                        @endif
                        <span class="desktop-mobile-menu-label">{{ $child['label'] ?? '' }}</span>
                    </span>
                </a>
                <button
                    type="button"
                    class="absolute right-3 top-1/2 z-10 inline-flex h-10 w-10 -translate-y-1/2 touch-manipulation items-center justify-center border border-slate-300 bg-white p-0 text-slate-500"
                    aria-label="{{ __('ui.front.desktop.open_navigation') }}: {{ $child['label'] ?? '' }}"
                    aria-expanded="false"
                    data-mobile-menu-toggle
                    data-mobile-menu-toggle-open
                >
                    <x-fa-icon name="plus" class="h-[18px] w-[18px]" />
                </button>
                <button
                    type="button"
                    class="absolute right-3 top-1/2 z-10 hidden h-10 w-10 -translate-y-1/2 touch-manipulation items-center justify-center border border-slate-300 bg-white p-0 text-slate-500"
                    aria-label="{{ __('ui.front.desktop.close_navigation') }}: {{ $child['label'] ?? '' }}"
                    aria-expanded="true"
                    data-mobile-menu-toggle
                    data-mobile-menu-toggle-close
                >
                    <x-fa-icon name="minus" class="h-[18px] w-[18px]" />
                </button>
            </summary>
            <ul class="desktop-mobile-menu-children">
                @foreach ($children as $nestedChild)
                    @include('front.desktop.partials.main-nav-mobile-child', ['child' => $nestedChild, 'level' => $level + 1])
                @endforeach
            </ul>
        </details>
    @else
        <a href="{{ $child['url'] ?? '#' }}" class="{{ $depthClass }} {{ $categoryRowClass }} desktop-mobile-menu-row flex min-h-[52px] items-center py-3 {{ $leafWeightClass }} text-slate-700 hover:bg-slate-100 hover:text-slate-900">
            <span class="desktop-mobile-menu-item-main">
                @if ($imageUrl !== '')
                    <span class="desktop-mobile-menu-thumb" aria-hidden="true">
                        <img src="{{ $imageUrl }}" alt="" loading="lazy" decoding="async">
                    </span>
                @endif
                <span class="desktop-mobile-menu-label">{{ $child['label'] ?? '' }}</span>
            </span>
        </a>
    @endif
</li>
