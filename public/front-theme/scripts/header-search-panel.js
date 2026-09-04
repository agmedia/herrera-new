(() => {
    const MIN_QUERY_LENGTH = 2;
    const DEBOUNCE_MS = 180;

    const init = function () {
        if (window.__headerSearchPanelInit === true) {
            return;
        }
        window.__headerSearchPanelInit = true;

        const panel = document.querySelector('[data-header-search-panel]');
        const toggles = document.querySelectorAll('[data-header-search-toggle]');
        const form = document.querySelector('[data-header-search-form]');
        const input = document.querySelector('[data-header-search-input]');

        if (!panel || !form || !input) {
            return;
        }

        const suggestions = form.querySelector('[data-header-search-suggestions]');
        const suggestionsClose = form.querySelector('[data-header-search-suggestions-close]');
        const suggestionsMeta = form.querySelector('[data-header-search-suggestions-meta]');
        const suggestionsList = form.querySelector('[data-header-search-suggestions-list]');
        const loadingState = form.querySelector('[data-header-search-loading]');
        const emptyState = form.querySelector('[data-header-search-empty]');
        const footer = form.querySelector('[data-header-search-footer]');
        const viewAllLink = form.querySelector('[data-header-search-view-all]');
        const b2bPriceLabel = String(form.dataset.autocompleteB2bLabel || 'B2B').trim();

        const autocompleteEnabled = form.dataset.autocompleteEnabled === '1'
            && !!form.dataset.autocompleteEndpoint
            && !!suggestions
            && !!suggestionsMeta
            && !!suggestionsList
            && !!loadingState
            && !!emptyState
            && !!footer
            && !!viewAllLink;

        const mobileViewport = window.matchMedia('(max-width: 1279px)');
        const isMobileViewport = function () {
            return mobileViewport.matches;
        };
        const isPersistentMobileSearch = function () {
            return isMobileViewport() && panel.hasAttribute('data-header-search-persistent');
        };

        let isOpen = !isMobileViewport() || isPersistentMobileSearch();
        let debounceId = 0;
        let activeIndex = -1;
        let abortController = null;
        let resultLinks = [];

        const ensurePanelVisible = function () {
            if (!isMobileViewport()) {
                return;
            }

            const panelTop = panel.getBoundingClientRect().top;
            const shouldScrollToTop = window.scrollY > 24 && panelTop < 0;

            if (shouldScrollToTop) {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        };

        const dismissMobileKeyboard = function () {
            if (!isMobileViewport() || document.activeElement !== input) {
                return;
            }

            input.blur();
        };

        const resetActiveLink = function () {
            resultLinks.forEach((link, index) => {
                link.classList.toggle('is-active', index === activeIndex);
            });
        };

        const closeSuggestions = function () {
            if (!autocompleteEnabled) {
                return;
            }

            if (debounceId) {
                window.clearTimeout(debounceId);
                debounceId = 0;
            }

            if (abortController) {
                abortController.abort();
                abortController = null;
            }

            activeIndex = -1;
            resultLinks = [];
            suggestions.hidden = true;
            suggestionsList.innerHTML = '';
            suggestionsMeta.textContent = '';
            loadingState.hidden = true;
            emptyState.hidden = true;
            footer.hidden = true;
        };

        suggestionsClose?.addEventListener('click', function () {
            closeSuggestions();
            input.blur();
        });

        const setMetaLabel = function (template, valueMap) {
            let label = String(template || '');
            Object.entries(valueMap).forEach(([key, value]) => {
                label = label.replace(key, value);
            });
            return label;
        };

        const buildSuggestionRow = function (item) {
            const link = document.createElement('a');
            link.href = item.url || '#';
            link.className = 'header-search-suggestion';
            link.dataset.headerSearchSuggestion = '';
            const hasImage = !!item.image_url;
            const hasPrice = !!item.price;
            link.classList.toggle('has-no-image', !hasImage);
            link.classList.toggle('has-no-price', !hasPrice);

            if (hasImage) {
                const thumb = document.createElement('span');
                thumb.className = 'header-search-suggestion-thumb';
                const image = document.createElement('img');
                image.src = item.image_url;
                image.alt = item.name || '';
                image.loading = 'lazy';
                thumb.appendChild(image);
                link.appendChild(thumb);
            }

            const copy = document.createElement('span');
            copy.className = 'header-search-suggestion-copy';

            const title = document.createElement('span');
            title.className = 'header-search-suggestion-title';
            title.textContent = item.name || '';
            copy.appendChild(title);

            if (item.brand || item.sku) {
                const meta = document.createElement('span');
                meta.className = 'header-search-suggestion-meta';

                if (item.brand) {
                    const brand = document.createElement('span');
                    brand.className = 'header-search-suggestion-brand';
                    brand.textContent = item.brand;
                    meta.appendChild(brand);
                }

                if (item.brand && item.sku) {
                    const separator = document.createElement('span');
                    separator.className = 'header-search-suggestion-meta-separator';
                    separator.setAttribute('aria-hidden', 'true');
                    separator.textContent = '•';
                    meta.appendChild(separator);
                }

                if (item.sku) {
                    const sku = document.createElement('span');
                    sku.className = 'header-search-suggestion-sku';
                    sku.textContent = item.sku;
                    meta.appendChild(sku);
                }

                copy.appendChild(meta);
            }

            link.appendChild(copy);

            if (hasPrice) {
                const prices = document.createElement('span');
                prices.className = 'header-search-suggestion-prices';

                const currentPrice = document.createElement('span');
                currentPrice.className = 'header-search-suggestion-price';
                currentPrice.textContent = item.price;
                prices.appendChild(currentPrice);

                if (item.is_b2b_price) {
                    const b2bLabel = document.createElement('span');
                    b2bLabel.className = 'header-search-suggestion-b2b';
                    b2bLabel.textContent = b2bPriceLabel;
                    prices.appendChild(b2bLabel);
                }

                if (item.has_discount && item.old_price) {
                    const oldPrice = document.createElement('span');
                    oldPrice.className = 'header-search-suggestion-old-price';
                    oldPrice.textContent = item.old_price;
                    prices.appendChild(oldPrice);
                }

                link.appendChild(prices);
            }

            return link;
        };

        const buildGroupHeading = function (label, total) {
            const heading = document.createElement('div');
            heading.className = 'header-search-group-heading';

            const title = document.createElement('span');
            title.textContent = label || '';
            heading.appendChild(title);

            if (total > 0) {
                const count = document.createElement('span');
                count.className = 'header-search-group-count';
                count.textContent = String(total);
                heading.appendChild(count);
            }

            return heading;
        };

        const buildRelatedGroup = function (groupKey, payload) {
            const items = Array.isArray(payload.items) ? payload.items : [];
            if (items.length === 0) {
                return null;
            }

            const section = document.createElement('section');
            section.className = `header-search-related-group is-${groupKey}`;
            const labelKey = groupKey === 'manufacturers'
                ? 'autocompleteManufacturersLabel'
                : `autocomplete${groupKey.charAt(0).toUpperCase()}${groupKey.slice(1)}Label`;
            section.appendChild(buildGroupHeading(form.dataset[labelKey], Number(payload.total || 0)));

            const list = document.createElement('div');
            list.className = 'header-search-related-list';

            items.forEach((item) => {
                const link = document.createElement('a');
                link.href = item.url || '#';
                link.className = 'header-search-related-suggestion';
                link.classList.toggle('is-blog', groupKey === 'blog');
                link.dataset.headerSearchSuggestion = '';
                link.textContent = item.name || '';
                list.appendChild(link);
            });

            section.appendChild(list);

            return section;
        };

        const bindSuggestionLinks = function () {
            resultLinks = Array.from(suggestionsList.querySelectorAll('[data-header-search-suggestion]'));
            resultLinks.forEach((link, index) => {
                link.dataset.index = String(index);
                link.addEventListener('mouseenter', function () {
                    activeIndex = index;
                    resetActiveLink();
                });
            });
        };

        const renderLoading = function () {
            if (!autocompleteEnabled) {
                return;
            }

            suggestions.hidden = false;
            suggestionsMeta.textContent = '';
            suggestionsList.innerHTML = '';
            loadingState.textContent = form.dataset.autocompleteLoadingLabel || '';
            loadingState.hidden = false;
            emptyState.hidden = true;
            footer.hidden = true;
            activeIndex = -1;
            resultLinks = [];
        };

        const renderResults = function (payload, query) {
            if (!autocompleteEnabled) {
                return;
            }

            suggestions.hidden = false;
            loadingState.hidden = true;
            suggestionsList.innerHTML = '';
            activeIndex = -1;

            const total = Number(payload.total || 0);
            const groups = payload.groups && typeof payload.groups === 'object'
                ? payload.groups
                : {
                    products: {
                        total,
                        items: Array.isArray(payload.items) ? payload.items : [],
                    },
                };
            const productGroup = groups.products || { total: 0, items: [] };
            const productItems = Array.isArray(productGroup.items) ? productGroup.items : [];
            const relatedGroupKeys = ['manufacturers', 'categories', 'blog'];
            const relatedSections = relatedGroupKeys
                .map((groupKey) => buildRelatedGroup(groupKey, groups[groupKey] || { total: 0, items: [] }))
                .filter(Boolean);

            if (productItems.length === 0 && relatedSections.length === 0) {
                suggestionsMeta.textContent = '';
                emptyState.textContent = setMetaLabel(form.dataset.autocompleteEmptyLabel, {
                    '__QUERY__': query,
                });
                emptyState.hidden = false;
                footer.hidden = true;
                resultLinks = [];
                return;
            }

            emptyState.hidden = true;
            suggestionsMeta.textContent = setMetaLabel(form.dataset.autocompleteResultsLabel, {
                '__COUNT__': String(total),
            });

            const grid = document.createElement('div');
            grid.className = 'header-search-suggestions-grid';
            grid.classList.toggle('has-no-products', productItems.length === 0);

            if (productItems.length > 0) {
                const productSection = document.createElement('section');
                productSection.className = 'header-search-products-group';
                productSection.appendChild(buildGroupHeading(
                    form.dataset.autocompleteProductsLabel,
                    Number(productGroup.total || 0)
                ));

                const productList = document.createElement('div');
                productList.className = 'header-search-products-list';
                productItems.forEach((item) => {
                    productList.appendChild(buildSuggestionRow(item));
                });

                productSection.appendChild(productList);
                grid.appendChild(productSection);
            }

            if (relatedSections.length > 0) {
                const relatedColumn = document.createElement('aside');
                relatedColumn.className = 'header-search-related-column';
                relatedSections.forEach((section) => {
                    relatedColumn.appendChild(section);
                });
                grid.appendChild(relatedColumn);
            }

            suggestionsList.appendChild(grid);
            bindSuggestionLinks();

            footer.hidden = productItems.length === 0;
            if (!footer.hidden) {
                const productTotal = Number(productGroup.total || 0);
                const totalSuffix = productTotal > 0 ? ` (${productTotal})` : '';
                viewAllLink.textContent = `${form.dataset.autocompleteViewAllLabel || ''}${totalSuffix}`;
                viewAllLink.href = payload.search_url || `${form.action}?q=${encodeURIComponent(query)}`;
            }
        };

        const requestAutocomplete = function () {
            if (!autocompleteEnabled || !isOpen) {
                return;
            }

            debounceId = 0;
            const query = input.value.trim();
            if (query.length < MIN_QUERY_LENGTH) {
                closeSuggestions();
                return;
            }

            renderLoading();

            if (abortController) {
                abortController.abort();
            }

            const requestController = new AbortController();
            abortController = requestController;
            const endpoint = new URL(form.dataset.autocompleteEndpoint, window.location.origin);
            endpoint.searchParams.set('q', query);

            window.fetch(endpoint.toString(), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                signal: requestController.signal,
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(`Autocomplete request failed with ${response.status}`);
                    }

                    return response.json();
                })
                .then((payload) => {
                    if (input.value.trim() !== query) {
                        return;
                    }

                    renderResults(payload, query);
                })
                .catch((error) => {
                    if (error && error.name === 'AbortError') {
                        return;
                    }

                    closeSuggestions();
                })
                .finally(() => {
                    if (abortController === requestController) {
                        abortController = null;
                    }
                });
        };

        const queueAutocomplete = function () {
            if (!autocompleteEnabled) {
                return;
            }

            if (debounceId) {
                window.clearTimeout(debounceId);
            }

            debounceId = window.setTimeout(requestAutocomplete, DEBOUNCE_MS);
        };

        const openPanel = function () {
            panel.classList.add('is-open');
            isOpen = true;

            ensurePanelVisible();

            window.setTimeout(function () {
                input.focus();
                if (autocompleteEnabled && input.value.trim().length >= MIN_QUERY_LENGTH) {
                    requestAutocomplete();
                }
            }, isMobileViewport() ? 260 : 120);
        };

        const closePanel = function () {
            closeSuggestions();

            if (isMobileViewport() && !isPersistentMobileSearch()) {
                panel.classList.remove('is-open');
                isOpen = false;
                return;
            }

            isOpen = true;
        };

        toggles.forEach(function (toggle) {
            toggle.addEventListener('click', function () {
                if (isOpen) {
                    closePanel();
                    return;
                }

                openPanel();
            });
        });

        input.addEventListener('focus', function () {
            if (!isOpen) {
                openPanel();
                return;
            }

            if (autocompleteEnabled && input.value.trim().length >= MIN_QUERY_LENGTH) {
                requestAutocomplete();
            }
        });

        input.addEventListener('input', function () {
            queueAutocomplete();
        });

        input.addEventListener('keydown', function (event) {
            if (!autocompleteEnabled || suggestions.hidden || resultLinks.length === 0) {
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                activeIndex = Math.min(resultLinks.length - 1, activeIndex + 1);
                resetActiveLink();
                return;
            }

            if (event.key === 'ArrowUp') {
                event.preventDefault();
                activeIndex = Math.max(0, activeIndex - 1);
                resetActiveLink();
                return;
            }

            if (event.key === 'Enter' && activeIndex >= 0 && resultLinks[activeIndex]) {
                event.preventDefault();
                window.location.assign(resultLinks[activeIndex].href);
            }
        });

        form.addEventListener('submit', function () {
            closeSuggestions();
        });

        if (autocompleteEnabled) {
            suggestionsList.addEventListener('touchmove', dismissMobileKeyboard, { passive: true });
            suggestionsList.addEventListener('scroll', dismissMobileKeyboard, { passive: true });
            suggestionsList.addEventListener('wheel', dismissMobileKeyboard, { passive: true });
        }

        document.addEventListener('click', function (event) {
            const target = event.target;
            if (isOpen && !panel.contains(target) && !Array.from(toggles).some((toggle) => toggle.contains(target))) {
                closePanel();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && isOpen) {
                closePanel();
            }
        });

        const syncViewportState = function () {
            if (!isMobileViewport()) {
                panel.classList.remove('is-open');
                isOpen = true;
                return;
            }

            isOpen = isPersistentMobileSearch() || panel.classList.contains('is-open');
        };

        if (typeof mobileViewport.addEventListener === 'function') {
            mobileViewport.addEventListener('change', syncViewportState);
        } else {
            mobileViewport.addListener(syncViewportState);
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
        return;
    }

    init();
})();
