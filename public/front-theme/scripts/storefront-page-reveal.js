(() => {
    const root = document.documentElement;
    if (!root.hasAttribute('data-storefront-page-loading')) {
        return;
    }

    let finished = false;
    let settling = false;
    let deadline = 0;
    let fadeCleanup = 0;
    const imageCleanups = [];
    const stopWaiting = () => {
        window.clearTimeout(window.__storefrontPageRevealTimeout);
        window.clearTimeout(deadline);
        imageCleanups.splice(0).forEach((cleanup) => cleanup());
    };
    const clear = () => {
        finished = true;
        stopWaiting();
        window.clearTimeout(fadeCleanup);
        root.removeAttribute('data-storefront-page-loading');
        root.removeAttribute('data-storefront-page-ready');
    };
    const reveal = () => {
        if (finished) return;
        if (!root.hasAttribute('data-storefront-page-loading')) {
            clear();
            return;
        }
        finished = true;
        stopWaiting();
        root.setAttribute('data-storefront-page-ready', '');
        window.requestAnimationFrame(() => {
            if (!root.hasAttribute('data-storefront-page-ready')) return;
            root.removeAttribute('data-storefront-page-loading');
            fadeCleanup = window.setTimeout(() => root.removeAttribute('data-storefront-page-ready'), 220);
        });
    };
    const revealAfterLayout = () => {
        if (finished || settling) return;
        settling = true;
        // Let all deferred page initializers and their layout changes finish.
        window.requestAnimationFrame(() => window.requestAnimationFrame(reveal));
    };
    const waitForImage = (image) => new Promise((resolve) => {
        const cleanup = () => {
            image.removeEventListener('load', ready);
            image.removeEventListener('error', ready);
            resolve();
        };
        const ready = () => {
            image.removeEventListener('load', ready);
            image.removeEventListener('error', ready);
            if (!image.naturalWidth || typeof image.decode !== 'function') {
                resolve();
                return;
            }
            Promise.resolve().then(() => image.decode()).catch(() => {}).then(resolve);
        };
        imageCleanups.push(cleanup);
        if (image.complete) {
            ready();
        } else {
            image.addEventListener('load', ready, { once: true });
            image.addEventListener('error', ready, { once: true });
        }
    });
    const init = () => {
        if (finished) return;
        deadline = window.setTimeout(revealAfterLayout, 1500);
        const visibleImages = Array.from(document.images).filter((image) => {
            // Do not request or wait for lazy images below the current viewport.
            if (!image.currentSrc && !image.getAttribute('src')) return false;
            if (image.closest('[data-mobile-menu-root], [data-header-cart-popover], [data-catalog-mega]')) return false;
            const rect = image.getBoundingClientRect();
            if (rect.width <= 0 || rect.height <= 0 || rect.bottom <= 0 || rect.top >= window.innerHeight
                || rect.right <= 0 || rect.left >= window.innerWidth) return false;
            const style = window.getComputedStyle(image);
            return style.visibility === 'visible' && style.display !== 'none' && style.opacity !== '0';
        });
        Promise.all([
            Promise.resolve(document.fonts?.ready).catch(() => {}),
            ...visibleImages.map(waitForImage),
        ]).then(revealAfterLayout);
    };

    window.addEventListener('pagehide', clear);
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) clear();
    });
    if (document.readyState !== 'complete') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
