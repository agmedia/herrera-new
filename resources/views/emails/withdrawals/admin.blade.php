<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('return_request.mail.admin_heading') }}</title>
    <style>
        body { margin:0; padding:0; background:#f1f5f9; color:#0f172a; font-family:Arial,Helvetica,sans-serif; }
        .wrap { width:100%; padding:24px 10px; box-sizing:border-box; }
        .card { max-width:680px; margin:0 auto; background:#fff; border:1px solid #dbe4ee; }
        .head { padding:24px; background:#0f172a; color:#fff; }
        h1 { margin:0; font-size:23px; line-height:1.3; }
        .content { padding:24px; }
        .intro { margin:0 0 18px; color:#475569; font-size:15px; line-height:1.6; }
        .statement { margin:20px 0; padding:16px; border-left:4px solid #0891b2; background:#f8fafc; font-size:15px; font-weight:700; line-height:1.55; }
        table { width:100%; border-collapse:collapse; font-size:14px; }
        th, td { padding:9px 8px; border-bottom:1px solid #e2e8f0; text-align:left; vertical-align:top; }
        th { width:38%; color:#64748b; font-size:12px; text-transform:uppercase; }
        .block { margin-top:20px; }
        .block h2 { margin:0 0 8px; font-size:14px; text-transform:uppercase; }
        .block p { margin:0; white-space:pre-line; color:#334155; font-size:14px; line-height:1.6; }
        .cta { display:inline-block; margin-top:24px; padding:12px 18px; background:#0891b2; color:#fff !important; text-decoration:none; font-size:13px; font-weight:700; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="head"><h1>{{ __('return_request.mail.admin_heading') }}</h1></div>
            <div class="content">
                <p class="intro">{{ __('return_request.mail.admin_intro') }}</p>
                <div class="statement">{{ $withdrawal->declaration }}</div>
                <table role="presentation">
                    <tr><th>{{ __('return_request.mail.reference') }}</th><td>{{ $withdrawal->reference }}</td></tr>
                    <tr><th>{{ __('return_request.mail.submitted_at') }}</th><td>{{ $withdrawal->submitted_at?->format('d.m.Y. H:i:s T') }}</td></tr>
                    <tr><th>{{ __('return_request.mail.full_name') }}</th><td>{{ $withdrawal->full_name }}</td></tr>
                    <tr><th>{{ __('return_request.mail.email') }}</th><td>{{ $withdrawal->email }}</td></tr>
                    <tr><th>{{ __('return_request.mail.phone') }}</th><td>{{ $withdrawal->phone ?: '—' }}</td></tr>
                    <tr><th>{{ __('return_request.mail.address') }}</th><td>{{ $withdrawal->address_line }}, {{ $withdrawal->postal_code }} {{ $withdrawal->city }}, {{ $withdrawal->country_code }}</td></tr>
                    <tr><th>{{ __('return_request.mail.order_number') }}</th><td>{{ $withdrawal->order_number }}</td></tr>
                    <tr><th>{{ __('return_request.mail.contract_date') }}</th><td>{{ $withdrawal->contract_date?->format('d.m.Y.') ?: '—' }}</td></tr>
                    <tr><th>{{ __('return_request.mail.received_date') }}</th><td>{{ $withdrawal->received_date?->format('d.m.Y.') ?: '—' }}</td></tr>
                </table>
                <div class="block"><h2>{{ __('return_request.mail.items') }}</h2><p>{{ $withdrawal->items }}</p></div>
                <div class="block"><h2>{{ __('return_request.mail.note') }}</h2><p>{{ $withdrawal->note ?: '—' }}</p></div>
                <a href="{{ $adminUrl }}" class="cta">{{ __('return_request.mail.admin_cta') }}</a>
            </div>
        </div>
    </div>
</body>
</html>
