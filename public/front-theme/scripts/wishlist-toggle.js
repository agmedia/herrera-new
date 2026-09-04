(() => {
    const init = function () {
        if (window.__wishlistToggleInit === true) {
            return;
        }
        window.__wishlistToggleInit = true;

    const countNodes = document.querySelectorAll('[data-wishlist-count]');
    const wishlistLinks = document.querySelectorAll('[data-wishlist-link]');

    const syncWishlistVisibility = function (count) {
        const hasItems = count > 0;
        wishlistLinks.forEach(function (link) {
            const shouldShow = hasItems || link.hasAttribute('data-wishlist-always-visible');
            link.classList.toggle('hidden', !shouldShow);
            link.style.display = shouldShow ? 'inline-flex' : 'none';
        });
    };

    const setCount = function (count) {
        const safeCount = Number.isFinite(count) ? Math.max(0, count) : 0;
        syncWishlistVisibility(safeCount);
        countNodes.forEach(function (node) {
            node.textContent = String(safeCount);
            node.classList.toggle('hidden', safeCount === 0);
            node.classList.toggle('inline-flex', safeCount > 0);
        });
    };

    const showMessage = function (message, isError) {
        if (!message) {
            return;
        }

        let toast = document.querySelector('[data-wishlist-toast]');
        if (!toast) {
            toast = document.createElement('div');
            toast.setAttribute('data-wishlist-toast', '1');
            toast.className = 'fixed bottom-4 right-4 z-[90] border px-4 py-2 text-sm font-semibold shadow';
            document.body.appendChild(toast);
        }

        toast.textContent = message;
        if (isError) {
            toast.classList.remove('border-emerald-200', 'bg-emerald-50', 'text-emerald-800');
            toast.classList.add('border-rose-200', 'bg-rose-50', 'text-rose-800');
        } else {
            toast.classList.remove('border-rose-200', 'bg-rose-50', 'text-rose-800');
            toast.classList.add('border-emerald-200', 'bg-emerald-50', 'text-emerald-800');
        }

        window.clearTimeout(window.__wishlistToastTimer);
        window.__wishlistToastTimer = window.setTimeout(function () {
            if (toast && toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 1800);
    };

    const resolveButton = function (form) {
        let button = form.querySelector('[data-wishlist-button]');
        if (button) {
            return button;
        }

        const formId = String(form.getAttribute('id') || '').trim();
        if (!formId) {
            return null;
        }

        const selector = typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
            ? '[data-wishlist-button][form="' + CSS.escape(formId) + '"]'
            : '[data-wishlist-button][form="' + formId.replace(/"/g, '\\"') + '"]';

        return document.querySelector(selector);
    };

    const readVisualState = function (form) {
        const button = resolveButton(form);
        if (button && button.classList.contains('is-active')) {
            return true;
        }

        return form.dataset.wishlisted === '1';
    };

    const toggleVisualState = function (form, isActive) {
        form.dataset.wishlisted = isActive ? '1' : '0';

        const button = resolveButton(form);
        if (!button) {
            return;
        }

        button.classList.toggle('is-active', isActive);

        const iconUse = button.querySelector('.fa6-icon use');
        if (iconUse) {
            const currentHref = String(iconUse.getAttribute('href') || '');
            const nextHref = isActive
                ? currentHref.replace('/regular.svg#heart', '/solid.svg#heart')
                : currentHref.replace('/solid.svg#heart', '/regular.svg#heart');
            iconUse.setAttribute('href', nextHref);
        }

        button.setAttribute('aria-label', isActive ? (form.dataset.labelRemove || 'Remove') : (form.dataset.labelAdd || 'Add'));
    };

    const readCurrentCount = function () {
        const node = countNodes[0];
        if (!node) {
            return 0;
        }

        const parsed = Number.parseInt(String(node.textContent || '').trim(), 10);
        return Number.isNaN(parsed) ? 0 : parsed;
    };

    const syncFormsByAction = function (action, isActive) {
        if (!action) {
            return;
        }

        document.querySelectorAll('[data-wishlist-form]').forEach(function (form) {
            if (form.getAttribute('action') !== action) {
                return;
            }
            toggleVisualState(form, isActive);
        });
    };

    const removeWishlistCardIfNeeded = function (form, isActive) {
        if (isActive) {
            return;
        }

        if (!window.location.pathname.includes('/wishlist')) {
            return;
        }

        const card = form.closest('[data-product-card]');
        if (card) {
            card.remove();
        }

        const remainingCards = document.querySelectorAll('[data-product-card]');
        if (remainingCards.length > 0) {
            return;
        }

        const contentSection = document.querySelector('[data-wishlist-page]');
        if (!contentSection || contentSection.querySelector('[data-wishlist-empty]')) {
            return;
        }

        const emptyText = String(contentSection.getAttribute('data-wishlist-empty-text') || '').trim();
        const empty = document.createElement('div');
        empty.setAttribute('data-wishlist-empty', '1');
        empty.className = 'border border-dashed border-slate-300 bg-white p-10 text-center text-slate-500';
        empty.textContent = emptyText || 'Wishlist is empty.';
        contentSection.appendChild(empty);
    };

    document.querySelectorAll('[data-wishlist-form]').forEach(function (form) {
        form.dataset.wishlisted = readVisualState(form) ? '1' : '0';
    });

    setCount(readCurrentCount());

    document.addEventListener('submit', async function (event) {
        const target = event.target;
        if (!target || target.nodeType !== 1 || typeof target.matches !== 'function' || !target.matches('[data-wishlist-form]')) {
            return;
        }

        event.preventDefault();
        const form = target;
        if (form.dataset.wishlistPending === '1') {
            return;
        }
        form.dataset.wishlistPending = '1';

        const wasActive = readVisualState(form);
        const optimisticActive = !wasActive;
        const currentCount = readCurrentCount();
        const optimisticCount = optimisticActive ? currentCount + 1 : currentCount - 1;

        syncFormsByAction(form.getAttribute('action'), optimisticActive);
        setCount(optimisticCount);

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'X-Wishlist-Ajax': '1',
                },
                body: new FormData(form),
            });

            if (!response.ok) {
                throw new Error('Wishlist request failed');
            }

            const contentType = String(response.headers.get('content-type') || '').toLowerCase();
            if (contentType.includes('application/json')) {
                const data = await response.json();
                const isActive = !!data.active;
                syncFormsByAction(form.getAttribute('action'), isActive);
                if (typeof data.count === 'number') {
                    setCount(data.count);
                } else {
                    setCount(optimisticCount);
                }
                removeWishlistCardIfNeeded(form, isActive);
                showMessage(String(data.message || ''), !data.ok);
            } else {
                // Fallback when server responds with redirect/html: keep optimistic state.
                syncFormsByAction(form.getAttribute('action'), optimisticActive);
                setCount(optimisticCount);
                removeWishlistCardIfNeeded(form, optimisticActive);
            }
        } catch (error) {
            syncFormsByAction(form.getAttribute('action'), wasActive);
            setCount(currentCount);
            showMessage(form.dataset.msgFailed || 'Wishlist update failed.', true);
        } finally {
            form.dataset.wishlistPending = '0';
        }
    }, true);

    document.addEventListener('click', function (event) {
        const rawTarget = event.target;
        if (!rawTarget || rawTarget.nodeType !== 1 || typeof rawTarget.closest !== 'function') {
            return;
        }

        const button = rawTarget.closest('[data-wishlist-button]');
        if (!button) {
            return;
        }

        const form = button.form || button.closest('form[data-wishlist-form]');
        if (!form || !form.matches('[data-wishlist-form]')) {
            return;
        }

        if (form.dataset.wishlistPending === '1') {
            event.preventDefault();
        }
    }, true);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
        return;
    }

    init();
})();
