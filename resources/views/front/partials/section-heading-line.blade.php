@php
    $side = ($side ?? 'left') === 'right' ? 'right' : 'left';
    $wrapperClass = trim((string) ($wrapperClass ?? 'flex min-w-0 flex-1 items-center gap-2.5 md:gap-3'));
    $iconClass = trim((string) ($iconClass ?? 'h-3.5 w-3.5 shrink-0 text-slate-400 md:h-4 md:w-4'));
    $lineClass = trim((string) ($lineClass ?? 'h-px flex-1'));
    $lineColor = trim((string) ($lineColor ?? '#cbd5e1'));
    $lineToneClass = strtolower($lineColor) === '#d6dee8'
        ? 'section-heading-line-track--soft'
        : 'section-heading-line-track--default';
@endphp

@if ($side === 'left')
    <span class="{{ $wrapperClass }}">
        <x-fa-icon name="scissors" class="{{ $iconClass }}" />
        <span class="section-heading-line-track {{ $lineToneClass }} {{ $lineClass }}"></span>
    </span>
@else
    <span class="{{ $wrapperClass }}">
        <span class="section-heading-line-track {{ $lineToneClass }} {{ $lineClass }}"></span>
        <x-fa-icon name="scissors" class="section-heading-line-icon--reverse {{ $iconClass }}" />
    </span>
@endif
