@extends('front.desktop.layouts.store')

@section('title', __('ui.checkout.success.page_title'))
@section('main_class', 'w-full px-0 py-8')

@section('content')
    @php
        $boxNow = is_array($order->payload['shipping']['boxnow'] ?? null) ? $order->payload['shipping']['boxnow'] : null;
        $glsPoint = is_array($order->payload['shipping']['gls_dpm'] ?? null) ? $order->payload['shipping']['gls_dpm'] : null;
    @endphp

    @push('styles')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/checkout.css') }}?v={{ filemtime(public_path('front-theme/styles/checkout.css')) }}">
    @endpush

    <section class="checkout-status-card checkout-status-card--success">
        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">{{ __('ui.checkout.success.eyebrow') }}</p>
        <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900">{{ __('ui.checkout.success.title') }}</h1>
        <p class="mt-3 text-slate-600">{{ __('ui.checkout.success.order_number') }}: <span class="font-semibold text-slate-900">{{ $order->order_number }}</span></p>

        <dl class="mt-6 grid gap-3 text-sm md:grid-cols-2">
            <div class="border border-slate-200 bg-slate-100 p-4">
                <dt class="text-slate-500">{{ __('ui.checkout.success.status') }}</dt>
                <dd class="mt-1 font-semibold text-slate-900">{{ $order->status?->name ?? __('ui.checkout.success.status_fallback_new') }}</dd>
            </div>
            <div class="border border-slate-200 bg-slate-100 p-4">
                <dt class="text-slate-500">{{ __('ui.checkout.success.grand_total') }}</dt>
                <dd class="mt-1 font-semibold text-slate-900">{{ $order->currency_code }} {{ number_format((float) $order->grand_total, 2) }}</dd>
            </div>
        </dl>

        @if (!empty($bankTransfer) && !empty($bankTransfer['receiver_iban']))
            <section class="mt-6 border border-slate-200 bg-slate-50 p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-700">{{ __('ui.checkout.success.bank_transfer_title') }}</h2>
                <p class="mt-2 text-sm text-slate-600">{{ __('ui.checkout.success.bank_transfer_note') }}</p>

                <div class="mt-3 grid gap-2 text-sm text-slate-800 md:grid-cols-2">
                    <p><strong>{{ __('ui.checkout.success.bank_recipient') }}:</strong> {{ $bankTransfer['receiver_name'] ?? '-' }}</p>
                    <p><strong>{{ __('ui.checkout.success.bank_iban') }}:</strong> {{ $bankTransfer['receiver_iban'] ?? '-' }}</p>
                    <p><strong>{{ __('ui.checkout.success.bank_model') }}:</strong> {{ $bankTransfer['model'] ?? '-' }}</p>
                    <p><strong>{{ __('ui.checkout.success.bank_reference') }}:</strong> {{ $bankTransfer['reference'] ?? '-' }}</p>
                    <p><strong>{{ __('ui.checkout.success.bank_amount') }}:</strong> {{ $order->currency_code }} {{ number_format((float) ($bankTransfer['amount'] ?? 0), 2) }}</p>
                    <p><strong>{{ __('ui.checkout.success.bank_description') }}:</strong> {{ $bankTransfer['description'] ?? '-' }}</p>
                </div>

                @if (!empty($bankTransfer['qr_image_base64']))
                    <div class="mt-4">
                        <img
                            src="data:{{ $bankTransfer['qr_image_mime'] ?? 'image/png' }};base64,{{ $bankTransfer['qr_image_base64'] }}"
                            alt="{{ __('ui.checkout.success.bank_qr_alt') }}"
                            class="h-auto w-full max-w-[380px] border border-slate-200 bg-white p-2"
                        >
                    </div>
                @endif
            </section>
        @endif

        @if (!empty($boxNow['locker_id']))
            <section class="mt-6 border border-blue-200 bg-blue-50 p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-700">{{ __('ui.checkout.success.boxnow_title') }}</h2>
                <p class="mt-2 text-sm text-slate-700"><strong>{{ __('ui.checkout.success.boxnow_locker') }}:</strong> {{ $boxNow['locker_name'] ?: '-' }} ({{ $boxNow['locker_id'] }})</p>
                <p class="mt-1 text-sm text-slate-700"><strong>{{ __('ui.checkout.success.boxnow_address') }}:</strong> {{ trim(($boxNow['address_line_1'] ?? '').', '.($boxNow['postal_code'] ?? '').' '.($boxNow['city'] ?? ''), ', ') ?: '-' }}</p>
            </section>
        @endif

        @if (!empty($glsPoint['id']))
            <section class="mt-6 border border-cyan-200 bg-cyan-50 p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-700">{{ __('GLS paketomat / ParcelShop') }}</h2>
                <p class="mt-2 text-sm text-slate-700"><strong>{{ __('Lokacija') }}:</strong> {{ $glsPoint['name'] ?: '-' }} ({{ $glsPoint['id'] }})</p>
                <p class="mt-1 text-sm text-slate-700"><strong>{{ __('Adresa') }}:</strong> {{ trim(($glsPoint['address_line_1'] ?? '').', '.($glsPoint['postal_code'] ?? '').' '.($glsPoint['city'] ?? ''), ', ') ?: '-' }}</p>
            </section>
        @endif

        <div class="mt-8 flex flex-wrap gap-2">
            @auth
                <a href="{{ route('account.orders.show', ['orderNumber' => $order->order_number]) }}" class="checkout-primary-button px-5 py-2.5">{{ __('ui.checkout.success.view_in_account') }}</a>
            @endauth
            <a href="{{ route('shop.index') }}" class="inline-flex min-h-12 items-center justify-center rounded-[3px] border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('ui.checkout.success.continue_shopping') }}</a>
        </div>
    </section>

    @php
        $analytics = $storeSettings['analytics'] ?? [];
        $ga4PurchaseEnabled = (bool) ($analytics['enabled'] ?? false)
            && (bool) ($analytics['purchase_event_enabled'] ?? true)
            && trim((string) ($analytics['ga4_measurement_id'] ?? '')) !== '';
        $metaPixelId = '2376960792811713';
        $metaPurchaseEnabled = $metaPixelId !== '';
        $shouldTrackPurchase = $ga4PurchaseEnabled || $metaPurchaseEnabled;
        $eventName = trim((string) ($analytics['purchase_event_name'] ?? 'purchase')) ?: 'purchase';
        $eventItems = $order->items->map(static function ($item): array {
            return [
                'item_id' => (string) ($item->sku ?: $item->code ?: $item->id),
                'item_name' => (string) $item->name,
                'quantity' => (int) $item->quantity,
                'price' => (float) $item->unit_price,
            ];
        })->values()->all();
        $purchasePayload = [
            'transaction_id' => (string) $order->order_number,
            'currency' => (string) ($order->currency_code ?: 'EUR'),
            'value' => (float) $order->grand_total,
            'tax' => (float) $order->tax_total,
            'shipping' => (float) $order->shipping_total,
            'coupon' => (string) ($order->payload['coupon_code'] ?? ''),
            'items' => $eventItems,
        ];
        $purchaseOnceKey = 'purchase:'.(string) $order->order_number;
    @endphp

    @if ($shouldTrackPurchase)
        @push('scripts')
            <script>
                (function () {
                    var trackPurchase = function () {
                        if (!window.ShopAnalytics) {
                            return;
                        }

                        @if ($ga4PurchaseEnabled)
                            window.ShopAnalytics.trackOnce(@js($purchaseOnceKey), @js($eventName), @js($purchasePayload));
                        @elseif ($metaPurchaseEnabled)
                            window.ShopAnalytics.trackMetaOnce(@js($purchaseOnceKey), 'purchase', @js($purchasePayload));
                        @endif
                    };

                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', trackPurchase, { once: true });
                    } else {
                        trackPurchase();
                    }
                })();
            </script>
        @endpush
    @endif
@endsection
