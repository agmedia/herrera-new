@php
    $boxPackages = $boxProduct->packages->filter(static fn ($package) => $package->is_active
        && $package->package_type === 'box' && $package->unit_of_measure === $boxProduct->unit_of_measure
        && (float) $package->quantity >= 2 && (float) $package->quantity <= 999
        && floor((float) $package->quantity) === (float) $package->quantity);
    $boxUnit = \App\Models\Catalog\Product\Product::unitOptions()[$boxProduct->unit_of_measure ?: 'pcs'] ?? $boxProduct->unit_of_measure;
@endphp
@if ($boxPackages->isNotEmpty())
    <div class="mt-4 flex flex-wrap items-center gap-2" data-product-box-ordering>
        @foreach ($boxPackages as $boxPackage)
            <button type="submit" name="product_package_id" value="{{ $boxPackage->id }}" data-product-box-quantity="{{ (int) $boxPackage->quantity }}" class="inline-flex min-h-10 items-center rounded-lg border border-cyan-700 bg-white px-3 py-2 text-sm font-semibold text-cyan-800 hover:bg-cyan-50">
                {{ __('Dodaj kutiju (:quantity :unit)', ['quantity' => (int) $boxPackage->quantity, 'unit' => $boxUnit]) }}
            </button>
        @endforeach
        <p class="w-full text-xs text-slate-600">{{ __('Jednim klikom dodajete jednu cijelu kutiju. Prikazana cijena odnosi se na osnovnu jedinicu proizvoda.') }}</p>
    </div>
@endif
