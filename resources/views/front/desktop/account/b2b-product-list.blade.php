@extends('front.desktop.layouts.store')

@section('title', $title)
@section('body_class', 'commerce-body account-commerce-body')
@section('main_class', 'commerce-main')

@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/commerce-pages.css') }}?v={{ filemtime(public_path('front-theme/styles/commerce-pages.css')) }}">
@endpush

@push('scripts')
<script>
document.querySelector('[data-toggle-ean]').addEventListener('change', function () {
    this.closest('section').querySelectorAll('[data-ean-column]').forEach(cell => { cell.hidden = !this.checked; });
});
</script>
@endpush

@section('content')
    <section class="front-soft-hero mb-8 px-4 py-6 text-center sm:px-6">
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-cyan-700">{{ $b2bAccount->company_name }}</p>
        <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900">{{ $title }}</h1>
        <p class="mt-2 text-slate-600">{{ $subtitle }}</p>
    </section>

    <div class="account-layout">
        @include('front.desktop.account.partials.nav', ['current' => $current])

        <section class="min-w-0">
            <label class="mb-4 inline-flex cursor-pointer items-center gap-2 text-sm font-semibold text-slate-700">
                <input type="checkbox" class="rounded border-slate-300 text-cyan-700" data-toggle-ean>
                {{ __('Prikaži EAN crtične kodove') }}
            </label>
            <div class="overflow-hidden border border-slate-200 bg-white">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-sm">
                        <thead class="bg-slate-100/70 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">{{ __('Artikl') }}</th>
                                @isset($quantities)<th class="px-4 py-3">{{ __('Količina · 12 mj.') }}</th>@endisset
                                <th class="px-4 py-3">{{ __('Šifra') }}</th>
                                <th class="px-4 py-3" hidden data-ean-column>{{ __('EAN kod') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Vaša cijena') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Akcija') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($products as $row)
                                <tr class="border-t border-slate-200">
                                    <td class="px-4 py-3 font-semibold text-slate-900">@php($photo = \App\Support\Media\LegacyCatalogImage::first($row['product'], ['thumb_100x100']))
                                        @if ($photo)<img src="{{ $photo }}" alt="" width="48" height="48" loading="lazy" class="mr-2 inline-block object-contain">@endif
                                        {{ $row['name'] }}</td>
                                    @isset($quantities)<td class="px-4 py-3">{{ rtrim(rtrim(number_format((float) $quantities[$row['product']->id], 4, ',', '.'), '0'), ',') }} {{ \App\Models\Catalog\Product\Product::unitOptions()[$row['product']->unit_of_measure] ?? $row['product']->unit_of_measure }}</td>@endisset
                                    <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $row['identifier'] }}</td>
                                    <td class="px-4 py-3" hidden data-ean-column><x-ean-barcode :value="$row['barcode']" /></td>
                                    <td class="px-4 py-3 text-right font-semibold text-slate-900">
                                        @if ($canViewPrices)
                                        {{ \App\Support\Currency::format((float) ($row['price']['display_current'] ?? $row['price']['current_gross']), 'EUR') }}
                                        @include('front.partials.b2b-tax-note', ['includesTax' => $row['price']['display_includes_tax'] ?? true])
                                        @if (! empty($row['price']['is_b2b_price']))
                                            <span class="ml-1 rounded-full bg-cyan-50 px-2 py-0.5 text-[10px] uppercase text-cyan-800">B2B</span>
                                        @endif
                                        @else
                                            {{ __('ui.b2b.pricing.access_required') }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        @if ($canViewPrices && $row['product']->is_active)
                                        <a href="{{ route('account.b2b.quick-order', ['code' => $row['identifier']]) }}" class="inline-flex rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                            {{ __('Dodaj u brzu kupnju') }}
                                        </a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ isset($quantities) ? 6 : 5 }}" class="px-4 py-10 text-center text-slate-500">{{ $emptyMessage ?? __('Još nema artikala u ovoj grupi.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @isset($purchases){{ $purchases->links() }}@endisset
        </section>
    </div>
@endsection
