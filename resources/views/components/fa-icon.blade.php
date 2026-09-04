@props([
    'name',
    'style' => 'solid',
])

@php
    $resolvedStyle = in_array($style, ['solid', 'regular', 'brands'], true) ? $style : 'solid';
    $resolvedName = preg_replace('/[^a-z0-9-]/', '', strtolower((string) $name));
    $storefrontIcons = [
        'solid' => [
            'arrow-right', 'arrow-up', 'bag-shopping', 'bars', 'check', 'chevron-down',
            'chevron-right', 'circle-check', 'circle-info', 'cookie-bite', 'credit-card',
            'grip', 'heart', 'list', 'lock', 'magnifying-glass', 'minus', 'plus',
            'rotate-left', 'scissors', 'sliders', 'table-cells', 'table-cells-large',
            'table-columns', 'triangle-exclamation', 'truck-fast', 'xmark',
        ],
        'regular' => ['heart', 'user'],
        'brands' => ['facebook-f', 'instagram', 'tiktok', 'youtube'],
    ];
    $spriteDirectory = in_array($resolvedName, $storefrontIcons[$resolvedStyle], true)
        ? 'storefront-sprites'
        : 'sprites';
    $spriteUrl = asset("front-theme/fonts/{$spriteDirectory}/{$resolvedStyle}.svg").'#'.$resolvedName;
@endphp

<svg
    {{ $attributes->class(['fa6-icon'])->merge(['fill' => 'currentColor']) }}
    aria-hidden="true"
    focusable="false"
>
    <use href="{{ $spriteUrl }}"></use>
</svg>
