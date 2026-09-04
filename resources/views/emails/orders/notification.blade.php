<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('mail.orders.mail_title', ['order' => $order_number]) }}</title>
    <style>
        body { margin:0; padding:0; background:#f3f4f6; font-family:Arial, Helvetica, sans-serif; color:#0f172a; }
        a, a:link, a:visited { color:#0f172a !important; text-decoration:none !important; }
        a:hover, a:active { color:#0f172a !important; text-decoration:none !important; }
        a[x-apple-data-detectors], u + #body a, #MessageViewBody a { color:#0f172a !important; text-decoration:none !important; font:inherit !important; }
        .wrap { width:100%; padding:20px 10px; box-sizing:border-box; }
        .card { max-width:720px; margin:0 auto; background:#ffffff; border:1px solid #e5e7eb; }
        .head { padding:20px; border-bottom:1px solid #e5e7eb; text-align:center; }
        .logo { max-height:42px; width:auto; }
        .store { margin-top:8px; font-size:34px; font-weight:900; letter-spacing:.01em; text-transform:uppercase; }
        .content { padding:20px; }
        .title { font-size:24px; font-weight:800; margin:0 0 6px; }
        .muted { color:#64748b; font-size:14px; margin:0; }
        .meta { margin-top:14px; border:1px solid #e5e7eb; }
        .meta td { padding:10px 12px; border-bottom:1px solid #e5e7eb; font-size:13px; }
        .meta tr:last-child td { border-bottom:none; }
        .label { color:#64748b; width:180px; }
        .items { width:100%; border-collapse:collapse; margin-top:16px; }
        .items th { text-align:left; font-size:12px; color:#64748b; border-bottom:1px solid #e5e7eb; padding:10px 8px; text-transform:uppercase; letter-spacing:.05em; }
        .items td { border-bottom:1px solid #e5e7eb; padding:10px 8px; vertical-align:top; font-size:14px; }
        .img-wrap { width:72px; height:96px; border:1px solid #e5e7eb; background:#f8fafc; display:flex; align-items:center; justify-content:center; }
        .img { max-width:100%; max-height:100%; width:auto; height:auto; object-fit:contain; display:block; }
        .name { font-weight:700; color:#0f172a; text-decoration:none; }
        .sku { font-size:12px; color:#64748b; margin-top:3px; }
        .num { text-align:right; white-space:nowrap; }
        .totals { width:100%; max-width:360px; margin-left:auto; margin-top:16px; border-collapse:collapse; }
        .totals td { padding:8px 10px; font-size:14px; border-bottom:1px solid #e5e7eb; }
        .totals tr:last-child td { border-bottom:none; font-size:16px; font-weight:800; }
        .totals .k { color:#64748b; }
        .note { margin-top:16px; padding:10px 12px; background:#f8fafc; border:1px solid #e2e8f0; font-size:13px; }
        .bank { margin-top:16px; padding:12px; border:1px solid #e2e8f0; background:#f8fafc; }
        .bank h3 { margin:0 0 8px; font-size:14px; }
        .bank p { margin:4px 0; font-size:13px; }
        .bank-qr { margin-top:10px; }
        .bank-qr img { width:360px; max-width:100%; height:auto; border:1px solid #d1d5db; background:#fff; padding:6px; }
        .foot { padding:16px 20px; border-top:1px solid #e5e7eb; color:#64748b; font-size:12px; text-align:center; }
        @media only screen and (max-width: 620px) {
            .content { padding:14px; }
            .title { font-size:20px; }
            .label { width:120px; }
            .items thead { display:none; }
            .items tr { display:block; border-bottom:1px solid #e5e7eb; padding:10px 0; }
            .items td { display:block; border-bottom:none; padding:4px 0; }
            .num { text-align:left; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="head">
                @if (!empty($logo_url))
                    <img src="{{ $logo_url }}" alt="{{ $store_name }}" class="logo">
                @endif
                <div class="store">{{ $store_name }}</div>
            </div>

            <div class="content">
                <h1 class="title">
                    {{ $variant === 'admin' ? __('mail.orders.heading_admin') : __('mail.orders.heading_customer') }}
                    #{{ $order_number }}
                </h1>
                <p class="muted">
                    {{ $variant === 'admin' ? __('mail.orders.intro_admin') : __('mail.orders.intro_customer') }}
                </p>

                <table class="meta" width="100%" cellspacing="0" cellpadding="0">
                    <tr><td class="label">{{ __('mail.orders.customer') }}</td><td>{{ $customer_name }}</td></tr>
                    <tr><td class="label">{{ __('mail.orders.email') }}</td><td><a href="mailto:{{ $customer_email }}" style="color:#0f172a !important; text-decoration:none !important;">{{ $customer_email }}</a></td></tr>
                    <tr><td class="label">{{ __('mail.orders.phone') }}</td><td>{{ $customer_phone }}</td></tr>
                    <tr><td class="label">{{ __('mail.orders.placed_at') }}</td><td>{{ $placed_at }}</td></tr>
                    <tr><td class="label">{{ __('mail.orders.payment') }}</td><td>{{ $payment_method }}</td></tr>
                    <tr><td class="label">{{ __('mail.orders.shipping') }}</td><td>{{ $shipping_method }}</td></tr>
                    <tr><td class="label">{{ __('mail.orders.billing_address') }}</td><td>{{ $billing_address }}</td></tr>
                    <tr><td class="label">{{ __('mail.orders.shipping_address') }}</td><td>{{ $shipping_address }}</td></tr>
                </table>

                @if (!empty($box_now) && !empty($box_now['locker_id']))
                    <div class="bank">
                        <h3>{{ __('mail.orders.boxnow_title') }}</h3>
                        <p><strong>{{ __('mail.orders.boxnow_locker') }}:</strong> {{ $box_now['locker_name'] ?: '-' }} ({{ $box_now['locker_id'] }})</p>
                        <p>
                            <strong>{{ __('mail.orders.boxnow_address') }}:</strong>
                            {{ trim(($box_now['address_line_1'] ?? '').', '.($box_now['postal_code'] ?? '').' '.($box_now['city'] ?? ''), ', ') ?: '-' }}
                        </p>
                    </div>
                @endif

                <table class="items" cellspacing="0" cellpadding="0">
                    <thead>
                        <tr>
                            <th width="72">{{ __('mail.orders.image') }}</th>
                            <th>{{ __('mail.orders.product') }}</th>
                            <th class="num">{{ __('mail.orders.qty') }}</th>
                            <th class="num">{{ __('mail.orders.price') }}</th>
                            <th class="num">{{ __('mail.orders.total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $item)
                            <tr>
                                <td>
                                    @if (!empty($item['image_url']))
                                        <div class="img-wrap">
                                            <img src="{{ $item['image_url'] }}" alt="{{ $item['name'] }}" class="img">
                                        </div>
                                    @else
                                        <div class="img-wrap"></div>
                                    @endif
                                </td>
                                <td>
                                    @if (!empty($item['product_url']))
                                        <a href="{{ $item['product_url'] }}" class="name" style="color:#0f172a !important; text-decoration:none !important;">{{ $item['name'] }}</a>
                                    @else
                                        <span class="name">{{ $item['name'] }}</span>
                                    @endif
                                    @if (!empty($item['sku']))
                                        <div class="sku">{{ __('mail.orders.sku') }}: {{ $item['sku'] }}</div>
                                    @endif
                                    @if (!empty($item['option_label']))
                                        <div class="sku">{{ __('mail.orders.option') }}: {{ $item['option_label'] }}</div>
                                    @endif
                                </td>
                                <td class="num">{{ $item['quantity'] }}</td>
                                <td class="num">{{ $item['unit_price'] }}</td>
                                <td class="num">{{ $item['line_total'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <table class="totals" cellspacing="0" cellpadding="0">
                    <tr><td class="k">{{ __('mail.orders.subtotal') }}</td><td class="num">{{ $totals['subtotal'] }}</td></tr>
                    @if (($totals_raw['discount'] ?? 0) > 0)
                        <tr><td class="k">{{ __('mail.orders.discount') }}</td><td class="num">-{{ $totals['discount'] }}</td></tr>
                    @endif
                    <tr><td class="k">{{ __('mail.orders.shipping') }}</td><td class="num">{{ $totals['shipping'] }}</td></tr>
                    <tr><td class="k">{{ __('mail.orders.payment_fee') }}</td><td class="num">{{ $totals['payment_fee'] }}</td></tr>
                    <tr><td class="k">{{ __('mail.orders.tax') }}</td><td class="num">{{ $totals['tax'] }}</td></tr>
                    <tr><td>{{ __('mail.orders.total') }}</td><td class="num">{{ $totals['grand_total'] }}</td></tr>
                </table>

                @if (!empty($bank_transfer) && !empty($bank_transfer['receiver_iban']))
                    <div class="bank">
                        <h3>{{ __('mail.orders.bank_transfer_title') }}</h3>
                        <p>{{ __('mail.orders.bank_transfer_note') }}</p>
                        <p><strong>{{ __('mail.orders.bank_recipient') }}:</strong> {{ $bank_transfer['receiver_name'] ?? '-' }}</p>
                        <p><strong>{{ __('mail.orders.bank_iban') }}:</strong> {{ $bank_transfer['receiver_iban'] ?? '-' }}</p>
                        <p><strong>{{ __('mail.orders.bank_model') }}:</strong> {{ $bank_transfer['model'] ?? '-' }}</p>
                        <p><strong>{{ __('mail.orders.bank_reference') }}:</strong> {{ $bank_transfer['reference'] ?? '-' }}</p>
                        <p><strong>{{ __('mail.orders.bank_amount') }}:</strong> {{ number_format((float) ($bank_transfer['amount'] ?? 0), 2, '.', ',') }} {{ $currency }}</p>
                        <p><strong>{{ __('mail.orders.bank_description') }}:</strong> {{ $bank_transfer['description'] ?? '-' }}</p>

                        @if (!empty($bank_transfer['qr_image_base64']))
                            @php
                                $qrBinary = base64_decode((string) $bank_transfer['qr_image_base64'], true);
                                $qrMime = (string) ($bank_transfer['qr_image_mime'] ?? 'image/png');
                                $qrCid = null;
                                if ($qrBinary !== false && isset($message) && method_exists($message, 'embedData')) {
                                    $qrCid = $message->embedData($qrBinary, 'upi-qr-'.$order_number.'.png', $qrMime);
                                }
                            @endphp
                            <div class="bank-qr">
                                @if (!empty($qrCid))
                                    <img src="{{ $qrCid }}" alt="{{ __('mail.orders.bank_qr_alt') }}">
                                @elseif ($qrBinary !== false)
                                    <img src="data:{{ $qrMime }};base64,{{ $bank_transfer['qr_image_base64'] }}" alt="{{ __('mail.orders.bank_qr_alt') }}">
                                @endif
                            </div>
                        @endif
                    </div>
                @endif

                @if (!empty($customer_note))
                    <div class="note">
                        <strong>{{ __('mail.orders.customer_note') }}:</strong><br>
                        {{ $customer_note }}
                    </div>
                @endif
            </div>

            <div class="foot">
                {{ $store_name }} | {{ now()->format('Y') }}
            </div>
        </div>
    </div>
</body>
</html>
