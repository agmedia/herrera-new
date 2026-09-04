(() => {
    const init = () => {
        const rotator = document.querySelector('[data-store-benefits-rotator]');
        if (!(rotator instanceof HTMLElement) || rotator.dataset.storeBenefitsInit === '1') {
            return;
        }

        const items = Array.from(rotator.querySelectorAll('[data-store-benefit-item]'));
        if (items.length < 2) {
            return;
        }

        rotator.dataset.storeBenefitsInit = '1';

        const compactViewport = window.matchMedia('(max-width: 1279px)');
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        let activeIndex = 0;
        let rotationTimer = 0;
        let exitTimer = 0;

        const showItem = (nextIndex, animate = true) => {
            const previousItem = items[activeIndex];
            const nextItem = items[nextIndex];

            if (animate && previousItem !== nextItem) {
                previousItem.classList.add('is-exiting');
            }

            items.forEach((item, index) => {
                const isActive = index === nextIndex;
                item.classList.toggle('is-active', isActive);
                item.setAttribute('aria-hidden', isActive ? 'false' : 'true');
            });

            window.clearTimeout(exitTimer);
            exitTimer = window.setTimeout(() => {
                items.forEach((item) => item.classList.remove('is-exiting'));
            }, 420);

            activeIndex = nextIndex;
        };

        const stopRotation = () => {
            window.clearInterval(rotationTimer);
            rotationTimer = 0;
        };

        const syncMode = () => {
            stopRotation();

            if (!compactViewport.matches) {
                items.forEach((item) => {
                    item.classList.remove('is-active', 'is-exiting');
                    item.setAttribute('aria-hidden', 'false');
                });
                return;
            }

            showItem(activeIndex, false);

            if (reducedMotion.matches) {
                return;
            }

            rotationTimer = window.setInterval(() => {
                showItem((activeIndex + 1) % items.length);
            }, 4000);
        };

        const bindMediaChange = (mediaQuery) => {
            if (typeof mediaQuery.addEventListener === 'function') {
                mediaQuery.addEventListener('change', syncMode);
                return;
            }

            mediaQuery.addListener(syncMode);
        };

        bindMediaChange(compactViewport);
        bindMediaChange(reducedMotion);
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                stopRotation();
                return;
            }

            syncMode();
        });

        syncMode();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
        return;
    }

    init();
})();
