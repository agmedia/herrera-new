@if ($canViewPrices)
    <dl class="mt-3 space-y-1 text-[10px] leading-tight sm:text-[11px]" data-product-availability>
        <div class="flex items-center justify-between gap-2">
            <dt class="text-slate-500">{{ __('Dostupnost 48 sati:') }}</dt>
            <dd class="shrink-0 font-semibold {{ $localStockQuantity > 0 ? 'text-emerald-700' : 'text-slate-500' }}" data-product-local-stock>{{ number_format($localStockQuantity, 0, ',', '.') }}</dd>
        </div>
        <div class="flex items-center justify-between gap-2">
            <dt class="text-slate-500">{{ __('Dostupnost 14 dana:') }}</dt>
            <dd class="shrink-0 font-semibold {{ $supplierStockQuantity > 0 ? 'text-emerald-700' : 'text-slate-500' }}" data-product-supplier-stock>{{ number_format($supplierStockQuantity, 0, ',', '.') }}</dd>
        </div>
    </dl>
@endif
