(() => {
    const init = function () {
        if (window.__productCardCartModalInit === true) {
            return;
        }
        window.__productCardCartModalInit = true;

        const cartCountNodes = document.querySelectorAll('[data-cart-count]');
        let openCard = document
            .querySelector('[data-card-overlay-toggle][aria-expanded="true"]')
            ?.closest('[data-product-card]') || null;

        const setCartCount = function (count) {
            const safeCount = Number.isFinite(count) ? Math.max(0, count) : 0;
            cartCountNodes.forEach(function (node) {
                node.textContent = String(safeCount);
            });
        };

        const closeCardOverlay = function (card) {
            const panel = card?.querySelector('[data-card-overlay]');
            const toggle = card?.querySelector('[data-card-overlay-toggle]');
            if (!panel || !toggle) {
                return;
            }

            panel.classList.remove('opacity-100', 'pointer-events-auto', 'md:opacity-100', 'md:pointer-events-auto');
            panel.classList.add('opacity-0', 'pointer-events-none', 'md:opacity-0', 'md:pointer-events-none');
            toggle.setAttribute('aria-expanded', 'false');

            if (openCard === card) {
                openCard = null;
            }
        };

        const openCardOverlay = function (card) {
            const panel = card.querySelector('[data-card-overlay]');
            const toggle = card.querySelector('[data-card-overlay-toggle]');
            if (!panel || !toggle) {
                return;
            }

            if (openCard && openCard !== card) {
                closeCardOverlay(openCard);
            }

            panel.classList.remove('opacity-0', 'pointer-events-none', 'md:opacity-0', 'md:pointer-events-none');
            panel.classList.add('opacity-100', 'pointer-events-auto', 'md:opacity-100', 'md:pointer-events-auto');
            toggle.setAttribute('aria-expanded', 'true');
            openCard = card;
        };

        const selectedOptionLabel = function (form) {
            const checked = form.querySelector('input[name="product_option_value_id"]:checked');
            if (!checked) {
                return '';
            }

            const wrappedLabel = checked.closest('label');
            if (wrappedLabel) {
                const textNode = wrappedLabel.querySelector('.product-size-label-text')
                    || wrappedLabel.querySelector('span');
                if (textNode) {
                    return String(textNode.textContent || '').trim();
                }
            }

            const label = checked.id
                ? form.querySelector('label[for="' + checked.id + '"] span')
                : null;
            return label ? String(label.textContent || '').trim() : '';
        };

        const currentQty = function (form) {
            const input = form.querySelector('input[name="quantity"]');
            const value = Number.parseInt(String(input ? input.value : '1'), 10);
            return Number.isNaN(value) ? 1 : Math.min(99, Math.max(1, value));
        };

        const submit = async function (form) {
            if (form.dataset.cartSubmitting === '1') {
                return;
            }

            const optionError = form.querySelector('[data-option-error]');

            form.dataset.cartSubmitting = '1';

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    },
                    body: new FormData(form),
                });

                const payload = await response.json();
                if (!response.ok || !payload.ok) {
                    if (optionError && payload?.errors?.product_option_value_id) {
                        optionError.classList.remove('hidden');
                    }
                    return;
                }

                if (optionError) {
                    optionError.classList.add('hidden');
                }

                if (payload.summary && typeof payload.summary.item_qty === 'number') {
                    setCartCount(payload.summary.item_qty);
                }

                document.dispatchEvent(new CustomEvent('cart:updated', {
                    detail: { summary: payload.summary || null },
                }));

                const quantity = currentQty(form);
                const optionLabel = selectedOptionLabel(form);

                if (window.ShopAnalytics?.trackAddToCartFromForm) {
                    window.ShopAnalytics.trackAddToCartFromForm(form, quantity, optionLabel);
                }

                document.dispatchEvent(new CustomEvent('product-card-overlay:close-all'));
                window.TermolCartModal?.show(form, optionLabel, quantity);
            } catch (error) {
                // Keep the card stable if the request fails.
            } finally {
                delete form.dataset.cartSubmitting;
            }
        };

        document.addEventListener('change', function (event) {
            if (window.__productCardOptionsInit === true) {
                return;
            }

            const input = event.target instanceof Element
                ? event.target.closest('[data-product-card-form] input[name="product_option_value_id"]')
                : null;
            if (!input) {
                return;
            }

            const form = input.closest('[data-product-card-form]');
            form.querySelector('[data-option-error]')?.classList.add('hidden');

            if (form.dataset.autoSubmitOnOption !== '1') {
                return;
            }

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            }
        });

        document.addEventListener('click', function (event) {
            const target = event.target instanceof Element ? event.target : null;
            if (!target) {
                return;
            }

            const quantityButton = target.closest('[data-qty-dec], [data-qty-inc]');
            const cardForm = quantityButton?.closest('[data-product-card-form]');
            if (quantityButton && cardForm) {
                if (window.__productCardQtyInit === true) {
                    return;
                }

                const control = quantityButton.closest('[data-qty-control]');
                const input = control?.querySelector('[data-qty-input]');
                const valueElement = control?.querySelector('[data-qty-value]');
                if (!input || !valueElement) {
                    return;
                }

                const current = Number.parseInt(input.value, 10) || 1;
                const direction = quantityButton.matches('[data-qty-inc]') ? 1 : -1;
                const value = Math.min(9999, Math.max(1, current + direction));

                input.value = String(value);
                if ('value' in valueElement) {
                    valueElement.value = String(value);
                } else {
                    valueElement.textContent = String(value);
                }
                return;
            }

            if (window.__productCardOverlayInit === true) {
                return;
            }

            const toggle = target.closest('[data-card-overlay-toggle]');
            const card = toggle?.closest('[data-product-card]');
            if (!toggle || !card) {
                return;
            }

            if (toggle.getAttribute('aria-expanded') === 'true') {
                closeCardOverlay(card);
            } else {
                openCardOverlay(card);
            }
        });

        document.addEventListener('product-card-overlay:close-all', function () {
            if (openCard) {
                closeCardOverlay(openCard);
            }
        });

        document.addEventListener('submit', function (event) {
            const form = event.target instanceof HTMLFormElement
                ? event.target.closest('[data-product-card-form]')
                : null;
            if (!form) {
                return;
            }

            event.preventDefault();
            const optionInputs = form.querySelectorAll('input[name="product_option_value_id"]');
            const selectedOption = form.querySelector('input[name="product_option_value_id"]:checked');
            const optionError = form.querySelector('[data-option-error]');

            if (optionInputs.length > 0 && !selectedOption) {
                optionError?.classList.remove('hidden');
                return;
            }

            optionError?.classList.add('hidden');
            submit(form);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
        return;
    }

    init();
})();
