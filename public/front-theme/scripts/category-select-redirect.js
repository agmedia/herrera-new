(() => {
    const init = () => {
        const selects = document.querySelectorAll('select[data-category-redirect]');
        if (!selects.length) {
            return;
        }

        selects.forEach((select) => {
            select.addEventListener('change', () => {
                const selectedOption = select.options[select.selectedIndex];
                const targetUrl = selectedOption?.dataset?.url || select.dataset.defaultUrl || '';
                if (!targetUrl) {
                    return;
                }

                window.location.href = targetUrl;
            });
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
