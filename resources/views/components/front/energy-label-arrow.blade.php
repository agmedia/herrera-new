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
        @if (! empty($declaration['energy_class_image_url']))
            <img
                src="{{ $declaration['energy_class_image_url'] }}"
                alt=""
                class="{{ $compact ? 'h-6 w-auto' : 'h-8 w-auto' }} max-w-[8rem] object-contain"
                width="{{ $compact ? 96 : 128 }}"
                height="{{ $compact ? 24 : 32 }}"
                loading="lazy"
                decoding="async"
                aria-hidden="true"
            >
        @else
            <span
                class="relative inline-flex {{ $compact ? 'h-6 min-w-10 px-2 text-xs' : 'h-8 min-w-12 px-2.5 text-sm' }} items-center justify-center font-black leading-none shadow-sm"
                style="background-color: {{ $declaration['color'] }}; color: {{ $declaration['text_color'] }}; clip-path: polygon(0 0, calc(100% - 0.55rem) 0, 100% 50%, calc(100% - 0.55rem) 100%, 0 100%); padding-right: 0.85rem;"
                aria-hidden="true"
            >
                {{ $declaration['energy_class'] }}
            </span>
            <span class="inline-flex {{ $compact ? 'h-6 px-1.5 text-[9px]' : 'h-8 px-2 text-[10px]' }} items-center border-y border-r border-slate-300 bg-white font-bold leading-none text-slate-700" aria-hidden="true">
                {{ $declaration['scale_label'] }}
            </span>
        @endif
    @if ($hasFullEnergyLabel)
        </a>
    @else
        </span>
    @endif
@endif
