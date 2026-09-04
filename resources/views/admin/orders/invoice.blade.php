<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('Invoice') }} {{ $order->order_number }} | {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
    <style>
        @media print {
            .print-hide { display: none !important; }
            body { background: #fff !important; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-900 antialiased">
    <main class="mx-auto max-w-5xl p-4 md:p-8">
        <div class="print-hide mb-4 flex items-center justify-between">
            <a href="{{ route('admin.orders.show', ['order' => $order->id]) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('Back to Order') }}</a>
            <button type="button" onclick="window.print()" class="rounded-lg bg-cyan-700 px-3 py-2 text-sm font-semibold text-white hover:bg-cyan-800">{{ __('Print') }}</button>
        </div>

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Invoice') }}</p>
                    <h1 class="mt-2 text-2xl font-semibold tracking-tight">#{{ $order->order_number }}</h1>
                    <p class="mt-1 text-sm text-slate-600">{{ __('Placed:') }} {{ optional($order->placed_at ?: $order->created_at)->format('Y-m-d H:i') }}</p>
                </div>
                <div class="text-right">
                    <p class="text-sm font-semibold">{{ config('app.name') }}</p>
                    <p class="text-xs text-slate-500">{{ __('Admin generated invoice preview') }}</p>
                </div>
            </div>

            <div class="mt-6 grid gap-4 md:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Bill To') }}</p>
                    <div class="mt-2 text-sm text-slate-700">
                        <p>{{ trim(($order->billing_first_name ?? '').' '.($order->billing_last_name ?? '')) ?: $order->customer_name }}</p>
                        @if ($order->billing_company)<p>{{ $order->billing_company }}</p>@endif
                        <p>{{ $order->billing_address_line_1 ?: '-' }}</p>
                        @if ($order->billing_address_line_2)<p>{{ $order->billing_address_line_2 }}</p>@endif
                        <p>{{ trim(($order->billing_postal_code ?? '').' '.($order->billing_city ?? '')) ?: '-' }}</p>
                        <p>{{ $order->billing_country_code ?: '-' }}</p>
                        <p>{{ $order->customer_email }}</p>
                    </div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Ship To') }}</p>
                    <div class="mt-2 text-sm text-slate-700">
                        <p>{{ trim(($order->shipping_first_name ?? '').' '.($order->shipping_last_name ?? '')) ?: $order->customer_name }}</p>
                        @if ($order->shipping_company)<p>{{ $order->shipping_company }}</p>@endif
                        <p>{{ $order->shipping_address_line_1 ?: '-' }}</p>
                        @if ($order->shipping_address_line_2)<p>{{ $order->shipping_address_line_2 }}</p>@endif
                        <p>{{ trim(($order->shipping_postal_code ?? '').' '.($order->shipping_city ?? '')) ?: '-' }}</p>
                        <p>{{ $order->shipping_country_code ?: '-' }}</p>
                    </div>
                </div>
            </div>

            <div class="mt-6 overflow-x-auto">
                <table class="min-w-full border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-slate-600">
                            <th class="px-2 py-2 text-left font-semibold">{{ __('Item') }}</th>
                            <th class="px-2 py-2 text-center font-semibold">{{ __('Qty') }}</th>
                            <th class="px-2 py-2 text-right font-semibold">{{ __('Unit') }}</th>
                            <th class="px-2 py-2 text-right font-semibold">{{ __('Line') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($order->items as $item)
                            <tr class="border-b border-slate-100">
                                <td class="px-2 py-2">
                                    <div class="font-medium">{{ $item->name }}</div>
                                    <div class="text-xs text-slate-500">{{ $item->sku ?: $item->code ?: '-' }}</div>
                                </td>
                                <td class="px-2 py-2 text-center">{{ $item->quantity }}</td>
                                <td class="px-2 py-2 text-right">{{ \App\Support\Currency::format((float) $item->unit_price, $order->currency_code) }}</td>
                                <td class="px-2 py-2 text-right font-semibold">{{ \App\Support\Currency::format((float) $item->line_total, $order->currency_code) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @php
                $loyaltyAvailable = app(\App\Services\Loyalty\LoyaltyService::class)->availableForUser($order->user_id);
                $invoiceTotals = $loyaltyAvailable
                    ? $order->totals
                    : $order->totals->reject(fn ($total) => $total->code === 'loyalty_redemption');
            @endphp
            <div class="mt-6 ml-auto w-full max-w-sm space-y-2">
                @forelse ($invoiceTotals as $total)
                    @php
                        $totalLabelMap = [
                            'subtotal' => __('ui.account.order_show.totals.labels.subtotal'),
                            'shipping' => __('ui.account.order_show.totals.labels.shipping'),
                            'payment_fee' => __('ui.account.order_show.totals.labels.payment_fee'),
                            'tax' => __('ui.account.order_show.totals.labels.tax'),
                            'loyalty_redemption' => __('Loyalty Redemption'),
                            'grand_total' => __('ui.account.order_show.totals.labels.grand_total'),
                        ];
                        $totalLabelRaw = trim((string) ($total->title ?? ''));
                        $isCustomLoyaltyLabel = (string) ($total->code ?? '') === 'loyalty_redemption'
                            && strcasecmp($totalLabelRaw, 'Loyalty Redemption') !== 0;
                        $totalLabel = $isCustomLoyaltyLabel
                            ? $totalLabelRaw
                            : ($totalLabelMap[(string) ($total->code ?? '')] ?? $totalLabelRaw);
                    @endphp
                    <div class="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm">
                        <span class="text-slate-700">{{ $totalLabel }}</span>
                        <span class="font-semibold text-slate-900">{{ \App\Support\Currency::format((float) $total->value, $order->currency_code) }}</span>
                    </div>
                @empty
                    <div class="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm">
                        <span class="text-slate-700">{{ __('Total') }}</span>
                        <span class="font-semibold text-slate-900">{{ \App\Support\Currency::format((float) $order->grand_total, $order->currency_code) }}</span>
                    </div>
                @endforelse
            </div>
        </section>
    </main>
</body>
</html>
