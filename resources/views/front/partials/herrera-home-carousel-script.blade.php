@once
    @push('scripts')
        <script>
            (function () {
                const init = function () {
                    if (document.readyState === 'loading' || typeof window.Splide !== 'function') {
                        return false;
                    }
                    document.querySelectorAll('[data-herrera-home-carousel]').forEach(function (el) {
                        if (el.dataset.splideReady === '1') {
                            return;
                        }
                        const cardControls = Array.from(el.querySelectorAll('a, button, textarea, input, select, iframe'))
                            .map(function (node) { return [node, node.getAttribute('tabindex')]; });
                        new window.Splide(el).mount({
                            RestoreCardGridFocus: function () {
                                return {
                                    destroy: function () {
                                        cardControls.forEach(function ([node, tabindex]) {
                                            if (tabindex === null) {
                                                node.removeAttribute('tabindex');
                                            } else {
                                                node.setAttribute('tabindex', tabindex);
                                            }
                                        });
                                    },
                                };
                            },
                        });
                        el.dataset.splideReady = '1';
                    });
                    return true;
                };
                const loader = document.querySelector('[data-storefront-splide]');
                if (loader) {
                    loader.addEventListener('load', init, { once: true });
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', init, { once: true });
                }
                init();
            })();
        </script>
    @endpush
@endonce
