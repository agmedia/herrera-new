@extends('front.desktop.layouts.store')

@section('title', $title)
@section('body_class', 'commerce-body account-commerce-body')
@section('main_class', 'commerce-main')

@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/commerce-pages.css') }}?v={{ filemtime(public_path('front-theme/styles/commerce-pages.css')) }}">
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
            <div class="overflow-hidden border border-slate-200 bg-white">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-sm">
                        <thead class="bg-slate-100/70 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">{{ __('Artikl') }}</th>
                                <th class="px-4 py-3">{{ __('Šifra') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Vaša cijena') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Akcija') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($products as $row)
                                <tr class="border-t border-slate-200">
                                    <td class="px-4 py-3 font-semibold text-slate-900">{{ $row['name'] }}</td>
                                    <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $row['identifier'] }}</td>
                                    <td class="px-4 py-3 text-right font-semibold text-slate-900">
                                        {{ \App\Support\Currency::format((float) $row['price']['current_gross'], 'EUR') }}
                                        @if (! empty($row['price']['is_b2b_price']))
                                            <span class="ml-1 rounded-full bg-cyan-50 px-2 py-0.5 text-[10px] uppercase text-cyan-800">B2B</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <a href="{{ route('account.b2b.quick-order', ['code' => $row['identifier']]) }}" class="inline-flex rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                            {{ __('Dodaj u brzu kupnju') }}
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-4 py-10 text-center text-slate-500">{{ __('Još nema artikala u ovoj grupi.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
@endsection
