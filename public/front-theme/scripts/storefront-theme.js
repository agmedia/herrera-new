(() => {
    const root = document.documentElement;
    const storageKey = 'herrera.storefront.theme';
    const selector = '[data-storefront-theme-toggle]';
    const normalize = (theme) => theme === 'dark' ? 'dark' : 'light';

    const applyTheme = (theme) => {
        const nextTheme = normalize(theme);
        root.setAttribute('data-theme', nextTheme);
        document.querySelectorAll(selector).forEach((button) => {
            button.hidden = false;
            button.setAttribute('aria-checked', String(nextTheme === 'dark'));
            button.setAttribute('title', nextTheme === 'dark'
                ? button.dataset.themeLabelLight
                : button.dataset.themeLabelDark);
        });
    };

    const readPreference = () => {
        try {
            return normalize(window.localStorage.getItem(storageKey));
        } catch (_) {
            return normalize(root.getAttribute('data-theme'));
        }
    };

    const init = () => applyTheme(root.getAttribute('data-theme'));
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }

    document.addEventListener('click', (event) => {
        const button = event.target.closest?.(selector);
        if (!button) return;

        const theme = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        applyTheme(theme);
        try {
            window.localStorage.setItem(storageKey, theme);
        } catch (_) {
            // Keep the current page usable when browser storage is unavailable.
        }
    });

    window.addEventListener('storage', (event) => {
        if (event.key === storageKey || event.key === null) {
            applyTheme(readPreference());
        }
    });
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) applyTheme(readPreference());
    });
})();
