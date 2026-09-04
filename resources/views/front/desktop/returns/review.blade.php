@extends('front.desktop.layouts.store')

@section('title', __('return_request.review.heading'))
@section('body_class', 'commerce-body returns-commerce-body')
@section('main_class', 'commerce-main')

@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/commerce-pages.css') }}?v={{ filemtime(public_path('front-theme/styles/commerce-pages.css')) }}">
@endpush

@section('content')
    <section class="commerce-hero">
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-cyan-700">{{ __('return_request.review.eyebrow') }}</p>
        <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900">{{ __('return_request.review.heading') }}</h1>
        <p class="mt-2 text-slate-600">{{ __('return_request.review.subheading') }}</p>
    </section>

    <div class="mx-auto max-w-4xl">
        <div class="rounded-xl border border-cyan-200 bg-cyan-50 p-5">
            <p class="text-xs font-bold uppercase tracking-wide text-cyan-800">{{ __('return_request.review.declaration_title') }}</p>
            <p class="mt-2 text-base font-semibold leading-7 text-slate-900">{{ $declaration }}</p>
        </div>

        <div class="mt-5 overflow-hidden rounded-xl border border-slate-200 bg-white">
            <dl class="divide-y divide-slate-100 text-sm">
                @foreach ([
                    __('return_request.form.full_name') => $withdrawal['full_name'],
                    __('return_request.form.email') => $withdrawal['email'],
                    __('return_request.form.phone') => $withdrawal['phone'],
                    __('return_request.mail.address') => $withdrawal['address_line'].', '.$withdrawal['postal_code'].' '.$withdrawal['city'].', '.$withdrawal['country_code'],
                    __('return_request.form.order_number') => $withdrawal['order_number'],
                    __('return_request.form.contract_date') => $withdrawal['contract_date'],
                    __('return_request.form.received_date') => $withdrawal['received_date'],
                ] as $label => $value)
                    <div class="grid gap-1 px-5 py-3 sm:grid-cols-[260px_1fr] sm:gap-4">
                        <dt class="font-semibold text-slate-500">{{ $label }}</dt>
                        <dd class="text-slate-900">{{ $value !== '' ? $value : __('return_request.review.not_provided') }}</dd>
                    </div>
                @endforeach
                <div class="px-5 py-4">
                    <dt class="font-semibold text-slate-500">{{ __('return_request.form.items') }}</dt>
                    <dd class="mt-2 whitespace-pre-line leading-6 text-slate-900">{{ $withdrawal['items'] }}</dd>
                </div>
                <div class="px-5 py-4">
                    <dt class="font-semibold text-slate-500">{{ __('return_request.form.note') }}</dt>
                    <dd class="mt-2 whitespace-pre-line leading-6 text-slate-900">{{ $withdrawal['note'] !== '' ? $withdrawal['note'] : __('return_request.review.not_provided') }}</dd>
                </div>
            </dl>
        </div>

        <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm leading-6 text-amber-950">
            {{ __('return_request.review.confirmation_notice') }}
        </div>

        <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('returns.create', ['returnRequestSlug' => __('return_request.slug')]) }}" class="rounded-lg border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                {{ __('return_request.review.edit') }}
            </a>
            <form method="POST" action="{{ route('returns.store', ['returnRequestSlug' => __('return_request.slug')]) }}">
                @csrf
                <input type="hidden" name="draft_token" value="{{ $draftToken }}">
                <button type="submit" class="commerce-primary-action px-7 py-3" onclick="this.disabled=true; this.form.submit();">
                    {{ __('return_request.review.confirm') }}
                </button>
            </form>
        </div>
    </div>
@endsection
