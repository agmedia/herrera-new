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
                const parsed = Number.parseInt(String(value), 10);
                input.value = String(Number.isNaN(parsed) ? 1 : Math.min(999, Math.max(1, parsed)));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            };

            decrease.addEventListener('click', () => {
                setValue((Number.parseInt(input.value, 10) || 1) - 1);
            });

            increase.addEventListener('click', () => {
                setValue((Number.parseInt(input.value, 10) || 1) + 1);
            });
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
