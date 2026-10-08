@once
    @push('scripts')
        <script>
            (function () {
                const init = function () {
                    if (typeof window.Splide !== 'function') {
                        return false;
                    }
                    document.querySelectorAll('[data-herrera-home-carousel]').forEach(function (el) {
                        if (el.dataset.splideReady === '1') {
                            return;
                        }
                        new window.Splide(el).mount();
                        el.dataset.splideReady = '1';
                    });
                    return true;
                };
                if (init()) {
                    return;
                }
                let attempts = 0;
                const timer = window.setInterval(function () {
                    attempts += 1;
                    if (init() || attempts > 40) {
                        window.clearInterval(timer);
                    }
                }, 120);
            })();
        </script>
    @endpush
@endonce
