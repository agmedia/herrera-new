@props([
    'declaration' => null,
    'compact' => true,
])

@if (is_array($declaration) && ! empty($declaration['product_information_sheet_url']))
    <a
        href="{{ $declaration['product_information_sheet_url'] }}"
        target="_blank"
        rel="noopener noreferrer"
        {{ $attributes->class([
            'inline-flex font-semibold text-blue-700 underline decoration-blue-400 underline-offset-2 hover:text-blue-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-700 focus-visible:ring-offset-2',
            $compact ? 'text-xs leading-tight' : 'text-sm',
        ]) }}
        aria-label="{{ __('ui.product.open_product_information_sheet') }}"
        data-product-information-sheet
    >
        {{ $compact ? __('ui.product.information_sheet_short') : __('ui.product.product_information_sheet') }}
    </a>
@endif
