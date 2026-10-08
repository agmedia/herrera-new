@props([
    'name',
    'style' => 'solid',
    'inline' => false,
])

@php
    $inlineIcon = $inline ? \App\Support\FontAwesomeIcon::inline((string) $name, (string) $style) : null;
    $spriteUrl = $inlineIcon === null ? \App\Support\FontAwesomeIcon::url((string) $name, (string) $style) : null;
    $svgDefaults = ['fill' => 'currentColor'];
    if ($inlineIcon !== null) {
        $svgDefaults['viewBox'] = $inlineIcon['viewBox'];
    }
@endphp

<svg
    {{ $attributes->class(['fa6-icon'])->merge($svgDefaults) }}
    aria-hidden="true"
    focusable="false"
>
    @if ($inlineIcon !== null)
        {!! $inlineIcon['content'] !!}
    @else
        <use href="{{ $spriteUrl }}"></use>
    @endif
</svg>
