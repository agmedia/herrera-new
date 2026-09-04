(() => {
    const initRoot = (root) => {
        if (!(root instanceof HTMLElement) || root.dataset.catalogLoadMoreInit === '1') {
            return;
        }
        root.dataset.catalogLoadMoreInit = '1';

        const grid = root.querySelector('[data-catalog-grid]');
        const controls = root.querySelector('[data-catalog-load-more-root]');
        if (!(grid instanceof HTMLElement) || !(controls instanceof HTMLElement)) {
            return;
        }

        const button = controls.querySelector('[data-catalog-load-more-button]');
        const nextLink = controls.querySelector('[data-catalog-next-url]');
        const loader = controls.querySelector('[data-catalog-load-more-loader]');
        const mode = String(controls.dataset.catalogLoadMode || 'load_more').trim();
        if (!(button instanceof HTMLElement) || !(nextLink instanceof HTMLAnchorElement)) {
            return;
        }
        const loaderText = loader instanceof HTMLElement
            ? loader.querySelector('[data-catalog-load-more-loader-text]')
            : null;
        const loaderSpinner = loader instanceof HTMLElement
            ? loader.querySelector('[data-catalog-load-more-spinner]')
            : null;

        let loading = false;
        let loadingStartedAt = 0;
        let observer = null;
        let sentinel = null;

        const setLoading = (state) => {
            loading = state;
            if (state) {
                loadingStartedAt = Date.now();
            }
            button.classList.toggle('hidden', state);
            if (loader instanceof HTMLElement) {
                loader.classList.toggle('hidden', !state);
                const loadingLabel = String(loader.dataset.loadingLabel || 'Loading...').trim();
                if (loaderText instanceof HTMLElement) {
                    loaderText.textContent = loadingLabel;
                } else {
                    loader.textContent = loadingLabel;
                }
                if (loaderSpinner instanceof HTMLElement) {
                    loaderSpinner.classList.remove('hidden');
                }
            }
        };

        const setLoadingVisibleWithMinimum = async (minimumMs = 260) => {
            const elapsed = Date.now() - loadingStartedAt;
            if (elapsed < minimumMs) {
                await new Promise((resolve) => window.setTimeout(resolve, minimumMs - elapsed));
            }
            setLoading(false);
        };

        const disableControls = () => {
            button.classList.add('hidden');
            if (loader instanceof HTMLElement) {
                const endLabel = String(loader.dataset.endLabel || 'End of list').trim();
                if (loaderText instanceof HTMLElement) {
                    loaderText.textContent = endLabel;
                } else {
                    loader.textContent = endLabel;
                }
                if (loaderSpinner instanceof HTMLElement) {
                    loaderSpinner.classList.add('hidden');
                }
                loader.classList.remove('hidden');
            }
            nextLink.remove();
            if (observer) {
                observer.disconnect();
            }
            if (sentinel instanceof HTMLElement) {
                sentinel.remove();
            }
        };
        const loadNext = async () => {
            if (loading) {
                return;
            }

            const targetUrl = String(nextLink.getAttribute('href') || '').trim();
            if (targetUrl === '') {
                disableControls();
                return;
            }

            setLoading(true);

            try {
                const response = await fetch(targetUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    await setLoadingVisibleWithMinimum();
                    return;
                }

                const html = await response.text();
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const nextGrid = doc.querySelector('[data-catalog-grid]');
                if (!(nextGrid instanceof HTMLElement)) {
                    disableControls();
                    return;
                }

                const fragment = document.createDocumentFragment();
                Array.from(nextGrid.children).forEach((child) => {
                    fragment.appendChild(child.cloneNode(true));
                });
                grid.appendChild(fragment);

                // Keep browser URL in sync with the latest loaded page (?page=N).
                if (window.history && typeof window.history.replaceState === 'function') {
                    window.history.replaceState(window.history.state, '', targetUrl);
                }

                const incomingNextLink = doc.querySelector('[data-catalog-next-url]');
                const incomingHref = incomingNextLink instanceof HTMLAnchorElement
                    ? String(incomingNextLink.getAttribute('href') || '').trim()
                    : '';

                if (incomingHref !== '') {
                    nextLink.setAttribute('href', incomingHref);
                    await setLoadingVisibleWithMinimum();
                } else {
                    disableControls();
                }

                document.dispatchEvent(new CustomEvent('catalog:items-appended', {
                    detail: { container: grid },
                }));
            } catch (error) {
                await setLoadingVisibleWithMinimum();
            }
        };

        button.addEventListener('click', loadNext);

        if (mode === 'infinite') {
            sentinel = document.createElement('div');
            sentinel.setAttribute('data-catalog-infinite-sentinel', '1');
            sentinel.className = 'h-1 w-full';
            controls.appendChild(sentinel);

            if ('IntersectionObserver' in window) {
                observer = new IntersectionObserver((entries) => {
                    const entry = entries[0];
                    if (!entry || !entry.isIntersecting) {
                        return;
                    }
                    loadNext();
                }, {
                    root: null,
                    rootMargin: '420px 0px',
                    threshold: 0.01,
                });
                observer.observe(sentinel);
            } else {
                const fallback = () => {
                    const nextHref = String(nextLink.getAttribute('href') || '').trim();
                    if (!nextHref || loading) {
                        return;
                    }
                    const viewportBottom = window.scrollY + window.innerHeight;
                    const triggerY = controls.getBoundingClientRect().top + window.scrollY - 220;
                    if (viewportBottom >= triggerY) {
                        loadNext();
                    }
                };
                window.addEventListener('scroll', fallback, { passive: true });
            }
        }
    };

    const init = () => {
        document.querySelectorAll('[data-catalog-load-more-root]').forEach((controls) => {
            const root = controls.closest('section');
            if (root) {
                initRoot(root);
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
