<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ __('Pristup kupcu više nije dostupan') }}</title>
    <style>
        body { margin: 0; padding: 24px; background: #f3f7f8; color: #29363c; font: 16px/1.6 system-ui, sans-serif; }
        main { max-width: 560px; margin: 10vh auto; padding: 32px; background: white; border: 1px solid #dce6e9; border-radius: 12px; }
        h1 { margin-top: 0; font-size: 26px; line-height: 1.3; }
        button { padding: 12px 20px; border: 0; border-radius: 6px; background: #007c8d; color: white; font: inherit; font-weight: 600; cursor: pointer; }
        button:focus-visible { outline: 3px solid #29363c; outline-offset: 3px; }
    </style>
</head>
<body>
    <main data-customer-impersonation-expired>
        <h1>{{ __('Pristup kupcu više nije dostupan') }}</h1>
        <p>{{ __('Dodjela kupca ili vaše ovlasti promijenjene su tijekom rada. Vratite se u administraciju i odaberite dostupnog kupca.') }}</p>
        <form method="POST" action="{{ route('front.impersonation.stop') }}">
            @csrf
            <button type="submit">{{ __('Vrati se u admin') }}</button>
        </form>
    </main>
</body>
</html>
