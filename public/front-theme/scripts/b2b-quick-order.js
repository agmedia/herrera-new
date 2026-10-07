(function () {
    'use strict';

    const currency = new Intl.NumberFormat('hr-HR', {
        style: 'currency',
        currency: 'EUR'
    });

    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    };

    const imageNode = (item, className) => {
        const wrapper = element('span', className);
        if (item.image_url) {
            const image = document.createElement('img');
            image.src = item.image_url;
            image.alt = '';
            image.loading = 'lazy';
            image.decoding = 'async';
            wrapper.appendChild(image);
        } else {
            wrapper.appendChild(element('span', '', 'Bez slike'));
        }

        return wrapper;
    };

    document.querySelectorAll('[data-quick-order-builder]').forEach((builder) => {
        const itemPriceText = (item, amount) => currency.format(amount)
            + (item.display_includes_tax === false ? ` ${builder.dataset.priceExcludesTaxLabel || ''}` : '');
        const searchInput = builder.querySelector('[data-quick-order-search]');
        const results = builder.querySelector('[data-quick-order-results]');
        const spinner = builder.querySelector('[data-quick-order-spinner]');
        const lines = builder.querySelector('[data-quick-order-lines]');
        const empty = builder.querySelector('[data-quick-order-empty]');
        const footer = builder.querySelector('[data-quick-order-footer]');
        const count = builder.querySelector('[data-quick-order-count]');
        const total = builder.querySelector('[data-quick-order-total]');
        const submit = builder.querySelector('[data-quick-order-submit]');
        const initial = builder.querySelector('[data-quick-order-initial]');
        const csrfToken = builder.querySelector('input[name="_token"]')?.value || '';
        const draftStatus = builder.querySelector('[data-quick-order-draft-status]');
        const announcement = builder.querySelector('[data-quick-order-announcement]');
        const clear = builder.querySelector('[data-quick-order-clear]');
        const undo = builder.querySelector('[data-quick-order-undo]');
        const selected = new Map();
        let searchTimer = null;
        let request = null;
        let syncTask = null;
        let clearedItems = [];
        let submitting = false;
        let syncPending = false;
        let selectionVersion = 0;
        let activeResult = -1;
        let currentResults = [];

        const labels = {
            searching: builder.dataset.searchingLabel,
            emptySearch: builder.dataset.emptySearchLabel,
            minSearch: builder.dataset.minSearchLabel,
            emptySelection: builder.dataset.emptySelectionLabel,
            remove: builder.dataset.removeLabel,
            b2b: builder.dataset.b2bLabel
        };

        const minimumSearchLength = Number(builder.dataset.minSearchLength || 2);

        const normalizeItem = (item) => {
            const minimum = Math.max(1, Number(item.minimum_quantity || 1));
            const step = Math.max(1, Number(item.quantity_step || 1));
            const available = Math.max(0, Math.min(999, Number(item.maximum_quantity ?? 999)));
            const maximum = available < minimum ? 0 : minimum + Math.floor((available - minimum) / step) * step;
            let quantity = Math.max(minimum, Math.floor(Number(item.quantity || minimum)));
            quantity = Math.min(maximum, minimum + Math.ceil((quantity - minimum) / step) * step);

            return {
                ...item,
                key: String(item.key || `${item.product_id}:${item.product_option_value_id || 0}`),
                minimum_quantity: minimum,
                quantity_step: step,
                maximum_quantity: maximum,
                quantity,
                unit_price: Number(item.unit_price || 0),
                is_b2b_price: Boolean(item.is_b2b_price)
            };
        };

        const selectionItems = () => Array.from(selected.values()).map((item) => ({
            product_id: item.product_id,
            product_option_value_id: item.product_option_value_id || null,
            quantity: item.quantity
        }));

        const saveBrowserFallback = () => {
            selectionVersion++;
            if (!builder.dataset.storageKey) return;

            try {
                window.sessionStorage.setItem(
                    builder.dataset.storageKey,
                    JSON.stringify(Array.from(selected.values()))
                );
            } catch (error) {
                // Storage restrictions should not block the quick-order form.
            }
        };

        const loadBrowserFallback = () => {
            if (!builder.dataset.storageKey) return null;

            try {
                const stored = window.sessionStorage.getItem(builder.dataset.storageKey);
                if (stored === null) return null;

                const items = JSON.parse(stored);
                return Array.isArray(items) ? items : null;
            } catch (error) {
                return null;
            }
        };

        const clearBrowserFallback = () => {
            if (!builder.dataset.storageKey) return;

            try {
                window.sessionStorage.removeItem(builder.dataset.storageKey);
            } catch (error) {
                // Storage restrictions should not block the quick-order form.
            }
        };

        const persistSelection = () => {
            if (!builder.dataset.syncUrl || !csrfToken) return Promise.resolve();
            syncPending = true;
            if (syncTask) return syncTask;

            syncTask = (async () => {
                do {
                    syncPending = false;
                    const syncedVersion = selectionVersion;
                    const syncController = new AbortController();
                    const syncTimeout = window.setTimeout(() => syncController.abort(), 10000);
                    if (draftStatus) draftStatus.textContent = builder.dataset.savingLabel;
                    try {
                        const response = await fetch(builder.dataset.syncUrl, {
                            method: 'PUT',
                            headers: {
                                Accept: 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            credentials: 'same-origin',
                            keepalive: true,
                            signal: syncController.signal,
                            body: JSON.stringify({items: selectionItems()})
                        });
                        if (!response.ok) throw new Error(`Draft sync failed with status ${response.status}`);
                        if (syncedVersion === selectionVersion) {
                            clearBrowserFallback();
                            if (draftStatus) draftStatus.textContent = builder.dataset.savedLabel;
                        }
                    } catch (error) {
                        if (draftStatus) draftStatus.textContent = builder.dataset.saveErrorLabel;
                    } finally {
                        window.clearTimeout(syncTimeout);
                    }
                } while (syncPending);
            })().finally(() => { syncTask = null; });

            return syncTask;
        };

        const hideResults = () => {
            results.hidden = true;
            results.replaceChildren();
            currentResults = [];
            activeResult = -1;
            searchInput.setAttribute('aria-expanded', 'false');
            searchInput.removeAttribute('aria-activedescendant');
        };

        const showMessage = (message) => {
            results.replaceChildren(element('p', 'quick-order-results-message', message));
            results.hidden = false;
            searchInput.setAttribute('aria-expanded', 'true');
            currentResults = [];
            activeResult = -1;
            searchInput.removeAttribute('aria-activedescendant');
        };

        const activateResult = (index) => {
            const buttons = Array.from(results.querySelectorAll('[data-quick-order-result]'));
            if (buttons.length === 0) return;
            activeResult = Math.max(0, Math.min(buttons.length - 1, index));
            buttons.forEach((button, buttonIndex) => {
                button.classList.toggle('is-active', buttonIndex === activeResult);
                button.setAttribute('aria-selected', buttonIndex === activeResult ? 'true' : 'false');
            });
            const active = buttons[activeResult];
            searchInput.setAttribute('aria-activedescendant', active.id);
            active.scrollIntoView({block: 'nearest'});
        };

        const addItem = (rawItem, options = {}) => {
            const item = normalizeItem(rawItem);
            if (item.maximum_quantity < item.minimum_quantity) return false;
            const existing = selected.get(item.key);
            if (!existing && selected.size >= 100) {
                if (announcement) announcement.textContent = builder.dataset.limitLabel;
                return false;
            }
            if (existing) {
                const addedQuantity = options.combine ? item.quantity : existing.quantity_step;
                existing.quantity = normalizeItem({...existing, quantity: existing.quantity + addedQuantity}).quantity;
                selected.set(item.key, existing);
            } else {
                selected.set(item.key, item);
            }
            if (announcement) announcement.textContent = `${item.name}: ${builder.dataset.addedLabel}`;
            if (!options.batch) {
                searchInput.value = '';
                if (request) request.abort();
                hideResults();
                render();
                saveBrowserFallback();
                persistSelection();
                searchInput.focus();
            }
            return true;
        };

        const renderSearchResults = (items) => {
            results.replaceChildren();
            currentResults = items.map(normalizeItem);
            activeResult = -1;

            if (currentResults.length === 0) {
                showMessage(labels.emptySearch);
                return;
            }

            currentResults.forEach((item, index) => {
                const button = element('button', 'quick-order-result');
                button.type = 'button';
                button.id = `quick-order-result-${index}`;
                button.setAttribute('role', 'option');
                button.setAttribute('aria-selected', 'false');
                button.dataset.quickOrderResult = String(index);

                const copy = element('span', 'quick-order-result-copy');
                copy.appendChild(element('span', 'quick-order-result-name', item.name));
                if (item.option_label) {
                    copy.appendChild(element('span', 'quick-order-result-option', item.option_label));
                }
                const meta = [item.code ? `Šifra: ${item.code}` : '', item.sku ? `SKU: ${item.sku}` : '']
                    .filter(Boolean)
                    .join(' · ');
                copy.appendChild(element('span', 'quick-order-result-meta', meta));

                const price = element('span', 'quick-order-result-price', itemPriceText(item, item.unit_price));
                if (item.is_b2b_price) {
                    price.appendChild(element('span', 'quick-order-b2b-badge', labels.b2b));
                }

                button.appendChild(imageNode(item, 'quick-order-result-image'));
                button.appendChild(copy);
                button.appendChild(price);
                results.appendChild(button);
            });

            results.hidden = false;
            searchInput.setAttribute('aria-expanded', 'true');
        };

        const performSearch = async () => {
            const query = searchInput.value.trim();
            if (query.length < minimumSearchLength) {
                if (query.length > 0) showMessage(labels.minSearch);
                else hideResults();
                return;
            }

            if (request) request.abort();
            const currentRequest = new AbortController();
            request = currentRequest;
            spinner.hidden = false;
            showMessage(labels.searching);

            try {
                const url = new URL(builder.dataset.searchUrl, window.location.origin);
                url.searchParams.set('q', query);
                const response = await fetch(url.toString(), {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin',
                    signal: currentRequest.signal
                });

                if (!response.ok) throw new Error(`Search failed with status ${response.status}`);
                const payload = await response.json();
                if (searchInput.value.trim() !== query) return;
                renderSearchResults(Array.isArray(payload.items) ? payload.items : []);
            } catch (error) {
                if (error.name !== 'AbortError' && searchInput.value.trim() === query) showMessage(builder.dataset.searchErrorLabel);
            } finally {
                if (request === currentRequest) spinner.hidden = true;
            }
        };

        const quantityControl = (item) => {
            const wrapper = element('div', 'quick-order-quantity-control');
            const decrement = element('button', '', '−');
            decrement.type = 'button';
            decrement.setAttribute('aria-label', `Smanji količinu: ${item.name}`);
            decrement.disabled = item.quantity <= item.minimum_quantity;

            const input = document.createElement('input');
            input.type = 'number';
            input.min = String(item.minimum_quantity);
            input.max = String(item.maximum_quantity);
            input.step = String(item.quantity_step);
            input.value = String(item.quantity);
            input.inputMode = 'numeric';
            input.setAttribute('aria-label', `Količina: ${item.name}`);

            const increment = element('button', '', '+');
            increment.type = 'button';
            increment.setAttribute('aria-label', `Povećaj količinu: ${item.name}`);
            increment.disabled = item.quantity >= item.maximum_quantity;

            const setQuantity = (value) => {
                const normalized = Math.max(
                    item.minimum_quantity,
                    Math.min(item.maximum_quantity, Number(value || item.minimum_quantity))
                );
                item.quantity = item.minimum_quantity
                    + Math.ceil((normalized - item.minimum_quantity) / item.quantity_step) * item.quantity_step;
                item.quantity = Math.max(item.minimum_quantity, Math.min(item.maximum_quantity, item.quantity));
                selected.set(item.key, item);
                render();
                saveBrowserFallback();
                persistSelection();
            };

            decrement.addEventListener('click', () => setQuantity(item.quantity - item.quantity_step));
            increment.addEventListener('click', () => setQuantity(item.quantity + item.quantity_step));
            input.addEventListener('change', () => setQuantity(input.value));

            wrapper.append(decrement, input, increment);
            return wrapper;
        };

        const hiddenInput = (name, value) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value === null || value === undefined ? '' : String(value);
            return input;
        };

        const render = () => {
            lines.replaceChildren();
            let itemQuantity = 0;
            let grandTotal = 0;

            Array.from(selected.values()).forEach((item, index) => {
                itemQuantity += item.quantity;
                grandTotal += item.unit_price * item.quantity;

                const row = element('div', 'quick-order-line');
                row.dataset.quickOrderLine = item.key;

                const product = element('div', 'quick-order-product');
                const copy = element('div', 'quick-order-line-copy');
                copy.appendChild(element('span', 'quick-order-line-name', item.name));
                if (item.option_label) {
                    copy.appendChild(element('span', 'quick-order-line-option', item.option_label));
                }
                const meta = [item.code ? `Šifra: ${item.code}` : '', item.sku ? `SKU: ${item.sku}` : '']
                    .filter(Boolean)
                    .join(' · ');
                copy.appendChild(element('span', 'quick-order-line-meta', meta));
                product.append(imageNode(item, 'quick-order-line-image'), copy);

                const unitPrice = element('div', 'quick-order-line-price');
                unitPrice.appendChild(element('small', '', 'Jedinična cijena'));
                const unitPriceValue = element('span', '', itemPriceText(item, item.unit_price));
                if (item.is_b2b_price) {
                    unitPriceValue.appendChild(element('span', 'quick-order-b2b-badge', labels.b2b));
                }
                unitPrice.appendChild(unitPriceValue);

                const quantity = element('div', 'quick-order-line-quantity');
                quantity.appendChild(element('label', '', 'Količina'));
                quantity.appendChild(quantityControl(item));

                const lineTotal = element('div', 'quick-order-line-total');
                lineTotal.appendChild(element('small', '', 'Ukupno'));
                lineTotal.appendChild(element('span', '', itemPriceText(item, item.unit_price * item.quantity)));

                const remove = element('button', 'quick-order-remove', '×');
                remove.type = 'button';
                remove.title = labels.remove;
                remove.setAttribute('aria-label', `${labels.remove}: ${item.name}`);
                remove.addEventListener('click', () => {
                    selected.delete(item.key);
                    render();
                    saveBrowserFallback();
                    persistSelection();
                });

                row.append(product, unitPrice, quantity, lineTotal, remove);
                row.append(
                    hiddenInput(`items[${index}][product_id]`, item.product_id),
                    hiddenInput(`items[${index}][product_option_value_id]`, item.product_option_value_id),
                    hiddenInput(`items[${index}][identifier]`, item.identifier),
                    hiddenInput(`items[${index}][quantity]`, item.quantity)
                );
                lines.appendChild(row);
            });

            const hasItems = selected.size > 0;
            empty.hidden = hasItems;
            lines.hidden = !hasItems;
            footer.hidden = !hasItems;
            submit.disabled = !hasItems;
            count.textContent = `${selected.size} ${selected.size === 1 ? 'stavka' : 'stavki'} · ${itemQuantity} kom`;
            if (clear) clear.hidden = !hasItems;
            if (undo) undo.hidden = clearedItems.length === 0;
            total.textContent = currency.format(grandTotal);
        };

        searchInput.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            if (request) request.abort();
            spinner.hidden = true;
            hideResults();
            searchTimer = window.setTimeout(performSearch, 250);
        });

        searchInput.addEventListener('focus', () => {
            if (searchInput.value.trim().length >= minimumSearchLength) performSearch();
        });

        searchInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                if (!results.hidden && currentResults.length > 0) addItem(currentResults[Math.max(0, activeResult)]);
                else performSearch();
                return;
            }
            if (results.hidden) return;

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                activateResult(activeResult + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                activateResult(activeResult <= 0 ? currentResults.length - 1 : activeResult - 1);

            } else if (event.key === 'Escape') {
                hideResults();
            }
        });

        results.addEventListener('click', (event) => {
            const button = event.target.closest('[data-quick-order-result]');
            if (!button) return;
            const item = currentResults[Number(button.dataset.quickOrderResult)];
            if (item) addItem(item);
        });

        document.addEventListener('click', (event) => {
            if (!builder.contains(event.target)) hideResults();
        });

        document.addEventListener('click', (event) => {
            const suggestion = event.target.closest('[data-quick-order-query]');
            if (!suggestion) return;
            searchInput.focus();
            searchInput.value = suggestion.dataset.quickOrderQuery || '';
            performSearch();
            searchInput.scrollIntoView({behavior: 'smooth', block: 'center'});
        });

        clear?.addEventListener('click', () => {
            clearedItems = Array.from(selected.values()).map((item) => ({...item}));
            selected.clear();
            render();
            saveBrowserFallback();
            persistSelection();
            if (announcement) announcement.textContent = builder.dataset.clearedLabel;
            undo?.focus();
        });
        undo?.addEventListener('click', () => {
            clearedItems.forEach((item) => {
                if (selected.has(item.key) || selected.size < 100) selected.set(item.key, item);
            });
            clearedItems = [];
            render();
            saveBrowserFallback();
            persistSelection();
            searchInput.focus();
        });

        const suggestionData = builder.querySelector('[data-quick-order-suggestions]');
        const suggestionPanel = builder.querySelector('[data-quick-order-suggestions-panel]');
        const suggestionItems = builder.querySelector('[data-quick-order-suggestion-items]');
        const suggestionTabs = Array.from(builder.querySelectorAll('[data-quick-order-suggestion-tab]'));
        let suggestions = {};
        try { suggestions = JSON.parse(suggestionData?.textContent || '{}'); } catch (error) { /* Empty suggestions are safe. */ }
        const renderSuggestions = (key) => {
            if (!suggestionItems) return;
            suggestionItems.replaceChildren();
            suggestionItems.setAttribute('aria-labelledby', `quick-order-tab-${key}`);
            suggestionTabs.forEach((tab) => {
                const active = tab.dataset.quickOrderSuggestionTab === key;
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
                tab.tabIndex = active ? 0 : -1;
            });
            const items = Array.isArray(suggestions[key]) ? suggestions[key] : [];
            if (items.length === 0) {
                suggestionItems.appendChild(element('p', 'quick-order-results-message', 'Još nema artikala. Pronađite ih pretraživanjem iznad.'));
                return;
            }
            items.forEach((item) => {
                const button = element('button', 'quick-order-suggestion');
                button.type = 'button';
                const copy = element('span', 'quick-order-suggestion-copy');
                copy.appendChild(element('strong', '', item.name));
                if (item.option_label) copy.appendChild(element('small', '', item.option_label));
                copy.appendChild(element('small', '', item.identifier));
                const action = element('span', 'quick-order-suggestion-action', item.requires_variant ? 'Odaberi varijantu →' : '+ Dodaj');
                button.append(imageNode(item, 'quick-order-result-image'), copy, action);
                button.addEventListener('click', () => {
                    if (item.requires_variant) {
                        searchInput.value = item.code;
                        searchInput.focus();
                        performSearch();
                    } else addItem(item);
                });
                suggestionItems.appendChild(button);
            });
        };
        suggestionTabs.forEach((tab, index) => {
            tab.addEventListener('click', () => renderSuggestions(tab.dataset.quickOrderSuggestionTab));
            tab.addEventListener('keydown', (event) => {
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                event.preventDefault();
                const nextIndex = event.key === 'Home' ? 0 : event.key === 'End' ? suggestionTabs.length - 1
                    : (index + (event.key === 'ArrowRight' ? 1 : -1) + suggestionTabs.length) % suggestionTabs.length;
                const next = suggestionTabs[nextIndex];
                renderSuggestions(next.dataset.quickOrderSuggestionTab);
                next.focus();
            });
        });
        const firstSuggestionGroup = ['frequent', 'favorites', 'recent'].find((key) => suggestions[key]?.length > 0);
        if (suggestionPanel && firstSuggestionGroup) {
            suggestionPanel.hidden = false;
            renderSuggestions(firstSuggestionGroup);
        }

        const bulkLines = builder.querySelector('[data-quick-order-bulk-lines]');
        const importButton = builder.querySelector('[data-quick-order-import]');
        const importFeedback = builder.querySelector('[data-quick-order-import-feedback]');
        importButton?.addEventListener('click', async () => {
            if (!bulkLines?.value.trim() || !builder.dataset.resolveUrl) return;
            importButton.disabled = true;
            importFeedback.hidden = false;
            importFeedback.replaceChildren(element('p', '', builder.dataset.importingLabel));
            const originalLines = bulkLines.value.split(/\r?\n/).filter((line) => line.trim() !== '');
            try {
                const response = await fetch(builder.dataset.resolveUrl, {
                    method: 'POST',
                    headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest'},
                    credentials: 'same-origin',
                    body: JSON.stringify({lines: bulkLines.value})
                });
                const payload = await response.json();
                if (!response.ok) throw new Error(payload.errors?.lines?.[0] || payload.message || builder.dataset.importErrorLabel);
                let addedCount = 0;
                const overflow = [];
                const overflowItems = [];
                (payload.items || []).forEach((item) => {
                    if (addItem(item, {batch: true, combine: true})) addedCount++;
                    else {
                        overflow.push(`${item.identifier}: ${builder.dataset.limitLabel}`);
                        overflowItems.push(`${item.identifier}; ${item.quantity}`);
                    }
                });
                render();
                saveBrowserFallback();
                persistSelection();
                importFeedback.replaceChildren(element('p', 'quick-order-import-summary', `${builder.dataset.importSuccessLabel}: ${addedCount}.`));
                const issues = [...(payload.errors || []), ...(payload.warnings || [])];
                if (issues.length || overflow.length) {
                    const list = element('ul');
                    issues.forEach((issue) => list.appendChild(element('li', '', `${issue.line}. ${issue.identifier}: ${issue.message}`)));
                    overflow.forEach((message) => list.appendChild(element('li', '', message)));
                    importFeedback.appendChild(list);
                }
                bulkLines.value = [...(payload.errors || []).map((issue) => originalLines[issue.line - 1]).filter(Boolean), ...overflowItems].join('\n');
            } catch (error) {
                importFeedback.replaceChildren(element('p', 'quick-order-import-error', error.message || builder.dataset.importErrorLabel));
            } finally {
                importButton.disabled = false;
            }
        });

        builder.querySelector('[data-quick-order-form]')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (submitting || selected.size === 0) return;
            submitting = true;
            submit.disabled = true;
            await persistSelection();
            clearBrowserFallback();
            event.target.submit();
        });

        const browserFallback = loadBrowserFallback();

        try {
            const items = browserFallback ?? JSON.parse(initial ? initial.textContent : '[]');
            if (Array.isArray(items)) {
                items.forEach((item) => {
                    const normalized = normalizeItem(item);
                    if (normalized.maximum_quantity >= normalized.minimum_quantity && selected.size < 100) selected.set(normalized.key, normalized);
                });
            }
        } catch (error) {
            // Invalid restored data should not block the form.
        }

        render();
        if (browserFallback !== null) persistSelection();
        else if (selected.size && draftStatus) draftStatus.textContent = builder.dataset.savedLabel;
        if (builder.dataset.initialQuery) {
            searchInput.value = builder.dataset.initialQuery;
            performSearch();
            searchInput.focus();
        }
    });
})();
