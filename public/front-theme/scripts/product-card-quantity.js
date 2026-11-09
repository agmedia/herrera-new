(() => {
    const init = function () {
        if (window.__productCardQtyInit === true) {
            return;
        }
        window.__productCardQtyInit = true;

        document.addEventListener('click', function (event) {
            const button = event.target instanceof Element
                ? event.target.closest('[data-qty-dec], [data-qty-inc]')
                : null;
            if (!button || button.closest('[data-product-detail-form]')) {
                return;
            }

            const control = button.closest('[data-qty-control]');
            const input = control?.querySelector('[data-qty-input]');
            const valueElement = control?.querySelector('[data-qty-value]');
            if (!input || !valueElement) {
                return;
            }

            const direction = button.matches('[data-qty-inc]') ? 1 : -1;
            const value = window.ShopOrderQuantity.move(input, direction);

            input.value = String(value);
            if ('value' in valueElement) {
                valueElement.value = String(value);
            } else {
                valueElement.textContent = String(value);
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
        return;
    }

    init();
})();
