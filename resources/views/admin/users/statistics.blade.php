<x-admin-layout :title="__('Statistika kupaca i artikala')">
    @php
        $currency = $filters['currency'];
        $amount = fn ($value) => number_format((float) $value, 2, ',', '.').' '.\App\Support\Currency::symbol($currency);
        $customerName = $selectedCustomer?->name ?: ($filters['guest_email'] ?: null);
        $trendMax = max(1, (float) $trend->max('order_value'));
        $customerParameters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        $customerParameters['dateFrom'] = $filters['dateFrom'];
        $customerParameters['dateTo'] = $filters['dateTo'];
    @endphp

    <div class="space-y-6">
        <section class="admin-panel admin-search-panel p-5 sm:p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="admin-section-title">{{ __('Admin / Kupci') }}</p>
                    <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">{{ __('Statistika kupaca i artikala') }}</h1>
                    <p class="mt-2 text-sm text-slate-600">{{ __('Pratite vrijednost narudžbi, kupce koji se vraćaju i najtraženije artikle.') }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($selectedCustomer)
                        <a href="{{ route('admin.users.show', $selectedCustomer) }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Profil kupca') }}</a>
                    @endif
                    <a href="{{ route('admin.users') }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Korisnici') }}</a>
                    <a href="{{ route('admin.dashboard') }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Dashboard') }}</a>
                </div>
            </div>

            @if ($errors->any())
                <div role="alert" class="mt-5 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">{{ $errors->first() }}</div>
            @endif

            <form method="GET" action="{{ route('admin.users.statistics') }}" class="mt-6 grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-6">
                @if ($filters['user_id'])<input type="hidden" name="user_id" value="{{ $filters['user_id'] }}">@endif
                @if ($filters['guest_email'])<input type="hidden" name="guest_email" value="{{ $filters['guest_email'] }}">@endif
                <div><label for="statistics-date-from" class="mb-1.5 block text-xs font-semibold text-slate-600">{{ __('Od datuma') }}</label><input id="statistics-date-from" name="dateFrom" type="date" value="{{ $filters['dateFrom'] }}" class="w-full rounded-xl border-slate-300 text-sm"></div>
                <div><label for="statistics-date-to" class="mb-1.5 block text-xs font-semibold text-slate-600">{{ __('Do datuma') }}</label><input id="statistics-date-to" name="dateTo" type="date" value="{{ $filters['dateTo'] }}" class="w-full rounded-xl border-slate-300 text-sm"></div>
                <div><label for="statistics-currency" class="mb-1.5 block text-xs font-semibold text-slate-600">{{ __('Valuta') }}</label><select id="statistics-currency" name="currency" class="w-full rounded-xl border-slate-300 text-sm">@foreach ($currencies as $code)<option value="{{ $code }}" @selected($code === $currency)>{{ $code }}</option>@endforeach</select></div>
                <div class="lg:col-span-2"><label for="statistics-search" class="mb-1.5 block text-xs font-semibold text-slate-600">{{ __('Kupac, tvrtka ili e-mail') }}</label><input id="statistics-search" name="search" type="search" value="{{ $filters['search'] }}" placeholder="{{ __('Pretražite kupce') }}" maxlength="191" class="w-full rounded-xl border-slate-300 text-sm"></div>
                <button type="submit" class="rounded-xl bg-cyan-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-cyan-800">{{ __('Prikaži statistiku') }}</button>
            </form>
            <div class="mt-4 flex flex-wrap items-center gap-2 text-xs">
                <span class="font-semibold text-slate-500">{{ __('Razdoblje') }}:</span>
                @foreach ([__('Ovaj mjesec') => [now()->startOfMonth()->toDateString(), now()->toDateString()], __('Ova godina') => [now()->startOfYear()->toDateString(), now()->toDateString()], __('Sve vrijeme') => ['', '']] as $label => $dates)
                    <a href="{{ route('admin.users.statistics', array_merge($customerParameters, ['dateFrom' => $dates[0], 'dateTo' => $dates[1]])) }}" class="rounded-full border border-slate-200 bg-white px-3 py-1.5 font-semibold text-slate-600 hover:border-cyan-300 hover:text-cyan-800">{{ $label }}</a>
                @endforeach
                @if ($customerName)
                    <span class="rounded-full bg-cyan-50 px-3 py-1.5 font-semibold text-cyan-800">{{ $customerName }}</span>
                    <a href="{{ route('admin.users.statistics', array_diff_key($customerParameters, array_flip(['user_id', 'guest_email']))) }}" class="px-2 py-1.5 font-semibold text-cyan-700 hover:underline">{{ __('Svi kupci') }}</a>
                @endif
            </div>
            <p class="mt-4 text-xs leading-relaxed text-slate-500">{{ __('Otkazane narudžbe su isključene. Iznosi narudžbi uključuju PDV i dostavu; vrijednost artikala je bez PDV-a. Valute se prikazuju zasebno, bez preračunavanja.') }}</p>
        </section>

        <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="admin-panel p-5"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Vrijednost narudžbi') }}</dt><dd class="mt-3 text-2xl font-bold tracking-tight text-slate-900">{{ $amount($summary['order_value']) }}</dd><p class="mt-2 text-xs text-slate-500">{{ __('Plaćeno') }}: {{ $amount($summary['paid_value']) }}</p></div>
            <div class="admin-panel p-5"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Narudžbe') }}</dt><dd class="mt-3 text-2xl font-bold tracking-tight text-slate-900">{{ number_format($summary['orders'], 0, ',', '.') }}</dd><p class="mt-2 text-xs text-slate-500">{{ __('Prosjek') }}: {{ $amount($summary['average_order']) }}</p></div>
            <div class="admin-panel p-5"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Aktivni kupci') }}</dt><dd class="mt-3 text-2xl font-bold tracking-tight text-slate-900">{{ number_format($summary['customers'], 0, ',', '.') }}</dd><p class="mt-2 text-xs text-slate-500">{{ $summary['repeat_customers'] }} {{ __('s više narudžbi u razdoblju') }} ({{ number_format($summary['repeat_rate'], 1, ',', '.') }}%)</p></div>
            <div class="admin-panel p-5"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Naručeni komadi') }}</dt><dd class="mt-3 text-2xl font-bold tracking-tight text-slate-900">{{ number_format($summary['items_sold'], 0, ',', '.') }}</dd><p class="mt-2 text-xs text-slate-500">{{ __('Isključene otkazane narudžbe') }}: {{ $summary['cancelled_orders'] }}</p></div>
        </dl>

        @if ($trend->isNotEmpty())
            <section class="admin-panel p-5 sm:p-6" aria-labelledby="statistics-trend-title">
                <div class="flex flex-wrap items-baseline justify-between gap-2"><h2 id="statistics-trend-title" class="text-lg font-semibold text-slate-900">{{ __('Kupnja po mjesecima') }}</h2><p class="text-xs text-slate-500">{{ __('Posljednjih 12 mjeseci s narudžbama u odabranom razdoblju') }}</p></div>
                <div class="mt-5 space-y-3">
                    @foreach ($trend as $month)
                        <div class="flex flex-wrap items-center gap-3 text-sm">
                            <span class="w-20 shrink-0 font-medium text-slate-600">{{ \Carbon\CarbonImmutable::parse($month->month.'-01')->format('m.Y.') }}</span>
                            <div class="h-3 min-w-20 flex-1 overflow-hidden rounded-full bg-slate-100" aria-hidden="true"><div class="h-full rounded-full bg-cyan-600" style="width: {{ max(1, min(100, 100 * $month->order_value / $trendMax)) }}%"></div></div>
                            <span class="w-36 text-right font-semibold tabular-nums text-slate-900">{{ $amount($month->order_value) }}</span><span class="w-24 text-right text-xs text-slate-500">{{ $month->orders }} {{ __('narudžbi') }}</span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="admin-panel overflow-hidden" aria-labelledby="statistics-customers-title">
            <div class="border-b border-slate-200 p-5 sm:p-6"><h2 id="statistics-customers-title" class="text-lg font-semibold text-slate-900">{{ $customerName ? __('Kupnja odabranog kupca') : __('Kupci po vrijednosti narudžbi') }}</h2><p class="mt-1 text-sm text-slate-500">{{ __('Otvorite statistiku kupca za pregled njegovih najčešće naručenih artikala.') }}</p></div>
            <div class="overflow-x-auto">
                <table class="admin-items-table w-full text-sm">
                    <thead><tr><th class="px-5 py-3 text-left">{{ __('Kupac') }}</th><th class="px-4 py-3 text-right">{{ __('Narudžbe') }}</th><th class="px-4 py-3 text-right">{{ __('Vrijednost') }}</th><th class="px-4 py-3 text-right">{{ __('Prosjek') }}</th><th class="px-4 py-3 text-right">{{ __('Komadi') }}</th><th class="px-4 py-3 text-left">{{ __('Posljednja kupnja') }}</th><th class="px-5 py-3 text-right">{{ __('Detalji') }}</th></tr></thead>
                    <tbody>
                        @forelse ($customers as $customer)
                            <tr><td class="px-5 py-4"><div class="font-semibold text-slate-900">{{ $customer->name }}</div><div class="mt-1 text-xs text-slate-500">{{ $customer->email }}</div>@if ($customer->company)<div class="mt-1 text-xs text-slate-500">{{ $customer->company }}</div>@endif @if (! $customer->user_id)<span class="mt-2 inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500">{{ __('Gost') }}</span>@endif</td><td class="px-4 py-4 text-right tabular-nums">{{ $customer->order_count }}</td><td class="whitespace-nowrap px-4 py-4 text-right font-semibold tabular-nums">{{ $amount($customer->order_value) }}</td><td class="whitespace-nowrap px-4 py-4 text-right tabular-nums">{{ $amount($customer->average_order) }}</td><td class="px-4 py-4 text-right tabular-nums">{{ $customer->items_sold }}</td><td class="whitespace-nowrap px-4 py-4 text-slate-500">{{ \Carbon\CarbonImmutable::parse($customer->last_order_at)->format('d.m.Y.') }}</td><td class="px-5 py-4 text-right">
                                @if ($customer->user_id || $customer->guest_email)
                                    <a href="{{ route('admin.users.statistics', array_merge(array_diff_key($customerParameters, array_flip(['user_id', 'guest_email', 'search'])), $customer->user_id ? ['user_id' => $customer->user_id] : ['guest_email' => $customer->guest_email])) }}" class="font-semibold text-cyan-700 hover:underline">{{ __('Statistika') }}</a>
                                @else
                                    <a href="{{ route('admin.orders.show', $customer->anonymous_order_id) }}" class="font-semibold text-cyan-700 hover:underline">{{ __('Narudžba') }}</a>
                                @endif
                            </td></tr>
                        @empty
                            <tr><td colspan="7" class="px-5 py-12 text-center text-slate-500">{{ __('Nema kupaca s narudžbama za odabrane filtre.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($customers->hasPages())<div class="border-t border-slate-200 p-5">{{ $customers->links() }}</div>@endif
        </section>

        <section class="admin-panel overflow-hidden" aria-labelledby="statistics-products-title">
            <div class="border-b border-slate-200 p-5 sm:p-6"><h2 id="statistics-products-title" class="text-lg font-semibold text-slate-900">{{ $customerName ? __('Najčešće naručeni artikli kupca') : __('Najčešće naručeni artikli') }}</h2><p class="mt-1 text-sm text-slate-500">{{ __('Poredak prema broju komada. Varijante se prate zasebno; podaci uključuju stavke povijesnih narudžbi.') }}</p><p class="mt-2 text-xs text-slate-500">{{ __('Vrijednost stavki je bez PDV-a i prije popusta koji se primjenjuju na cijelu košaricu.') }}</p></div>
            <div class="overflow-x-auto">
                <table class="admin-items-table w-full text-sm">
                    <thead><tr><th class="px-5 py-3 text-left">{{ __('Artikl') }}</th><th class="px-4 py-3 text-left">{{ __('SKU / šifra') }}</th><th class="px-4 py-3 text-right">{{ __('Komadi') }}</th><th class="px-4 py-3 text-right">{{ __('Narudžbe') }}</th><th class="px-5 py-3 text-right">{{ __('Vrijednost bez PDV-a') }}</th></tr></thead>
                    <tbody>
                        @forelse ($products as $product)
                            <tr><td class="px-5 py-4 font-semibold text-slate-900">@if ($product->product_id && (auth()->user()->isA('superadmin') || auth()->user()->can('catalog.products.update')))<a href="{{ route('admin.products.edit', $product->product_id) }}" class="hover:text-cyan-700 hover:underline">{{ $product->name }}</a>@else{{ $product->name }}@endif</td><td class="px-4 py-4 font-mono text-xs text-slate-500">{{ $product->sku ?: $product->code ?: '—' }}</td><td class="px-4 py-4 text-right font-semibold tabular-nums">{{ $product->quantity }}</td><td class="px-4 py-4 text-right tabular-nums">{{ $product->order_count }}</td><td class="whitespace-nowrap px-5 py-4 text-right tabular-nums">{{ $amount($product->net_value) }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-12 text-center text-slate-500">{{ __('Nema naručenih artikala za odabrane filtre.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($products->hasPages())<div class="border-t border-slate-200 p-5">{{ $products->links() }}</div>@endif
        </section>
    </div>
</x-admin-layout>
