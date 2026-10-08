@props([
    'declaration' => null,
    'compact' => true,
])

@if (is_array($declaration) && ($declaration['has_arrow'] ?? false))
    @php
        $hasFullEnergyLabel = ($declaration['is_complete'] ?? false)
            && ! empty($declaration['energy_label_url']);
        $arrowClasses = $attributes->class([
            'group/energy-label inline-flex max-w-full items-stretch align-middle no-underline',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-700 focus-visible:ring-offset-2' => $hasFullEnergyLabel,
        ]);
        $arrowLabel = __('ui.product.open_energy_label', [
            'class' => $declaration['energy_class'],
            'range' => $declaration['scale_label'],
        ]);
        $classFontSize = strlen($declaration['energy_class']) > 1 ? 23 : 32;
        $rangeFontSize = max(strlen($declaration['scale_min']), strlen($declaration['scale_max'])) > 1 ? 9 : 13;
    @endphp

    @if ($hasFullEnergyLabel)
        <a
            href="{{ $declaration['energy_label_url'] }}"
            target="_blank"
            rel="noopener noreferrer"
            {{ $arrowClasses }}
            aria-label="{{ $arrowLabel }}"
            title="{{ $arrowLabel }}"
            data-energy-label-arrow
        >
    @else
        <span
            {{ $arrowClasses }}
            role="img"
            aria-label="{{ __('ui.product.energy_class_and_range', [
                'class' => $declaration['energy_class'],
                'range' => $declaration['scale_label'],
            ]) }}"
            title="{{ __('ui.product.energy_class_and_range', [
                'class' => $declaration['energy_class'],
                'range' => $declaration['scale_label'],
            ]) }}"
            data-energy-label-arrow
        >
    @endif
        <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 100 52"
            width="{{ $compact ? 50 : 65.38 }}"
            height="{{ $compact ? 26 : 34 }}"
            class="block shrink-0"
            aria-hidden="true"
            focusable="false"
            data-energy-label-graphic
        >
            <title>{{ $declaration['energy_class'] }} · {{ $declaration['scale_label'] }}</title>
            <path d="M24 1H99V51H24L1 26Z" fill="#fff" />
            <path d="M24 1H77V51H24L1 26Z" fill="{{ $declaration['color'] }}" />
            <path d="M24 1H99V51H24L1 26Z" fill="none" stroke="#64748b" stroke-width="1" stroke-linejoin="round" />
            <path d="M77 1V51" stroke="#64748b" stroke-width="1" />
            <g font-family="Arial, Helvetica, sans-serif" font-weight="700" text-anchor="middle">
                <text x="48" y="27" dominant-baseline="central" font-size="{{ $classFontSize }}" fill="{{ $declaration['text_color'] }}">{{ $declaration['energy_class'] }}</text>
                <g font-size="{{ $rangeFontSize }}" fill="#24353b">
                    <text x="88" y="14">{{ $declaration['scale_min'] }}</text>
                    <text x="88" y="47">{{ $declaration['scale_max'] }}</text>
                </g>
            </g>
            <path d="M88 32V20M84 24L88 20L92 24" fill="none" stroke="#24353b" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    @if ($hasFullEnergyLabel)
        </a>
    @else
        </span>
    @endif
@endif
