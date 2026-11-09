@if ($canViewPrices)
    @php
        $canViewStock = $canViewStock ?? app(\App\Services\Front\CartStaffAccess::class)->canViewStock(request());
        $hasLocalAvailability = $product->hasVisibleOptionRows()
            ? $product->hasAvailableOptionRows()
            : $localStockQuantity > 0;
        $hasSupplierAvailability = ! $product->hasVisibleOptionRows() && $supplierStockQuantity > 0;
        $deliveryEstimate = app(\App\Services\Shipping\SupplierDeliveryService::class)->estimate($product, max(1, (int) $product->minimum_order_quantity));
    @endphp
    <dl class="mt-3 space-y-1 text-[10px] leading-tight sm:text-[11px]" data-product-availability>
        @if ($canViewStock)
        <div class="flex items-center justify-between gap-2">
            <dt class="text-slate-500">{{ __('Vlastita zaliha:') }}</dt>
            <dd class="shrink-0 font-semibold {{ $localStockQuantity > 0 ? 'text-emerald-700' : 'text-slate-500' }}" data-product-local-stock>{{ number_format($localStockQuantity, 0, ',', '.') }}</dd>
        </div>
        <div class="flex items-center justify-between gap-2">
            <dt class="text-slate-500">{{ __('Zaliha dobavljača:') }}</dt>
            <dd class="shrink-0 font-semibold {{ $supplierStockQuantity > 0 ? 'text-emerald-700' : 'text-slate-500' }}" data-product-supplier-stock>{{ number_format($supplierStockQuantity, 0, ',', '.') }}</dd>
        </div>
        @else
            <div class="flex items-center justify-between gap-2">
                <dt class="text-slate-500">{{ __('stock.delivery_time') }}:</dt>
                <dd class="text-right font-semibold {{ $hasLocalAvailability || $hasSupplierAvailability ? 'text-emerald-700' : 'text-slate-500' }}" data-product-delivery-time>{{ $product->hasVisibleOptionRows() ? ($hasLocalAvailability ? __('stock.local_delivery') : __('ui.product.unavailable')) : $deliveryEstimate['label'] }}</dd>
            </div>
        @endif
    </dl>
@endif
