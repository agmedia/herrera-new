@extends('front.desktop.layouts.store')

@section('title', __('ui.account.dashboard.page_title'))
@section('body_class', 'commerce-body account-commerce-body')
@section('main_class', 'commerce-main')

@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/commerce-pages.css') }}?v={{ filemtime(public_path('front-theme/styles/commerce-pages.css')) }}">
@endpush

@section('content')
    <section class="front-soft-hero mb-8 px-4 py-6 text-center sm:px-6">
        <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">{{ __('ui.account.dashboard.title') }}</h1>
        <p class="mt-2 text-slate-600">
            {{ $loyaltyEnabled ? __('ui.account.dashboard.subtitle') : __('ui.account.dashboard.subtitle_without_loyalty') }}
        </p>
    </section>

    <div class="account-layout">
        @include('front.desktop.account.partials.nav', ['current' => 'dashboard'])

        <div class="min-w-0 space-y-8">
            @if ($b2bAccount)
                @php
                    $b2bStatus = \App\Models\User\B2BAccount::statusOptions()[$b2bAccount->status] ?? $b2bAccount->status;
                    $b2bApproved = $canViewPrices && $b2bAccount->contractIsActive();
                @endphp
                <section class="border {{ $b2bApproved ? 'border-cyan-200 bg-cyan-50' : 'border-amber-200 bg-amber-50' }} p-5" data-account-status="{{ $b2bApproved ? 'approved' : 'pending' }}">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] {{ $b2bApproved ? 'text-cyan-800' : 'text-amber-800' }}">{{ __('B2B račun') }} · {{ __($b2bStatus) }}</p>
                            <h2 class="mt-2 text-xl font-bold text-slate-900">{{ $b2bAccount->company_name }}</h2>
                            @if ($b2bApproved)
                                <p class="mt-1 text-sm text-slate-700">
                                    {{ __('Cjenovna grupa') }}: <strong>{{ $b2bAccount->customerGroup?->name ?? '—' }}</strong>
                                    @if ($b2bAccount->contract_number) · {{ __('Ugovor') }}: <strong>{{ $b2bAccount->contract_number }}</strong> @endif
                                </p>
                            @else
                                <p class="mt-1 text-sm text-slate-700">{{ $b2bAccount->status_reason ?: __('Zahtjev je zaprimljen i čeka provjeru administratora.') }}</p>
                            @endif
                        </div>
                        @if ($b2bApproved)
                            <a href="{{ route('account.b2b.quick-order') }}" class="commerce-primary-action shrink-0 px-4 py-2.5 text-sm font-semibold">{{ __('Brza narudžba') }}</a>
                        @endif
                    </div>
                </section>
            @endif

            <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                <article class="border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.dashboard.cards.user') }}</p>
                    <h2 class="mt-2 text-xl font-bold text-slate-900">{{ $user->name }}</h2>
                    <p class="mt-1 text-sm text-slate-600">{{ $user->email }}</p>
                    <a href="{{ route('account.profile') }}" class="store-text-link mt-3 inline-flex border-b border-current text-sm font-semibold">{{ __('ui.account.nav.edit_account') }}</a>
                </article>

                <article class="border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.dashboard.cards.orders') }}</p>
                    <h2 class="mt-2 text-xl font-bold text-slate-900">{{ $orderCount }}</h2>
                    <a href="{{ route('account.orders') }}" class="store-text-link mt-3 inline-flex border-b border-current text-sm font-semibold">{{ __('ui.account.dashboard.cards.view_orders') }}</a>
                </article>

                <article class="border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Vrijednost narudžbi') }}</p>
                    @forelse ($orderTotals as $orderTotal)
                        <h2 class="mt-2 text-xl font-bold text-slate-900">{{ number_format((float) $orderTotal->total, 2, ',', '.') }} {{ \App\Support\Currency::symbol($orderTotal->currency_code) }}</h2>
                        <p class="mt-1 text-sm text-slate-600">{{ __('Prosječna narudžba') }}: {{ number_format((float) $orderTotal->average, 2, ',', '.') }} {{ \App\Support\Currency::symbol($orderTotal->currency_code) }}</p>
                    @empty
                        <h2 class="mt-2 text-xl font-bold text-slate-900">0,00 €</h2>
                    @endforelse
                    <p class="mt-2 text-xs text-slate-500">{{ __('Ukupno s PDV-om. Otkazane narudžbe nisu uključene.') }}</p>
                </article>

                @if ($loyaltyEnabled)
                    <article id="loyalty" class="border border-slate-200 bg-white p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.dashboard.cards.loyalty') }}</p>
                        <h2 class="mt-2 text-xl font-bold text-slate-900">{{ $loyaltyBalance }} {{ __('ui.account.dashboard.cards.points') }}</h2>
                        <p class="mt-1 text-sm text-slate-600">{{ __('ui.account.dashboard.cards.loyalty_enabled') }}</p>
                        <a href="{{ route('account.loyalty') }}" class="store-text-link mt-3 inline-flex border-b border-current text-sm font-semibold">{{ __('ui.account.loyalty.open') }}</a>
                    </article>
                @endif
            </div>

            @if ($b2bAccount && $b2bApproved)
                <section class="border border-slate-200 bg-white p-5" data-account-order-shortcuts>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="text-xl font-bold text-slate-900">{{ __('Naručite brže') }}</h2>
                            <p class="mt-1 text-sm text-slate-600">{{ __('Nastavite pripremljenu narudžbu ili odaberite artikle koje već poznajete.') }}</p>
                        </div>
                        <a href="{{ route('account.b2b.quick-order') }}" class="commerce-primary-action px-4 py-2.5 text-sm font-semibold">
                            {{ $draftItemCount ? __('Nastavi narudžbu').' ('.$draftItemCount.' '.($draftItemCount === 1 ? __('stavka') : __('stavki')).')' : __('Nova brza narudžba') }}
                        </a>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <a href="{{ route('account.b2b.frequent-products') }}" class="commerce-secondary-action px-4 py-2 text-sm font-semibold">{{ __('Često naručivani artikli') }}</a>
                        <a href="{{ route('account.b2b.favorite-products') }}" class="commerce-secondary-action px-4 py-2 text-sm font-semibold">{{ __('Favoriti') }}</a>
                        @if ($orders->isNotEmpty())
                            <form method="POST" action="{{ route('account.orders.reorder', ['orderNumber' => $orders->first()->order_number]) }}">
                                @csrf
                                <button type="submit" class="commerce-secondary-action px-4 py-2 text-sm font-semibold">{{ __('Ponovi zadnju narudžbu') }}</button>
                            </form>
                        @endif
                    </div>
                </section>
            @endif

            <section>
                <h2 class="text-2xl font-bold text-slate-900">{{ __('ui.account.dashboard.recent_orders.title') }}</h2>
                <div class="mt-4 overflow-hidden border border-slate-200 bg-white">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[560px] text-sm sm:min-w-[620px]">
                            <thead class="bg-slate-100/70 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">{{ __('ui.account.orders.table.order') }}</th>
                                <th class="px-4 py-3">{{ __('ui.account.orders.table.placed') }}</th>
                                <th class="px-4 py-3">{{ __('ui.account.orders.table.status') }}</th>
                                <th class="px-4 py-3">{{ __('ui.account.orders.table.total') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($orders as $order)
                                <tr class="border-t border-slate-200">
                                    <td class="px-4 py-3"><a href="{{ route('account.orders.show', ['orderNumber' => $order->order_number]) }}" class="store-text-link break-all font-semibold underline-offset-2 hover:underline">{{ $order->order_number }}</a></td>
                                    <td class="px-4 py-3">{{ optional($order->placed_at ?? $order->created_at)->format('Y-m-d H:i') }}</td>
                                    <td class="px-4 py-3">{{ $order->status?->name ?? __('ui.account.orders.status_new') }}</td>
                                    <td class="px-4 py-3 font-semibold">{{ \App\Support\Currency::format((float) $order->grand_total, $order->currency_code) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">{{ __('ui.account.orders.empty') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            @if ($loyaltyEnabled)
                <section>
                    <h2 class="text-2xl font-bold text-slate-900">{{ __('ui.account.dashboard.recent_loyalty.title') }}</h2>
                    <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @forelse ($loyaltyRecent as $entry)
                            @php
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
                            <article class="border border-slate-200 bg-white p-4">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $typeLabel }}</p>
                                <p class="mt-2 text-xl font-bold {{ $entry->points >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ $entry->points >= 0 ? '+' : '' }}{{ $entry->points }} {{ __('ui.account.dashboard.cards.points') }}</p>
                                <p class="mt-1 text-sm text-slate-600">{{ optional($entry->created_at)->format('Y-m-d H:i') }}</p>
                                @if ($noteLabel)
                                    <p class="mt-1 text-xs text-slate-500">{{ $noteLabel }}</p>
                                @endif
                            </article>
                        @empty
                            <p class="text-sm text-slate-500">{{ __('ui.account.dashboard.recent_loyalty.empty') }}</p>
                        @endforelse
                    </div>
                </section>
            @endif
        </div>
    </div>
@endsection
