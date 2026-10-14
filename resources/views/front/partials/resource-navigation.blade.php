@php
    $resourceMobile = $resourceMobile ?? false;
    $resourceHerreraPrimary = $resourceHerreraPrimary ?? false;
    $existingResourcePaths = [];
    $collectResourcePaths = function (array $items) use (&$collectResourcePaths, &$existingResourcePaths): void {
        foreach ($items as $item) {
            $existingResourcePaths[] = rtrim((string) parse_url($item['url'] ?? '', PHP_URL_PATH), '/');
            $collectResourcePaths($item['children'] ?? []);
        }
    };
    $collectResourcePaths($mainNavigation ?? []);
    $resourceLinks = [
        ['url' => route('supplier-catalogs.index'), 'label' => __('herrera_resources.catalogs_short')],
        ['url' => route('references.index'), 'label' => __('herrera_resources.references')],
    ];
@endphp
@foreach ($resourceLinks as $resourceLink)
    @unless (in_array(rtrim((string) parse_url($resourceLink['url'], PHP_URL_PATH), '/'), $existingResourcePaths, true))
        <a href="{{ $resourceLink['url'] }}" @if ($resourceHerreraPrimary) data-herrera-primary-link @else class="{{ $resourceMobile ? 'desktop-mobile-menu-row flex min-h-[56px] items-center px-4 py-3 text-[14px] font-semibold hover:bg-slate-50' : 'site-main-nav-link inline-flex h-full min-w-0 items-center justify-center px-3 text-center text-[15px] font-bold tracking-[-0.01em] transition focus-visible:outline-none' }}" @endif @if (request()->url() === $resourceLink['url']) aria-current="page" @endif>{{ $resourceLink['label'] }}</a>
    @endunless
@endforeach
