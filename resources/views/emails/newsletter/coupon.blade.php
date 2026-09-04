<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('mail.newsletter_coupon.title') }}</title>
    <style>
        body { margin:0; padding:0; background:#f3f4f6; font-family:Arial, Helvetica, sans-serif; color:#0f172a; }
        a, a:link, a:visited { color:#0f172a !important; text-decoration:none !important; }
        .wrap { width:100%; padding:20px 10px; box-sizing:border-box; }
        .card { max-width:640px; margin:0 auto; background:#ffffff; border:1px solid #e5e7eb; }
        .head { padding:22px 20px; border-bottom:1px solid #e5e7eb; text-align:center; }
        .logo { max-height:42px; width:auto; }
        .store { margin-top:8px; font-size:32px; font-weight:900; letter-spacing:.01em; text-transform:uppercase; }
        .content { padding:24px 22px; text-align:center; }
        .eyebrow { margin:0 0 8px; color:#64748b; font-size:12px; font-weight:700; letter-spacing:.16em; text-transform:uppercase; }
        .title { margin:0; font-size:25px; line-height:1.25; font-weight:800; }
        .text { margin:12px auto 0; max-width:480px; color:#475569; font-size:15px; line-height:1.55; }
        .coupon { display:inline-block; margin:22px 0 10px; padding:14px 28px; border:2px dashed #0f172a; font-size:26px; font-weight:900; letter-spacing:.16em; }
        .cta { display:inline-block; margin-top:16px; padding:12px 22px; background:#0f172a; color:#ffffff !important; font-size:13px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; }
        .foot { padding:16px 20px; border-top:1px solid #e5e7eb; color:#64748b; font-size:12px; text-align:center; }
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
                <p class="eyebrow">{{ __('mail.newsletter_coupon.eyebrow') }}</p>
                <h1 class="title">{{ __('mail.newsletter_coupon.heading') }}</h1>
                <p class="text">{{ __('mail.newsletter_coupon.intro') }}</p>
                <div class="coupon">{{ $coupon_code }}</div>
                <p class="text">{{ __('mail.newsletter_coupon.note') }}</p>
                <a href="{{ $shop_url }}" class="cta">{{ __('mail.newsletter_coupon.cta') }}</a>
            </div>

            <div class="foot">
                {{ __('mail.newsletter_coupon.footer', ['store' => $store_name]) }}
            </div>
        </div>
    </div>
</body>
</html>
