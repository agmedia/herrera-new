@once
    @push('scripts')
        @php
            $splidePreviousLabel = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.previous')));
            $splideNextLabel = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.next')));
            $splideArrowLabels = ['prev' => $splidePreviousLabel, 'next' => $splideNextLabel, 'first' => $splideNextLabel, 'last' => $splidePreviousLabel];
        @endphp
        <script defer data-storefront-splide src="{{ asset('vendor/splide/splide.min.js') }}?v={{ filemtime(public_path('vendor/splide/splide.min.js')) }}"></script>
        <script>
            (function () {
                const localizeArrows = function () {
                    if (typeof window.Splide === 'function') {
                        window.Splide.defaults = { i18n: @json($splideArrowLabels) };
                    }
                };
                const loader = document.querySelector('[data-storefront-splide]');
                if (loader) {
                    loader.addEventListener('load', localizeArrows, { once: true });
                }
                localizeArrows();
            })();
        </script>
    @endpush
@endonce
