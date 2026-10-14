@extends('front.desktop.layouts.store')
@section('title', __('customer_offers.title'))
@section('body_class', 'commerce-body account-commerce-body')
@section('main_class', 'commerce-main')
@push('styles')<link rel="stylesheet" href="{{ asset('front-theme/styles/commerce-pages.css') }}?v={{ filemtime(public_path('front-theme/styles/commerce-pages.css')) }}">@endpush
@section('content')
<section class="front-soft-hero mb-8 px-4 py-6 text-center"><h1 class="text-3xl font-extrabold">{{ __('customer_offers.title') }}</h1><p class="mt-2 text-slate-600">{{ __('customer_offers.buyer_subtitle') }}</p></section>
<div class="account-layout">@include('front.desktop.account.partials.nav', ['current' => 'offers'])<div class="min-w-0 space-y-4">
@forelse($offers as $offer)<article class="border border-slate-200 bg-white p-5"><p class="text-sm text-cyan-700">{{ $offer->number }}</p><h2 class="mt-1 text-xl font-bold">{{ $offer->title }}</h2><p class="my-3 text-sm text-slate-600">{{ __('customer_offers.valid_until') }} {{ $offer->valid_until->format('d.m.Y.') }} · {{ __('customer_offers.net_total') }} {{ number_format((float) $offer->net_total, 2, ',', '.') }} €</p><a class="commerce-secondary-action px-4 text-sm" target="_blank" href="{{ route('account.customer-offers.pdf', $offer) }}">{{ __('customer_offers.open_pdf') }}</a></article>
@empty<p class="border border-slate-200 bg-white p-8 text-center text-slate-500">{{ __('customer_offers.empty') }}</p>@endforelse
{{ $offers->links() }}</div></div>
@endsection
