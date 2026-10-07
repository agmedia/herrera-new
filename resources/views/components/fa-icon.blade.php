@props([
    'name',
    'style' => 'solid',
])

@php
    $spriteUrl = \App\Support\FontAwesomeIcon::url((string) $name, (string) $style);
@endphp

<svg
    {{ $attributes->class(['fa6-icon'])->merge(['fill' => 'currentColor']) }}
    aria-hidden="true"
    focusable="false"
>
    <use href="{{ $spriteUrl }}"></use>
</svg>
