{{-- Restore the explicit preference before any styles paint; light is the default. --}}
<script data-storefront-theme-boot>
    (() => {
        let theme = 'light';
        try {
            if (window.localStorage.getItem('herrera.storefront.theme') === 'dark') theme = 'dark';
        } catch (_) {
            // Browser storage may be disabled; the switch still works for this page.
        }
        document.documentElement.setAttribute('data-theme', theme);
    })();
</script>
<script defer src="{{ asset('front-theme/scripts/storefront-theme.js') }}?v={{ filemtime(public_path('front-theme/scripts/storefront-theme.js')) }}"></script>
