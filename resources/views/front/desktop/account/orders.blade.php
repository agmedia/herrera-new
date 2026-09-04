@extends('front.desktop.layouts.store')

@section('title', __('ui.account.orders.page_title'))
@section('body_class', 'commerce-body account-commerce-body')
@section('main_class', 'commerce-main')

@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/commerce-pages.css') }}?v={{ filemtime(public_path('front-theme/styles/commerce-pages.css')) }}">
@endpush

@section('content')
    <section class="front-soft-hero mb-8 px-4 py-6 text-center sm:px-6">
        <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">{{ __('ui.account.orders.title') }}</h1>
        <p class="mt-2 text-slate-600">{{ __('ui.account.orders.subtitle') }}</p>
    </section>

    <div class="account-layout">
        @include('front.desktop.account.partials.nav', ['current' => 'orders'])

        <div class="min-w-0">
            <div class="overflow-hidden border border-slate-200 bg-white">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[620px] text-sm">
                        <thead class="bg-slate-100/70 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">{{ __('ui.account.orders.table.order') }}</th>
                            <th class="px-4 py-3">{{ __('ui.account.orders.table.placed') }}</th>
                            <th class="px-4 py-3">{{ __('ui.account.orders.table.status') }}</th>
                            <th class="px-4 py-3">{{ __('ui.account.orders.table.total') }}</th>
                            <th class="px-4 py-3">{{ __('ui.account.orders.table.actions') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($orders as $order)
                            <tr class="border-t border-slate-200">
                                <td class="break-all px-4 py-3 font-semibold text-slate-900">{{ $order->order_number }}</td>
                                <td class="px-4 py-3">{{ optional($order->placed_at ?? $order->created_at)->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3">{{ $order->status?->name ?? __('ui.account.orders.status_new') }}</td>
                                <td class="px-4 py-3 font-semibold">{{ \App\Support\Currency::format((float) $order->grand_total, $order->currency_code) }}</td>
                                <td class="px-4 py-3">
                                    <div class="inline-flex items-center gap-2">
                                        <a href="{{ route('account.orders.show', ['orderNumber' => $order->order_number]) }}" class="commerce-secondary-action px-3 text-xs uppercase tracking-wide">
                                            {{ __('ui.account.orders.table.details') }}
                                        </a>
                                        @if ($b2bAccount?->contractIsActive())
                                            <form method="POST" action="{{ route('account.orders.reorder', ['orderNumber' => $order->order_number]) }}">
                                                @csrf
                                                <button type="submit" class="commerce-secondary-action px-3 text-xs uppercase tracking-wide">{{ __('Ponovi') }}</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">{{ __('ui.account.orders.empty') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-6">{{ $orders->links() }}</div>
        </div>
    </div>
@endsection
