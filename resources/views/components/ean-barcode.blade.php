@props(['value'])
@php
    $ean = trim((string) $value);
    $modules = app(\App\Support\Ean13Barcode::class)->modules($ean);
@endphp
@if ($modules !== null)
    <svg {{ $attributes->merge(['class' => 'block bg-white']) }} width="234" height="124" viewBox="0 0 117 62" role="img" aria-label="{{ __('EAN :ean', ['ean' => $ean]) }}" data-ean-barcode="{{ $ean }}" shape-rendering="crispEdges">
        <rect width="117" height="62" fill="white" />
        @foreach (str_split($modules) as $index => $module)
            @if ($module === '1')
                <rect x="{{ $index + 11 }}" y="3" width="1" height="{{ $index < 3 || ($index >= 45 && $index < 50) || $index >= 92 ? 47 : 43 }}" fill="black" />
            @endif
        @endforeach
        <text x="58.5" y="59" text-anchor="middle" font-family="monospace" font-size="8" fill="black" shape-rendering="auto">{{ $ean }}</text>
    </svg>
@elseif ($ean !== '')
    <span class="font-mono text-xs text-slate-700">{{ $ean }}</span>
@else
    <span class="text-xs text-slate-400">{{ __('Nema EAN koda') }}</span>
@endif
