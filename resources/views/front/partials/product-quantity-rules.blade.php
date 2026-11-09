@php
    $quantityMinimum = max(1, (int) ($quantityProduct->minimum_order_quantity ?? 1));
    $quantityStep = max(1, (int) ($quantityProduct->order_quantity_step ?? 1));
    $quantityUnit = \App\Models\Catalog\Product\Product::unitOptions()[$quantityProduct->unit_of_measure ?: 'pcs'] ?? $quantityProduct->unit_of_measure;
@endphp
@if ($quantityMinimum > 1 || $quantityStep > 1)
    <p class="{{ $quantityRulesClass ?? 'mt-2 text-xs leading-5 text-slate-600' }}" data-product-quantity-rules>{{ __('order_quantity.rules', ['minimum' => $quantityMinimum, 'step' => $quantityStep, 'unit' => $quantityUnit]) }}</p>
@endif
