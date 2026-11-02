@if ($canViewPrices)
    @php
        $canViewStock = $canViewStock ?? app(\App\Services\Front\CartStaffAccess::class)->canViewStock(request());
        $hasLocalAvailability = $product->hasVisibleOptionRows()
            ? $product->hasAvailableOptionRows()
            : $localStockQuantity > 0;
        $hasSupplierAvailability = ! $product->hasVisibleOptionRows() && $supplierStockQuantity > 0;
    @endphp
    <dl class="mt-3 space-y-1 text-[10px] leading-tight sm:text-[11px]" data-product-availability>
        @if ($canViewStock)
        <div class="flex items-center justify-between gap-2">
            <dt class="text-slate-500">{{ __('Dostupnost 48 sati:') }}</dt>
            <dd class="shrink-0 font-semibold {{ $localStockQuantity > 0 ? 'text-emerald-700' : 'text-slate-500' }}" data-product-local-stock>{{ number_format($localStockQuantity, 0, ',', '.') }}</dd>
        </div>
        <div class="flex items-center justify-between gap-2">
            <dt class="text-slate-500">{{ __('Dostupnost 14 dana:') }}</dt>
            <dd class="shrink-0 font-semibold {{ $supplierStockQuantity > 0 ? 'text-emerald-700' : 'text-slate-500' }}" data-product-supplier-stock>{{ number_format($supplierStockQuantity, 0, ',', '.') }}</dd>
        </div>
        @else
            <div class="flex items-center justify-between gap-2">
                <dt class="text-slate-500">{{ __('stock.delivery_time') }}:</dt>
                <dd class="shrink-0 font-semibold {{ $hasLocalAvailability || $hasSupplierAvailability ? 'text-emerald-700' : 'text-slate-500' }}" data-product-delivery-time>{{ $hasLocalAvailability ? __('stock.local_delivery') : ($hasSupplierAvailability ? __('stock.supplier_delivery') : __('ui.product.unavailable')) }}</dd>
            </div>
        @endif
    </dl>
@endif
