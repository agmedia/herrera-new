(() => {
    const init = () => {
        const root = document.querySelector('[data-header-cart]');
        const trigger = root?.querySelector('[data-header-cart-trigger]');
        const popover = root?.querySelector('[data-header-cart-popover]');

        if (!root || !trigger || !popover || root.dataset.headerCartReady === '1') {
            return;
        }

        root.dataset.headerCartReady = '1';

        const hoverQuery = window.matchMedia('(hover: hover) and (pointer: fine)');
        let closeTimer = 0;
        let refreshRequest = null;

        const cancelClose = () => {
            if (!closeTimer) {
                return;
            }

            window.clearTimeout(closeTimer);
            closeTimer = 0;
        };

        const open = () => {
            cancelClose();
            root.classList.add('is-open');
            trigger.setAttribute('aria-expanded', 'true');
            popover.setAttribute('aria-hidden', 'false');
        };

        const close = () => {
            cancelClose();
            root.classList.remove('is-open');
            trigger.setAttribute('aria-expanded', 'false');
            popover.setAttribute('aria-hidden', 'true');
        };

        const scheduleClose = () => {
            cancelClose();
            closeTimer = window.setTimeout(close, 130);
        };

        const setCartCount = (count) => {
            const safeCount = Number.isFinite(count) ? Math.max(0, count) : 0;
            document.querySelectorAll('[data-cart-count]').forEach((node) => {
                node.textContent = String(safeCount);
            });
        };

        const refresh = async () => {
            const previewUrl = String(popover.dataset.previewUrl || '').trim();
            if (previewUrl === '') {
                return;
            }

            if (refreshRequest) {
                refreshRequest.abort();
            }

            const request = new AbortController();
            refreshRequest = request;

            try {
                const response = await fetch(previewUrl, {
                    headers: {
                        Accept: 'text/html',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    signal: request.signal,
                });

                if (!response.ok) {
                    return;
                }

                const template = document.createElement('template');
                template.innerHTML = await response.text();
                const nextContent = template.content.querySelector('[data-header-cart-content]');
                const currentContent = popover.querySelector('[data-header-cart-content]');

                if (nextContent && currentContent) {
                    currentContent.replaceWith(nextContent);
                }
            } catch (error) {
                if (error.name !== 'AbortError') {
                    return;
                }
            } finally {
                if (refreshRequest === request) {
                    refreshRequest = null;
                }
            }
        };

        root.addEventListener('pointerenter', () => {
            if (hoverQuery.matches) {
                open();
            }
        });

        root.addEventListener('pointerleave', () => {
            if (hoverQuery.matches) {
                scheduleClose();
            }
        });

        root.addEventListener('focusin', open);
        root.addEventListener('focusout', (event) => {
            const nextTarget = event.relatedTarget;
            if (!(nextTarget instanceof Node) || !root.contains(nextTarget)) {
                scheduleClose();
            }
        });

        root.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }

            close();
            trigger.focus({ preventScroll: true });
        });

        root.addEventListener('submit', async (event) => {
            const form = event.target instanceof HTMLFormElement
                ? event.target.closest('[data-header-cart-remove]')
                : null;

            if (!form) {
                return;
            }

            event.preventDefault();

            const button = form.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
            }

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: new FormData(form),
                    credentials: 'same-origin',
                });
                const payload = await response.json().catch(() => null);

                if (!response.ok || !payload?.ok) {
                    return;
                }

                if (payload.summary && typeof payload.summary.item_qty === 'number') {
                    setCartCount(payload.summary.item_qty);
                }

                document.dispatchEvent(new CustomEvent('cart:updated', {
                    detail: { summary: payload.summary || null },
                }));
            } catch (error) {
                return;
            } finally {
                if (button?.isConnected) {
                    button.disabled = false;
                }
            }
        });

        document.addEventListener('cart:updated', (event) => {
            const itemQty = event.detail?.summary?.item_qty;
            if (typeof itemQty === 'number') {
                setCartCount(itemQty);
            }
            refresh();
        });

        window.addEventListener('pageshow', (event) => {
            if (event.persisted) {
                refresh();
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
