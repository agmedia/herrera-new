@extends('front.desktop.layouts.store')

@section('title', __('ui.account.loyalty.page_title'))
@section('body_class', 'commerce-body account-commerce-body')
@section('main_class', 'commerce-main')

@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/commerce-pages.css') }}?v={{ filemtime(public_path('front-theme/styles/commerce-pages.css')) }}">
@endpush

@section('content')
    <section class="front-soft-hero mb-8 px-4 py-6 text-center sm:px-6">
        <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">{{ __('ui.account.loyalty.title') }}</h1>
        <p class="mt-2 text-slate-600">{{ __('ui.account.loyalty.subtitle') }}</p>
    </section>

    <div class="account-layout">
        @include('front.desktop.account.partials.nav', ['current' => 'loyalty'])

        <div class="min-w-0 space-y-6">
            <div class="grid gap-4 md:grid-cols-3">
                <article class="border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.loyalty.cards.balance') }}</p>
                    <p class="mt-2 text-2xl font-extrabold text-slate-900">{{ $balance }}</p>
                </article>
                <article class="border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.loyalty.cards.earned') }}</p>
                    <p class="mt-2 text-2xl font-extrabold text-emerald-700">+{{ $earned }}</p>
                </article>
                <article class="border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.loyalty.cards.spent') }}</p>
                    <p class="mt-2 text-2xl font-extrabold text-rose-700">-{{ $spent }}</p>
                </article>
            </div>

            <article class="border border-slate-200 bg-white p-5">
                <h2 class="text-lg font-bold text-slate-900">{{ __('ui.account.loyalty.usage.title') }}</h2>
                <p class="mt-2 text-sm text-slate-600">
                    {{ __('ui.account.loyalty.usage.min_order', ['amount' => \App\Support\Currency::format($minOrderTotal)]) }}
                </p>
                <p class="mt-1 text-sm text-slate-600">{{ __('ui.account.loyalty.usage.instant') }}</p>
            </article>

            <section class="overflow-hidden border border-slate-200 bg-white">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-sm">
                        <thead class="bg-slate-100/70 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">{{ __('ui.account.loyalty.table.date') }}</th>
                            <th class="px-4 py-3">{{ __('ui.account.loyalty.table.activity') }}</th>
                            <th class="px-4 py-3">{{ __('ui.account.loyalty.table.points') }}</th>
                            <th class="px-4 py-3">{{ __('ui.account.loyalty.table.available_at') }}</th>
                            <th class="px-4 py-3">{{ __('ui.account.loyalty.table.used_at') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($transactions as $entry)
                            @php
                                $isEarn = (int) $entry->points > 0;
                                $isSpend = (int) $entry->points < 0;
                                $typeKey = 'ui.account.loyalty.types.'.$entry->type;
                                $typeLabel = \Illuminate\Support\Facades\Lang::has($typeKey)
                                    ? __($typeKey)
                                    : ucfirst(str_replace('_', ' ', (string) $entry->type));
                                $noteMap = [
                                    'Auto settlement from order status sync.' => __('ui.account.loyalty.notes.auto_settlement'),
                                    'Order discount consumed through loyalty redemption.' => __('ui.account.loyalty.notes.redemption_consumed'),
                                    'Auto reversal from order status sync.' => __('ui.account.loyalty.notes.auto_reversal'),
                                ];
                                $noteLabel = $entry->note ? ($noteMap[$entry->note] ?? $entry->note) : null;
                            @endphp
                            <tr class="border-t border-slate-200">
                                <td class="px-4 py-3">{{ optional($entry->created_at)->format('Y-m-d H:i') }}</td>
                                <td class="break-words px-4 py-3">
                                    <p class="font-semibold text-slate-900">{{ $typeLabel }}</p>
                                    @if ($entry->order)
                                        <p class="text-xs text-slate-500">{{ __('ui.account.loyalty.table.order') }}: {{ $entry->order->order_number }}</p>
                                    @endif
                                    @if ($noteLabel)
                                        <p class="text-xs text-slate-500">{{ $noteLabel }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3 font-bold {{ $isEarn ? 'text-emerald-700' : ($isSpend ? 'text-rose-700' : 'text-slate-900') }}">
                                    {{ (int) $entry->points > 0 ? '+' : '' }}{{ (int) $entry->points }}
                                </td>
                                <td class="px-4 py-3 text-slate-700">
                                    {{ $isEarn ? optional($entry->created_at)->format('Y-m-d H:i') : '—' }}
                                </td>
                                <td class="px-4 py-3 text-slate-700">
                                    {{ $isSpend ? optional($entry->created_at)->format('Y-m-d H:i') : '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-10 text-center text-slate-500">{{ __('ui.account.loyalty.empty') }}</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <div>{{ $transactions->links() }}</div>
        </div>
    </div>
@endsection
