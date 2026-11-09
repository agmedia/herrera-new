@extends('front.desktop.layouts.store')

@section('title', __($withdrawal['request_type'] === 'claim' ? 'return_request.claim.review_heading' : 'return_request.review.heading'))
@section('body_class', 'commerce-body returns-commerce-body')
@section('main_class', 'commerce-main')

@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/commerce-pages.css') }}?v={{ filemtime(public_path('front-theme/styles/commerce-pages.css')) }}">
@endpush

@section('content')
    <section class="commerce-hero">
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-cyan-700">{{ __('return_request.review.eyebrow') }}</p>
        <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900">{{ __($withdrawal['request_type'] === 'claim' ? 'return_request.claim.review_heading' : 'return_request.review.heading') }}</h1>
        <p class="mt-2 text-slate-600">{{ __('return_request.review.subheading') }}</p>
    </section>

    <div class="mx-auto max-w-4xl">
        @if ($errors->any())
            <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-900" role="alert">
                @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
            </div>
        @endif
        <p class="mb-4 text-sm font-semibold text-slate-700">{{ ($withdrawal['request_type'] ?? 'return') === 'claim' ? 'Reklamacija – zahtjev za odobrenje' : 'Povrat ispravne robe' }}</p>
        <div class="rounded-xl border border-cyan-200 bg-cyan-50 p-5">
            <p class="text-xs font-bold uppercase tracking-wide text-cyan-800">{{ __($withdrawal['request_type'] === 'claim' ? 'return_request.claim.declaration_title' : 'return_request.review.declaration_title') }}</p>
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

        <form method="POST" action="{{ route('returns.store', ['returnRequestSlug' => __('return_request.slug')]) }}" class="mt-6">
            @csrf
            <input type="hidden" name="draft_token" value="{{ $draftToken }}">
            @if ($orderItems->isNotEmpty())
                <input type="hidden" name="structured_items" value="1">
                <fieldset class="mb-5 rounded-xl border border-slate-200 bg-white p-5">
                    <legend class="px-1 font-bold text-slate-900">Odaberite artikle i količine</legend>
                    <p class="mb-3 text-sm text-slate-600">Unesite broj komada za ovaj zahtjev. Za ostale artikle ostavite 0. Prethodni povrati provjeravaju se pri slanju.</p>
                    @foreach ($orderItems as $item)
                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 py-3">
                            <label for="return-line-{{ $item->id }}" class="min-w-0 text-sm"><strong>{{ $item->name }}</strong><br><span class="text-slate-500">{{ $item->sku ?: $item->code }} · kupljeno {{ $item->quantity }} kom · preostalo za zahtjev {{ $returnableQuantities[$item->id] ?? 0 }}</span></label>
                            <input id="return-line-{{ $item->id }}" type="number" name="line_quantities[{{ $item->id }}]" value="{{ old('line_quantities.'.$item->id, 0) }}" min="0" max="{{ $returnableQuantities[$item->id] ?? 0 }}" step="1" class="w-24 rounded-lg border border-slate-300 px-3 py-2" aria-label="Količina za {{ $item->name }}">
                        </div>
                    @endforeach
                </fieldset>
            @else
                <p class="mb-5 rounded-lg bg-amber-50 p-4 text-sm text-amber-950">Narudžba nije automatski povezana. Zahtjev ćemo obraditi prema unesenom opisu, nakon provjere izvorne narudžbe.</p>
            @endif
            <div class="flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('returns.create', ['returnRequestSlug' => __('return_request.slug')]) }}" class="rounded-lg border border-slate-300 px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                {{ __('return_request.review.edit') }}
            </a>
                <button type="submit" class="commerce-primary-action px-7 py-3" onclick="this.disabled=true; this.form.submit();">
                    {{ __($withdrawal['request_type'] === 'claim' ? 'return_request.claim.confirm' : 'return_request.review.confirm') }}
                </button>
            </div>
        </form>
    </div>
@endsection
