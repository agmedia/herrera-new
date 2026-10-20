@extends('front.desktop.layouts.store')
@section('title', __('Moja statistika kupnje'))
@section('body_class', 'commerce-body account-commerce-body')
@section('main_class', 'commerce-main')
@push('styles')
<link rel="stylesheet" href="{{ asset('front-theme/styles/commerce-pages.css') }}?v={{ filemtime(public_path('front-theme/styles/commerce-pages.css')) }}">
<style>
    .purchase-statistics-filters { display: flex; flex-wrap: wrap; align-items: end; gap: 16px; padding: 24px; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; }
    .purchase-statistics-filters label { display: flex; flex-direction: column; gap: 8px; min-width: 0; font-size: 13px; font-weight: 600; color: #475569; }
    .purchase-statistics-filters input, .purchase-statistics-filters select { width: 100%; height: 48px; border-radius: 8px; font-size: 14px; font-weight: 400; }
    .purchase-statistics-filters .statistics-search { flex: 1 1 220px; }
    .purchase-statistics-filters .statistics-actions { display: flex; flex-wrap: wrap; gap: 10px; }
    .commerce-body .purchase-statistics-filters .statistics-action { min-height: 48px; padding: 0 20px; border-radius: 8px; font-size: 14px; text-decoration: none; white-space: nowrap; }
    @media (max-width: 640px) {
        .purchase-statistics-filters { padding: 16px; gap: 12px; }
        .purchase-statistics-filters label { flex: 1 1 calc(50% - 12px); }
        .purchase-statistics-filters .statistics-search, .purchase-statistics-filters .statistics-actions { flex: 1 1 100%; }
        .purchase-statistics-filters .statistics-action { flex: 1 1 auto; }
    }
</style>
@endpush
@section('content')
<section class="front-soft-hero mb-8 px-4 py-6 text-center"><h1 class="text-3xl font-extrabold">{{ __('Moja statistika kupnje') }}</h1><p class="mt-2">{{ __('Pregled vaših narudžbi i kupljenih artikala u shopu.') }}</p></section>
<div class="account-layout">
@include('front.desktop.account.partials.nav', ['current' => 'statistics'])
<div class="min-w-0 space-y-6">
    @if($errors->any())<div role="alert" class="border border-rose-200 p-4 text-rose-700">{{ $errors->first() }}</div>@endif
    <form method="GET" class="purchase-statistics-filters">
        <label>{{ __('Od datuma') }}<input type="date" name="dateFrom" value="{{ $filters['dateFrom'] }}" class="block rounded border-slate-300"></label>
        <label>{{ __('Do datuma') }}<input type="date" name="dateTo" value="{{ $filters['dateTo'] }}" class="block rounded border-slate-300"></label>
        <label>{{ __('Valuta') }}<select name="currency" class="block rounded border-slate-300">@foreach($currencies as $code)<option @selected($filters['currency'] === $code)>{{ $code }}</option>@endforeach</select></label>
        <label class="statistics-search">{{ __('Artikl ili šifra') }}<input type="search" name="product_search" maxlength="191" value="{{ $filters['product_search'] }}" class="block rounded border-slate-300"></label>
        <div class="statistics-actions">
        <button type="submit" class="commerce-primary-action statistics-action">{{ __('Prikaži statistiku') }}</button>
        <a href="{{ route('account.statistics', ['dateFrom' => '', 'dateTo' => '', 'currency' => $filters['currency'], 'product_search' => $filters['product_search']]) }}" class="commerce-secondary-action statistics-action">{{ __('Sve vrijeme') }}</a>
        </div>
    </form>
    @php($money = fn ($value) => \App\Support\Currency::format($value, $filters['currency']))
    <dl class="grid gap-4 sm:grid-cols-3">
        <div class="border bg-white p-5"><dt>{{ __('Ukupna vrijednost narudžbi') }}</dt><dd class="mt-2 text-2xl font-bold">{{ $money($summary['order_value']) }}</dd></div>
        <div class="border bg-white p-5"><dt>{{ __('Broj narudžbi') }}</dt><dd class="mt-2 text-2xl font-bold">{{ $summary['orders'] }}</dd></div>
        <div class="border bg-white p-5"><dt>{{ __('Prosječna narudžba') }}</dt><dd class="mt-2 text-2xl font-bold">{{ $money($summary['average_order']) }}</dd></div>
    </dl>
    <p class="text-sm text-slate-600">{{ __('Otkazane narudžbe su isključene. Ukupni iznosi uključuju PDV i dostavu. Valute se prikazuju odvojeno. Pretraga artikala filtrira samo popis artikala; ukupni iznosi odnose se na sve vaše narudžbe u odabranom razdoblju.') }}</p>
    <section class="border bg-white p-5"><h2 class="text-lg font-bold">{{ __('Kupnja po mjesecima') }}</h2>
        @forelse($trend as $month)<div class="flex justify-between gap-4 border-b py-3"><span>{{ \Carbon\CarbonImmutable::parse($month->month.'-01')->format('m.Y.') }}</span><span>{{ $month->orders }} {{ __('narudžbi') }} · <strong>{{ $money($month->order_value) }}</strong></span></div>@empty<p class="py-4">{{ __('Nema narudžbi u odabranom razdoblju.') }}</p>@endforelse
        <p class="mt-3 text-xs text-slate-500">{{ __('Posljednjih 12 mjeseci s narudžbama u odabranom razdoblju.') }}</p>
    </section>
    <section class="overflow-hidden border bg-white"><h2 class="p-5 text-lg font-bold">{{ __('Kupljeni artikli') }}</h2><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-100"><tr><th class="p-3">{{ __('Artikl') }}</th><th class="p-3">{{ __('Šifra') }}</th><th class="p-3">{{ __('Komadi') }}</th><th class="p-3">{{ __('Narudžbe') }}</th><th class="p-3">{{ __('Vrijednost bez PDV-a') }}</th></tr></thead><tbody>
    @forelse($products as $product)<tr class="border-t"><td class="p-3">{{ $product->name }}</td><td class="p-3">{{ $product->sku ?: $product->code ?: '—' }}</td><td class="p-3">{{ $product->quantity }}</td><td class="p-3">{{ $product->order_count }}</td><td class="p-3">{{ $money($product->net_value) }}</td></tr>@empty<tr><td colspan="5" class="p-5">{{ __('Nema artikala za odabrane filtre.') }}</td></tr>@endforelse
    </tbody></table></div><p class="p-5 text-xs text-slate-500">{{ __('Vrijednost artikala je prije popusta na cijelu košaricu.') }}</p><div class="p-5">{{ $products->links() }}</div></section>
</div></div>
@endsection
