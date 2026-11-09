(() => {
    const init = () => {
        document.querySelectorAll('[data-cart-qty-control]').forEach((control) => {
            if (control.dataset.cartQtyInit === '1') {
                return;
            }

            const input = control.querySelector('[data-cart-qty-input]');
            const decrease = control.querySelector('[data-cart-qty-dec]');
            const increase = control.querySelector('[data-cart-qty-inc]');
            if (!input || !decrease || !increase) {
                return;
            }

            control.dataset.cartQtyInit = '1';

            const setValue = (value) => {
                input.value = String(window.ShopOrderQuantity.normalize(input, value));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            };

            decrease.addEventListener('click', () => {
                setValue(window.ShopOrderQuantity.move(input, -1));
            });

            increase.addEventListener('click', () => {
                setValue(window.ShopOrderQuantity.move(input, 1));
            });
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
