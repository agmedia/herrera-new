/* Category catalog interactions. Loaded with defer and safe to cache. */
(() => {
    const buildCleanFormUrl = (form) => {
        const url = new URL(form.action, window.location.origin);
        const colsField = form.querySelector('[name="cols"]');
        const colsValue = String(colsField?.value || '').trim();

        if (colsValue !== '') {
            url.searchParams.set('cols', colsValue);
        }

        return url.toString();
    };

    const hasActiveFilterFields = (form) => Array.from(form.elements).some((field) => {
        if (!field || !field.name || field.disabled) {
            return false;
        }

        if (field.name === 'cols') {
            return false;
        }

        if (field.type === 'checkbox' || field.type === 'radio') {
            return field.checked;
        }

        const value = String(field.value || '').trim();
        if (value === '') {
            return false;
        }

        if (field.name === 'sort') {
            return value !== 'default';
        }

        return true;
    });

    const updateGlobalResetVisibility = (form) => {
        if (!form) {
            return;
        }

        const hasActiveFilters = hasActiveFilterFields(form);
        form.querySelectorAll('[data-global-filter-reset]').forEach((node) => {
            node.classList.toggle('hidden', !hasActiveFilters);
        });
    };

    const submitFilterForm = (form) => {
        if (!form) {
            return;
        }

        updateGlobalResetVisibility(form);

        if (!hasActiveFilterFields(form)) {
            window.location.assign(buildCleanFormUrl(form));
            return;
        }

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
            return;
        }

        form.submit();
    };

    const closePricePanel = (root) => {
        if (!root) {
            return;
        }

        root.classList.remove('is-open');
        const toggle = root.querySelector('[data-price-filter-toggle]');
        if (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
        }
    };

    const closeAllPricePanels = (exceptRoot = null) => {
        document.querySelectorAll('[data-price-filter-root].is-open').forEach((root) => {
            if (root !== exceptRoot) {
                closePricePanel(root);
            }
        });
    };

    const closeAllCustomSelects = () => {
        document.querySelectorAll('[data-custom-select].is-open').forEach((root) => {
            root.classList.remove('is-open');
            const button = root.querySelector('[data-custom-select-button]');
            if (button) {
                button.setAttribute('aria-expanded', 'false');
            }
        });
    };

    const initPriceRange = (root) => {
        if (root.dataset.priceRangeInit === '1') {
            return;
        }

        root.dataset.priceRangeInit = '1';

        const form = root.closest('form');
        const minRange = root.querySelector('[data-price-range-min]');
        const maxRange = root.querySelector('[data-price-range-max]');
        const hiddenMin = root.querySelector('[data-price-range-hidden-min]');
        const hiddenMax = root.querySelector('[data-price-range-hidden-max]');
        const currentMin = root.querySelector('[data-price-range-current-min]');
        const currentMax = root.querySelector('[data-price-range-current-max]');
        const progress = root.querySelector('[data-price-range-progress]');
        const promoToggle = root.querySelector('[data-price-range-promo]');
        const resetButton = root.querySelector('[data-price-filter-reset]');
        const desktopPriceFilter = root.closest('[data-price-filter-root]');
        const desktopPriceToggle = desktopPriceFilter?.querySelector('[data-price-filter-toggle]');
        const manualSubmit = root.hasAttribute('data-price-manual-submit');

        if (!form || !minRange || !maxRange || !hiddenMin || !hiddenMax || !currentMin || !currentMax || !progress) {
            return;
        }

        const minBound = Number(root.dataset.priceMinBound || minRange.min || 0);
        const maxBound = Number(root.dataset.priceMaxBound || maxRange.max || minBound);
        const totalRange = Math.max(1, maxBound - minBound);

        const formatPrice = (value) => `${Math.round(value)} €`;
        const setActiveState = () => {
            const hasActivePriceFilter = hiddenMin.value !== '' || hiddenMax.value !== '' || Boolean(promoToggle?.checked);
            if (desktopPriceToggle) {
                desktopPriceToggle.classList.toggle('is-active', hasActivePriceFilter);
            }
            if (resetButton) {
                resetButton.disabled = !hasActivePriceFilter;
            }
            updateGlobalResetVisibility(form);
        };

        const normalizePair = () => {
            let minValue = Number(minRange.value || minBound);
            let maxValue = Number(maxRange.value || maxBound);

            minValue = Math.max(minBound, Math.min(maxBound, minValue));
            maxValue = Math.max(minBound, Math.min(maxBound, maxValue));

            if (minValue > maxValue) {
                if (document.activeElement === minRange) {
                    maxValue = minValue;
                } else {
                    minValue = maxValue;
                }
            }

            minRange.value = String(minValue);
            maxRange.value = String(maxValue);

            return { minValue, maxValue };
        };

        const syncRangeState = () => {
            const { minValue, maxValue } = normalizePair();
            const left = ((minValue - minBound) / totalRange) * 100;
            const width = ((maxValue - minValue) / totalRange) * 100;

            hiddenMin.value = minValue <= minBound ? '' : String(minValue);
            hiddenMax.value = maxValue >= maxBound ? '' : String(maxValue);
            currentMin.textContent = formatPrice(minValue);
            currentMax.textContent = formatPrice(maxValue);
            progress.style.left = `${left}%`;
            progress.style.width = `${width}%`;

            setActiveState();
        };

        minRange.addEventListener('input', syncRangeState);
        maxRange.addEventListener('input', syncRangeState);
        minRange.addEventListener('change', () => {
            syncRangeState();
            if (!manualSubmit) {
                submitFilterForm(form);
            }
        });
        maxRange.addEventListener('change', () => {
            syncRangeState();
            if (!manualSubmit) {
                submitFilterForm(form);
            }
        });

        if (promoToggle) {
            promoToggle.addEventListener('change', () => {
                setActiveState();
                if (!manualSubmit) {
                    submitFilterForm(form);
                }
            });
        }

        if (resetButton) {
            resetButton.addEventListener('click', () => {
                minRange.value = String(minBound);
                maxRange.value = String(maxBound);
                if (promoToggle) {
                    promoToggle.checked = false;
                }
                syncRangeState();
                if (!manualSubmit) {
                    closePricePanel(root.closest('[data-price-filter-root]'));
                    submitFilterForm(form);
                }
            });
        }

        syncRangeState();
    };

    const initStickyFilterBar = () => {
        const shell = document.querySelector('[data-sticky-filter-shell]');
        const bar = shell?.querySelector('[data-sticky-filter-bar]');

        if (!shell || !bar || shell.dataset.stickyInit === '1') {
            return;
        }

        shell.dataset.stickyInit = '1';

        let rafId = 0;
        const readStickyOffset = () => {
            const rootStyles = window.getComputedStyle(document.documentElement);
            const cssValue = parseFloat(rootStyles.getPropertyValue('--site-header-bottom'));
            if (Number.isFinite(cssValue) && cssValue > 0) {
                return cssValue;
            }

            const header = document.querySelector('.site-main-header');
            if (header instanceof HTMLElement) {
                return Math.max(0, header.getBoundingClientRect().bottom);
            }

            return 60;
        };

        const applyStickyState = () => {
            rafId = 0;

            const stickyOffset = readStickyOffset();
            const shellRect = shell.getBoundingClientRect();
            const barRect = bar.getBoundingClientRect();
            const shouldPin = shellRect.top <= stickyOffset;

            if (!shouldPin) {
                shell.classList.remove('is-pinned');
                shell.style.removeProperty('--catalog-sticky-height');
                shell.style.removeProperty('--catalog-sticky-top');
                shell.style.removeProperty('--catalog-sticky-left');
                shell.style.removeProperty('--catalog-sticky-width');
                return;
            }

            shell.style.setProperty('--catalog-sticky-height', `${Math.ceil(barRect.height)}px`);
            shell.style.setProperty('--catalog-sticky-top', `${Math.round(stickyOffset)}px`);
            shell.style.setProperty('--catalog-sticky-left', `${Math.round(shellRect.left)}px`);
            shell.style.setProperty('--catalog-sticky-width', `${Math.round(shellRect.width)}px`);
            shell.classList.add('is-pinned');
        };

        const requestApply = () => {
            if (rafId) {
                return;
            }

            rafId = window.requestAnimationFrame(applyStickyState);
        };

        requestApply();
        window.addEventListener('scroll', requestApply, { passive: true });
        window.addEventListener('resize', requestApply);
    };

    const initResponsiveGridToggles = () => {
        const grid = document.querySelector('[data-catalog-grid]');
        const toggles = Array.from(document.querySelectorAll('[data-catalog-grid-cols]'));

        if (!grid || toggles.length === 0 || grid.dataset.gridToggleSyncInit === '1') {
            return;
        }

        grid.dataset.gridToggleSyncInit = '1';

        let rafId = 0;
        const countRenderedColumns = () => {
            const template = window.getComputedStyle(grid).gridTemplateColumns.trim();
            return template === '' || template === 'none'
                ? 0
                : template.split(/\s+/).length;
        };
        const syncToggleState = () => {
            rafId = 0;
            const renderedColumns = countRenderedColumns();

            toggles.forEach((toggle) => {
                const isActive = Number(toggle.dataset.catalogGridCols) === renderedColumns;
                toggle.classList.toggle('is-active', isActive);

                if (isActive) {
                    toggle.setAttribute('aria-current', 'true');
                } else {
                    toggle.removeAttribute('aria-current');
                }
            });
        };
        const requestSync = () => {
            if (rafId) {
                return;
            }

            rafId = window.requestAnimationFrame(syncToggleState);
        };

        requestSync();
        window.addEventListener('resize', requestSync);

        if (typeof window.ResizeObserver === 'function') {
            const resizeObserver = new window.ResizeObserver(requestSync);
            resizeObserver.observe(grid);
        }
    };

    const init = () => {
        document.querySelectorAll('[data-mobile-filter-root]').forEach((root) => {
            if (root.dataset.mobileFilterInit === '1') {
                return;
            }
            root.dataset.mobileFilterInit = '1';
            const toggle = root.querySelector('[data-mobile-filter-toggle]');
            const drawer = root.querySelector('[data-mobile-filter-drawer]');
            const closeButtons = root.querySelectorAll('[data-mobile-filter-close]');
            let previouslyFocused = null;
            if (!toggle || !drawer) {
                return;
            }

            if (drawer.dataset.mobileFilterMounted !== '1') {
                drawer.dataset.mobileFilterMounted = '1';
                document.body.appendChild(drawer);
            }

            const openDrawer = () => {
                previouslyFocused = document.activeElement;
                drawer.classList.remove('hidden');
                drawer.classList.add('flex');
                toggle.setAttribute('aria-expanded', 'true');
                root.classList.add('is-open');
                document.body.classList.add('overflow-hidden', 'desktop-mobile-filter-open');
                window.requestAnimationFrame(() => {
                    const closeButton = drawer.querySelector('[data-mobile-filter-close]:not(.catalog-mobile-filter-drawer-backdrop)');
                    closeButton?.focus();
                });
            };

            const closeDrawer = () => {
                drawer.classList.add('hidden');
                drawer.classList.remove('flex');
                toggle.setAttribute('aria-expanded', 'false');
                root.classList.remove('is-open');
                document.body.classList.remove('desktop-mobile-filter-open');
                if (!document.body.classList.contains('desktop-mobile-menu-open')) {
                    document.body.classList.remove('overflow-hidden');
                }
                if (previouslyFocused instanceof HTMLElement) {
                    previouslyFocused.focus();
                } else {
                    toggle.focus();
                }
            };

            toggle.addEventListener('click', () => {
                if (drawer.classList.contains('hidden')) {
                    openDrawer();
                    return;
                }

                closeDrawer();
            });

            closeButtons.forEach((button) => {
                button.addEventListener('click', closeDrawer);
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !drawer.classList.contains('hidden')) {
                    closeDrawer();
                }
            });

            window.addEventListener('resize', () => {
                if (window.innerWidth >= 1025 && !drawer.classList.contains('hidden')) {
                    closeDrawer();
                }
            });

            drawer.addEventListener('keydown', (event) => {
                if (event.key !== 'Tab') {
                    return;
                }

                const focusable = Array.from(drawer.querySelectorAll(
                    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
                )).filter((element) => element instanceof HTMLElement && element.offsetParent !== null);

                if (focusable.length === 0) {
                    event.preventDefault();
                    return;
                }

                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            });
        });

        document.querySelectorAll('[data-exclusive-filter]').forEach((control) => {
            if (control.dataset.exclusiveFilterInit === '1') {
                return;
            }
            control.dataset.exclusiveFilterInit = '1';
            control.addEventListener('change', () => {
                if (!control.checked || !control.name) {
                    return;
                }

                const form = control.form || control.closest('form');
                if (!form) {
                    return;
                }

                form.querySelectorAll('[data-exclusive-filter]').forEach((other) => {
                    if (other !== control && other.name === control.name) {
                        other.checked = false;
                    }
                });
            });
        });

        document.querySelectorAll('[data-auto-submit-filter]').forEach((control) => {
            if (control.dataset.autoSortInit === '1') {
                return;
            }
            control.dataset.autoSortInit = '1';
            control.addEventListener('change', () => {
                const form = control.form || control.closest('form');
                if (!form) {
                    return;
                }
                updateGlobalResetVisibility(form);
                submitFilterForm(form);
            });
        });

        document.querySelectorAll('form[data-desktop-filter-form], form[data-mobile-filter-panel]').forEach((form) => {
            updateGlobalResetVisibility(form);
        });

        document.querySelectorAll('[data-price-range-root]').forEach((root) => {
            initPriceRange(root);
        });

        document.querySelectorAll('[data-price-filter-root]').forEach((root) => {
            if (root.dataset.priceFilterInit === '1') {
                return;
            }

            root.dataset.priceFilterInit = '1';
            const toggle = root.querySelector('[data-price-filter-toggle]');
            const panel = root.querySelector('[data-price-filter-panel]');

            if (!toggle || !panel) {
                return;
            }

            const openPanel = () => {
                root.classList.add('is-open');
                toggle.setAttribute('aria-expanded', 'true');
            };

            toggle.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                if (root.classList.contains('is-open')) {
                    closePricePanel(root);
                    return;
                }
                closeAllCustomSelects();
                closeAllPricePanels(root);
                openPanel();
            });

            panel.addEventListener('click', (event) => {
                event.stopPropagation();
            });
        });

        initStickyFilterBar();
        initResponsiveGridToggles();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
        return;
    }
    init();

    document.addEventListener('click', (event) => {
        document.querySelectorAll('[data-price-filter-root].is-open').forEach((root) => {
            if (!root.contains(event.target)) {
                closePricePanel(root);
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        closeAllPricePanels();
    });
})();
