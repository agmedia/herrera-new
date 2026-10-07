@if (auth()->user()?->isA('superadmin') || (auth()->user()?->can('users.list.view') && auth()->user()?->can('sales.orders.view')))
    @php
        $purchaseStatistics = app(\App\Services\Analytics\CustomerPurchaseStatistics::class)->forUser($user);
        $purchaseAmount = fn ($value, $code) => number_format((float) $value, 2, ',', '.').' '.\App\Support\Currency::symbol($code);
    @endphp
    <section class="admin-panel admin-form-panel p-5 sm:p-6" aria-labelledby="customer-purchase-statistics-title">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="admin-section-title">{{ __('Kupac') }}</p>
                <h2 id="customer-purchase-statistics-title" class="mt-1 text-lg font-semibold text-slate-900">{{ __('Statistika kupnje') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('Sve narudžbe ovog računa, bez otkazanih. Iznosi su odvojeni po valuti.') }}</p>
            </div>
            <a href="{{ route('admin.users.statistics', ['user_id' => $user->id, 'dateFrom' => '', 'dateTo' => '']) }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-cyan-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-cyan-800">
                <x-fa-icon name="chart-line" class="h-4 w-4" />
                {{ __('Detaljna statistika kupca') }}
            </a>
        </div>
        @forelse ($purchaseStatistics['currencies'] as $purchaseCurrency => $purchaseSummary)
            <div class="mt-5" data-customer-purchase-currency="{{ $purchaseCurrency }}">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <a href="{{ route('admin.users.statistics', ['user_id' => $user->id, 'currency' => $purchaseCurrency, 'dateFrom' => '', 'dateTo' => '']) }}" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700 hover:bg-cyan-50 hover:text-cyan-800">{{ $purchaseCurrency }} · {{ __('Detalji') }}</a>
                    <span class="text-xs text-slate-500">{{ __('Posljednja narudžba') }}: {{ $purchaseSummary['last_order_at']?->format('d.m.Y.') ?? '—' }}</span>
                </div>
                <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Narudžbe') }}</dt><dd class="mt-2 text-xl font-bold text-slate-900">{{ number_format($purchaseSummary['orders'], 0, ',', '.') }}</dd></div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Vrijednost s PDV-om') }}</dt><dd class="mt-2 text-xl font-bold text-slate-900">{{ $purchaseAmount($purchaseSummary['order_value'], $purchaseCurrency) }}</dd></div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Prosječna narudžba') }}</dt><dd class="mt-2 text-xl font-bold text-slate-900">{{ $purchaseAmount($purchaseSummary['average_order'], $purchaseCurrency) }}</dd></div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Naručeni komadi') }}</dt><dd class="mt-2 text-xl font-bold text-slate-900">{{ number_format($purchaseSummary['items_sold'], 0, ',', '.') }}</dd></div>
                </dl>
                <p class="mt-3 text-xs text-slate-500">{{ __('Plaćene narudžbe') }}: {{ $purchaseAmount($purchaseSummary['paid_value'], $purchaseCurrency) }} · {{ __('Otkazane narudžbe') }}: {{ $purchaseSummary['cancelled_orders'] }}</p>
            </div>
        @empty
            <div class="mt-5 rounded-xl border border-dashed border-slate-300 p-5 text-sm text-slate-500">{{ __('Kupac još nema narudžbi. Statistika će se prikazati nakon prve narudžbe.') }}</div>
        @endforelse
    </section>
@endif
