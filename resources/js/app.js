import './bootstrap';
import TomSelect from 'tom-select';
import 'tom-select/dist/css/tom-select.css';
import Quill from 'quill';
import 'quill/dist/quill.snow.css';
import Cropper from 'cropperjs';
import 'cropperjs/dist/cropper.css';
import Chart from 'chart.js/auto';

let aceLoaderPromise = null;
let aceInlineFailureNotified = false;

const loadAce = async () => {
    if (aceLoaderPromise) {
        return aceLoaderPromise;
    }

    aceLoaderPromise = (async () => {
        const aceModule = await import('ace-builds/src-noconflict/ace');
        const resolvedAce = aceModule.default ?? aceModule;
        const ace = resolvedAce?.default ?? resolvedAce;

        if (typeof window !== 'undefined' && ace) {
            window.ace = ace;
        }

        await import('ace-builds/src-noconflict/ext-language_tools');
        await import('ace-builds/src-noconflict/mode-html');
        await import('ace-builds/src-noconflict/theme-tomorrow_night');

        const readyAce = (typeof window !== 'undefined' ? window.ace : null) || ace;
        if (!readyAce || typeof readyAce.edit !== 'function') {
            throw new Error('Ace core failed to initialize.');
        }

        return readyAce;
    })()
        .catch((error) => {
            aceLoaderPromise = null;
            throw error;
        });

    return aceLoaderPromise;
};

const initAdminSidebar = () => {
    const sidebar = document.getElementById('admin-sidebar');
    const openButton = document.getElementById('admin-sidebar-open');
    const backdrop = document.getElementById('admin-sidebar-backdrop');

    if (!(sidebar instanceof HTMLElement) || !(openButton instanceof HTMLButtonElement) || !(backdrop instanceof HTMLButtonElement)) {
        return;
    }

    if (sidebar.dataset.adminSidebarReady === '1') {
        return;
    }
    sidebar.dataset.adminSidebarReady = '1';

    const desktopQuery = window.matchMedia('(min-width: 768px)');
    const desktopStorageKey = 'termol.admin.sidebar.collapsed';
    const openLabel = openButton.dataset.labelOpen || 'Show navigation';
    const closeLabel = openButton.dataset.labelClose || 'Hide navigation';

    const updateToggleButton = () => {
        const isExpanded = desktopQuery.matches
            ? !document.documentElement.classList.contains('admin-sidebar-collapsed')
            : sidebar.classList.contains('is-open');
        const label = isExpanded ? closeLabel : openLabel;

        openButton.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
        openButton.setAttribute('aria-label', label);
        openButton.setAttribute('title', label);
    };

    const persistedDesktopState = () => {
        try {
            return window.localStorage.getItem(desktopStorageKey) === '1';
        } catch {
            return false;
        }
    };

    const setDesktopCollapsed = (collapsed, persist = true) => {
        const nextCollapsed = Boolean(collapsed) && desktopQuery.matches;

        document.documentElement.classList.toggle('admin-sidebar-collapsed', nextCollapsed);

        if (persist) {
            try {
                window.localStorage.setItem(desktopStorageKey, collapsed ? '1' : '0');
            } catch {
                // The toggle still works when browser storage is unavailable.
            }
        }

        updateToggleButton();
    };

    const setOpen = (open) => {
        const nextOpen = Boolean(open) && !desktopQuery.matches;

        sidebar.classList.toggle('is-open', nextOpen);
        backdrop.classList.toggle('is-open', nextOpen);
        backdrop.hidden = !nextOpen;
        document.body.classList.toggle('admin-sidebar-open', nextOpen);
        updateToggleButton();

        if (nextOpen) {
            sidebar.focus({ preventScroll: true });
        }
    };

    openButton.addEventListener('click', () => {
        if (desktopQuery.matches) {
            setDesktopCollapsed(!document.documentElement.classList.contains('admin-sidebar-collapsed'));

            return;
        }

        setOpen(!sidebar.classList.contains('is-open'));
    });

    backdrop.addEventListener('click', () => setOpen(false));

    document.querySelectorAll('[data-admin-sidebar-close]').forEach((button) => {
        button.addEventListener('click', () => setOpen(false));
    });

    sidebar.querySelectorAll('a[href]').forEach((link) => {
        link.addEventListener('click', () => setOpen(false));
    });

    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
            setOpen(false);
        }
    });

    desktopQuery.addEventListener('change', () => {
        if (desktopQuery.matches) {
            setOpen(false);
            setDesktopCollapsed(persistedDesktopState(), false);
        } else {
            document.documentElement.classList.remove('admin-sidebar-collapsed');
            updateToggleButton();
        }
    });

    if (desktopQuery.matches) {
        setDesktopCollapsed(persistedDesktopState(), false);
    } else {
        updateToggleButton();
    }
};

const initAceLauncher = () => {
    const overlay = document.getElementById('admin-ace-overlay');
    const editorRoot = document.getElementById('admin-ace-editor');
    const titleNode = document.getElementById('admin-ace-title');
    const closeButton = document.getElementById('admin-ace-close');
    const cancelButton = document.getElementById('admin-ace-cancel');
    const applyButton = document.getElementById('admin-ace-apply');

    if (!overlay || !editorRoot || !closeButton || !cancelButton || !applyButton) {
        return;
    }

    if (overlay.dataset.aceLauncherReady === '1') {
        return;
    }
    overlay.dataset.aceLauncherReady = '1';

    let editor = null;
    let targetTextarea = null;
    let openNonce = 0;

    const ensureEditor = async () => {
        if (editor) {
            return editor;
        }

        const ace = await loadAce();
        editor = ace.edit(editorRoot);
        editor.session.setMode('ace/mode/html');
        editor.setTheme('ace/theme/tomorrow_night');
        editor.setOptions({
            fontSize: '13px',
            showPrintMargin: false,
            useSoftTabs: true,
            tabSize: 2,
            enableBasicAutocompletion: true,
            enableLiveAutocompletion: true,
        });
        editor.session.setUseWorker(false);

        return editor;
    };

    const close = () => {
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        targetTextarea = null;
    };

    const open = async (textarea, label) => {
        const requestNonce = ++openNonce;
        targetTextarea = textarea;
        titleNode.textContent = label || 'HTML Editor';
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');

        try {
            const loadedEditor = await ensureEditor();
            if (requestNonce !== openNonce || !overlay.classList.contains('is-open')) {
                return;
            }

            loadedEditor.setValue(textarea.value || '', -1);
            setTimeout(() => loadedEditor.focus(), 0);
        } catch (error) {
            console.error('Failed to load Ace editor', error);
            close();
            window.dispatchEvent(new CustomEvent('admin:notify', {
                detail: { type: 'danger', message: 'Ace editor failed to load.' },
            }));
        }
    };

    const apply = () => {
        if (!targetTextarea || !editor) {
            close();
            return;
        }

        const value = editor.getValue();
        if (targetTextarea.value !== value) {
            targetTextarea.value = value;
            targetTextarea.dispatchEvent(new Event('input', { bubbles: true }));
            targetTextarea.dispatchEvent(new Event('change', { bubbles: true }));
        }

        close();
    };

    const closeIfNoLaunchButtons = () => {
        if (!document.querySelector('[data-ace-open][data-ace-target]')) {
            close();
        }
    };

    const bindLaunchButtons = () => {
        const buttons = document.querySelectorAll('[data-ace-open][data-ace-target]');
        if (buttons.length === 0) {
            closeIfNoLaunchButtons();
        }

        buttons.forEach((button) => {
            if (button.dataset.aceBound === '1') {
                return;
            }
            button.dataset.aceBound = '1';

            button.addEventListener('click', async () => {
                const targetId = button.getAttribute('data-ace-target');
                if (!targetId) return;

                const textarea = document.getElementById(targetId);
                if (!(textarea instanceof HTMLTextAreaElement)) {
                    return;
                }

                await open(textarea, button.getAttribute('data-ace-label') || 'HTML Editor');
            });
        });
    };

    closeButton.addEventListener('click', close);
    cancelButton.addEventListener('click', close);
    applyButton.addEventListener('click', apply);

    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
            close();
        }
    });

    window.addEventListener('keydown', (event) => {
        if (!overlay.classList.contains('is-open')) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            close();
        }

        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
            event.preventDefault();
            apply();
        }
    });

    bindLaunchButtons();

    const observer = new MutationObserver(() => {
        bindLaunchButtons();
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
    });

    document.addEventListener('livewire:navigated', closeIfNoLaunchButtons);
};

const initTomSelect = () => {
    if (!document.body) {
        return;
    }

    const selectSelector = 'select[data-tom-select], select.admin-select:not([multiple])';

    const selectSignature = (element) => JSON.stringify(Array.from(element.options).map((option, index) => {
        const group = option.parentElement instanceof HTMLOptGroupElement
            ? option.parentElement
            : null;

        return [
            index,
            option.value,
            option.text,
            option.disabled,
            option.selected,
            group?.label || '',
            Boolean(group?.disabled),
        ];
    }));

    const destroyInstance = (tom) => {
        if (!tom || tom.__adminDestroying === true) {
            return;
        }

        tom.__adminDestroying = true;
        const element = tom.input instanceof HTMLSelectElement ? tom.input : null;
        const currentMarkup = element?.innerHTML ?? null;
        const selectedIndexes = element
            ? Array.from(element.options)
                .map((option, index) => (option.selected ? index : -1))
                .filter((index) => index >= 0)
            : [];
        const disabled = element?.disabled;
        const readOnly = element?.readOnly;

        if (tom.wrapper instanceof HTMLElement) {
            delete tom.wrapper.__adminTomSelectInstance;
        }

        try {
            tom.destroy();
        } catch (_error) {
            // The node may already be partially detached by a Livewire morph.
        }

        if (element) {
            // Tom Select restores the markup captured at initialization when it is
            // destroyed. Keep the newer server-rendered options/value instead.
            if (element.isConnected && currentMarkup !== null) {
                element.innerHTML = currentMarkup;
                Array.from(element.options).forEach((option, index) => {
                    option.selected = selectedIndexes.includes(index);
                });
                element.disabled = Boolean(disabled);
                element.readOnly = Boolean(readOnly);
            }

            delete element.tomselect;
            delete element.dataset.tomSelectBound;
        }
    };

    const destroyAll = (root) => {
        if (!(root instanceof HTMLElement) && !(root instanceof HTMLSelectElement)) {
            return;
        }

        const instances = new Set();
        const collectSelect = (select) => {
            if (select instanceof HTMLSelectElement && select.tomselect) {
                instances.add(select.tomselect);
            }
        };
        const collectWrapper = (wrapper) => {
            if (wrapper instanceof HTMLElement && wrapper.__adminTomSelectInstance) {
                instances.add(wrapper.__adminTomSelectInstance);
            }
        };

        collectSelect(root);
        collectWrapper(root);
        root.querySelectorAll?.(selectSelector).forEach(collectSelect);
        root.querySelectorAll?.('.ts-wrapper').forEach(collectWrapper);
        instances.forEach(destroyInstance);
    };

    const syncInstance = (element, tom) => {
        const signature = selectSignature(element);
        if (tom.__adminSelectSignature === signature) {
            return true;
        }

        try {
            // sync() only adds options. Clear the internal option/optgroup stores
            // first so removed or renamed server options cannot remain visible.
            tom.clearOptions(() => false);
            tom.clearOptionGroups();
            tom.sync();

            if (element.disabled && !tom.isDisabled) {
                tom.disable();
            } else if (!element.disabled && tom.isDisabled) {
                tom.enable();
            }

            tom.__adminSelectSignature = selectSignature(element);

            return true;
        } catch (_error) {
            return false;
        }
    };

    const buildTypeRender = () => ({
        option(data, escape) {
            const value = escape(String(data.value ?? ''));
            const label = escape(String(data.text ?? data.label ?? data.value ?? ''));
            return `<div class="tom-type-option"><span class="tom-type-chip tom-type-chip--${value}"></span><span>${label}</span></div>`;
        },
        item(data, escape) {
            const value = escape(String(data.value ?? ''));
            const label = escape(String(data.text ?? data.label ?? data.value ?? ''));
            return `<div class="tom-type-option"><span class="tom-type-chip tom-type-chip--${value}"></span><span>${label}</span></div>`;
        },
    });

    const bindElement = (element) => {
        if (!(element instanceof HTMLSelectElement)) {
            return;
        }
        const tom = element.tomselect;
        if (tom) {
            const wrapperConnected = Boolean(tom.wrapper?.isConnected);
            const controlConnected = Boolean(tom.control?.isConnected);
            if (wrapperConnected && controlConnected && syncInstance(element, tom)) {
                return;
            }

            destroyInstance(tom);
        }

        if (element.dataset.tomSelectBound === '1' && !element.tomselect) {
            delete element.dataset.tomSelectBound;
        }

        if (element.dataset.tomSelectBound === '1') {
            return;
        }

        const noSearch = element.dataset.tomNoSearch === '1';
        const visual = element.dataset.tomVisual || '';
        const preserveOrder = element.dataset.tomPreserveOrder === '1';
        const placeholder = element.getAttribute('placeholder') || element.dataset.tomPlaceholder || '';
        const maxItemsRaw = element.dataset.tomMaxItems;
        const maxItems = maxItemsRaw ? Number.parseInt(maxItemsRaw, 10) : (element.multiple ? null : 1);

        const plugins = [];
        if (!noSearch) {
            plugins.push('dropdown_input');
        }
        if (element.multiple) {
            plugins.push('remove_button');
        }

        const config = {
            allowEmptyOption: true,
            maxItems: Number.isNaN(maxItems) ? (element.multiple ? null : 1) : maxItems,
            create: false,
            plugins,
            controlInput: noSearch ? null : undefined,
            searchField: noSearch ? [] : ['text'],
            sortField: preserveOrder
                ? [{ field: '$order', direction: 'asc' }]
                : [{ field: 'text', direction: 'asc' }],
            placeholder,
            onChange() {
                if (element.tomselect) {
                    element.tomselect.__adminSelectSignature = selectSignature(element);
                }
                element.dispatchEvent(new Event('input', { bubbles: true }));
                element.dispatchEvent(new Event('change', { bubbles: true }));
            },
        };

        if (visual === 'block-type') {
            config.render = buildTypeRender();
        }

        element.dataset.tomSelectBound = '1';
        const instance = new TomSelect(element, config);
        instance.__adminSelectSignature = selectSignature(element);
        if (instance.wrapper instanceof HTMLElement) {
            instance.wrapper.__adminTomSelectInstance = instance;
        }
    };

    const bindAll = (root) => {
        if (!root) {
            return;
        }
        if (root instanceof HTMLSelectElement && root.matches(selectSelector)) {
            bindElement(root);
        }
        root.querySelectorAll?.(selectSelector).forEach(bindElement);
    };

    bindAll(document);

    if (!window.__tomSelectObserver) {
        window.__tomSelectObserver = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.removedNodes.forEach((node) => {
                    if (node instanceof HTMLElement || node instanceof HTMLSelectElement) {
                        destroyAll(node);
                    }
                });

                if (mutation.target instanceof HTMLElement || mutation.target instanceof HTMLSelectElement) {
                    bindAll(mutation.target);
                }

                mutation.addedNodes.forEach((node) => {
                    if (node instanceof HTMLElement || node instanceof HTMLSelectElement) {
                        bindAll(node);
                    }
                });

            });
        });
    }

    if (window.__tomSelectObserverBody !== document.body) {
        window.__tomSelectObserver.disconnect();
        window.__tomSelectObserver.observe(document.body, {
            childList: true,
            subtree: true,
        });
        window.__tomSelectObserverBody = document.body;
    }

    if (window.__tomSelectLivewireHooksBound !== '1') {
        const registerLivewireHooks = () => {
            if (window.__tomSelectLivewireHooksBound === '1'
                || !window.Livewire
                || typeof window.Livewire.hook !== 'function') {
                return;
            }

            window.__tomSelectLivewireHooksBound = '1';
            window.Livewire.hook('morph.removing', ({ el }) => {
                destroyAll(el);
            });
            window.Livewire.hook('morphed', ({ el }) => {
                // Run after Alpine/Livewire have applied model values to native
                // selects, then mirror the complete option set into Tom Select.
                setTimeout(() => bindAll(el), 0);
            });
        };

        if (window.Livewire && typeof window.Livewire.hook === 'function') {
            registerLivewireHooks();
        } else {
            document.addEventListener('livewire:init', registerLivewireHooks, { once: true });
        }
    }
};

const initQuillEditors = () => {
    if (!document.body) {
        return;
    }

    const selector = 'textarea[data-quill-editor]';

    const normalizeHtml = (html) => {
        const value = String(html ?? '').trim();
        return value === '<p><br></p>' ? '' : value;
    };

    const bypassInlineSanitizeOnce = (textarea) => (
        textarea instanceof HTMLTextAreaElement
        && textarea.dataset.quillBypassInlineSanitizeOnce === '1'
    );

    const stripInlineStyles = (html) => {
        const source = String(html ?? '').trim();
        if (source === '') {
            return '';
        }

        const parser = new DOMParser();
        const doc = parser.parseFromString(source, 'text/html');
        doc.body.querySelectorAll('[style]').forEach((node) => node.removeAttribute('style'));

        return normalizeHtml(doc.body.innerHTML || '');
    };

    const readTextareaHtml = (textarea) => {
        if (!(textarea instanceof HTMLTextAreaElement)) {
            return '';
        }

        const normalizeForField = (html) => {
            const normalized = normalizeHtml(html);
            return bypassInlineSanitizeOnce(textarea) ? normalized : stripInlineStyles(normalized);
        };

        const fromValue = normalizeForField(textarea.value);
        if (fromValue !== '') {
            return fromValue;
        }

        // Livewire can occasionally hydrate <textarea> content without dispatching input/change.
        return normalizeForField(textarea.textContent ?? '');
    };

    const hideTextarea = (textarea) => {
        if (!(textarea instanceof HTMLTextAreaElement)) {
            return;
        }

        textarea.classList.add('hidden');
        textarea.setAttribute('aria-hidden', 'true');
        textarea.tabIndex = -1;
    };

    const attachTextareaToState = (state, textarea) => {
        if (!state || !(textarea instanceof HTMLTextAreaElement)) {
            return;
        }

        if (state.textarea === textarea) {
            textarea.__adminQuillState = state;
            textarea.dataset.quillBound = '1';
            hideTextarea(textarea);
            return;
        }

        if (state.textarea instanceof HTMLTextAreaElement) {
            state.textarea.removeEventListener('input', state.syncQuillFromTextarea);
            state.textarea.removeEventListener('change', state.syncQuillFromTextarea);
            state.valueObserver?.disconnect();
            delete state.textarea.__adminQuillState;
            delete state.textarea.dataset.quillBound;
        }

        state.textarea = textarea;
        state.wrapper.__adminQuillState = state;
        textarea.__adminQuillState = state;
        textarea.dataset.quillBound = '1';
        hideTextarea(textarea);

        textarea.addEventListener('input', state.syncQuillFromTextarea);
        textarea.addEventListener('change', state.syncQuillFromTextarea);

        state.valueObserver = new MutationObserver(() => {
            state.syncQuillFromTextarea();
        });
        state.valueObserver.observe(textarea, {
            childList: true,
            characterData: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['value'],
        });
    };

    const bindElement = (textarea) => {
        if (!(textarea instanceof HTMLTextAreaElement)) {
            return;
        }

        const existingQuillWrapper = textarea.nextElementSibling instanceof HTMLElement
            && textarea.nextElementSibling.classList.contains('admin-quill')
            ? textarea.nextElementSibling
            : null;

        const existingState = existingQuillWrapper?.__adminQuillState ?? textarea.__adminQuillState ?? null;
        if (existingState?.wrapper instanceof HTMLElement && existingState.wrapper.isConnected) {
            attachTextareaToState(existingState, textarea);
            setTimeout(() => existingState.syncQuillFromTextarea(), 0);
            return;
        }

        // If this field was previously mounted with Ace, tear it down and restore textarea.
        const staleAce = textarea.nextElementSibling;
        if (staleAce instanceof HTMLElement && staleAce.classList.contains('admin-ace-inline')) {
            staleAce.remove();
            delete textarea.dataset.aceInlineBound;
        }

        const wrapper = existingQuillWrapper ?? document.createElement('div');
        if (!existingQuillWrapper) {
            wrapper.className = 'admin-quill';
            textarea.insertAdjacentElement('afterend', wrapper);
        }

        let editorRoot = wrapper.querySelector(':scope > .admin-quill-editor');
        if (!(editorRoot instanceof HTMLElement)) {
            editorRoot = document.createElement('div');
            editorRoot.className = 'admin-quill-editor';
            wrapper.replaceChildren(editorRoot);
        }

        let quill = null;
        try {
            quill = new Quill(editorRoot, {
                theme: 'snow',
                modules: {
                    toolbar: [
                        [{ header: [2, 3, false] }],
                        ['bold', 'italic', 'underline'],
                        [{ list: 'ordered' }, { list: 'bullet' }],
                        ['blockquote', 'link'],
                        ['clean'],
                    ],
                },
                placeholder: textarea.getAttribute('placeholder') || '',
            });
        } catch (error) {
            console.error('Failed to initialize Quill editor', error);
            if (!existingQuillWrapper) {
                wrapper.remove();
            }
            window.dispatchEvent(new CustomEvent('admin:notify', {
                detail: { type: 'danger', message: 'WYSIWYG editor failed to load.' },
            }));
            return;
        }

        // Paste sanitization: remove inline style attributes from incoming HTML.
        quill.clipboard.addMatcher(Node.ELEMENT_NODE, (node, delta) => {
            if (!bypassInlineSanitizeOnce(textarea) && node instanceof HTMLElement && node.hasAttribute('style')) {
                node.removeAttribute('style');
            }
            return delta;
        });

        if (!Array.isArray(window.__adminQuillEditors)) {
            window.__adminQuillEditors = [];
        }
        window.__adminQuillEditors.push(quill);
        window.__activeAdminQuill = quill;
        quill.__lastRange = null;

        quill.on('selection-change', (range) => {
            if (range) {
                window.__activeAdminQuill = quill;
                quill.__lastRange = range;
            }
        });

        const rows = Number.parseInt(textarea.getAttribute('rows') || '8', 10);
        const minHeight = Number.isNaN(rows) ? 220 : Math.max(180, rows * 26);
        const editorNode = editorRoot.querySelector('.ql-editor');
        if (editorNode instanceof HTMLElement) {
            editorNode.style.minHeight = `${minHeight}px`;
        }

        const initial = readTextareaHtml(textarea);
        if (initial) {
            quill.clipboard.dangerouslyPasteHTML(initial);
        } else {
            quill.setText('');
        }

        const state = {
            quill,
            wrapper,
            textarea: null,
            valueObserver: null,
            syncingFromQuill: false,
            syncingFromTextarea: false,
            syncTextareaFromQuill(dispatchChange = false) {
                const activeTextarea = state.textarea;
                if (!(activeTextarea instanceof HTMLTextAreaElement) || state.syncingFromTextarea) {
                    return;
                }

                // Keep Quill HTML as-is; inline style stripping is handled on ingestion/paste.
                const html = normalizeHtml(quill.root.innerHTML);
                const textareaHtml = normalizeHtml(activeTextarea.value);

                if (textareaHtml !== html) {
                    state.syncingFromQuill = true;
                    activeTextarea.value = html;
                    activeTextarea.dispatchEvent(new Event('input', { bubbles: true }));
                    if (dispatchChange) {
                        activeTextarea.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    state.syncingFromQuill = false;
                    return;
                }

                if (dispatchChange) {
                    activeTextarea.dispatchEvent(new Event('change', { bubbles: true }));
                }
            },
            syncQuillFromTextarea() {
                const activeTextarea = state.textarea;
                if (!(activeTextarea instanceof HTMLTextAreaElement) || state.syncingFromQuill) {
                    return;
                }

                const source = readTextareaHtml(activeTextarea);
                const current = normalizeHtml(quill.root.innerHTML);
                if (source === current) {
                    return;
                }

                const hadFocus = wrapper.contains(document.activeElement);
                const previousRange = quill.getSelection() || quill.__lastRange || null;

                state.syncingFromTextarea = true;
                if (source) {
                    quill.clipboard.dangerouslyPasteHTML(source);
                } else {
                    quill.setText('');
                }
                if (activeTextarea.dataset.quillBypassInlineSanitizeOnce === '1') {
                    delete activeTextarea.dataset.quillBypassInlineSanitizeOnce;
                }

                if (hadFocus && previousRange) {
                    const maxIndex = Math.max(0, quill.getLength() - 1);
                    quill.setSelection(Math.min(previousRange.index, maxIndex), previousRange.length || 0, 'silent');
                }
                state.syncingFromTextarea = false;
            },
        };

        attachTextareaToState(state, textarea);

        quill.on('text-change', () => {
            state.syncTextareaFromQuill(false);
        });

        if (editorNode instanceof HTMLElement) {
            editorNode.addEventListener('blur', () => {
                state.syncTextareaFromQuill(true);
            });
        }

        setTimeout(() => state.syncQuillFromTextarea(), 0);
        setTimeout(() => state.syncQuillFromTextarea(), 200);
    };

    const bindAll = (root) => {
        if (!root) {
            return;
        }
        if (root instanceof HTMLTextAreaElement && root.matches(selector)) {
            bindElement(root);
        }
        root.querySelectorAll?.(selector).forEach(bindElement);
    };

    bindAll(document);

    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node instanceof HTMLElement || node instanceof HTMLTextAreaElement) {
                    bindAll(node);
                }
            });

            if (mutation.removedNodes.length > 0 && mutation.target instanceof HTMLElement) {
                bindAll(mutation.target);
            }
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
    });

    if (window.__adminQuillLivewireHookBound !== '1') {
        const registerMorphHook = () => {
            if (window.__adminQuillLivewireHookBound === '1') {
                return;
            }
            if (!window.Livewire || typeof window.Livewire.hook !== 'function') {
                return;
            }

            window.__adminQuillLivewireHookBound = '1';
            window.Livewire.hook('morphed', ({ el }) => {
                if (el instanceof HTMLElement || el instanceof HTMLTextAreaElement) {
                    bindAll(el);
                }
            });
        };

        if (window.Livewire && typeof window.Livewire.hook === 'function') {
            registerMorphHook();
        } else {
            document.addEventListener('livewire:init', registerMorphHook, { once: true });
        }
    }

    if (window.__adminQuillSmartLinkBound !== '1') {
        window.__adminQuillSmartLinkBound = '1';
        window.addEventListener('admin-quill-insert-link', (event) => {
            const detail = event?.detail || {};
            const url = String(detail.url || '').trim();
            const label = String(detail.label || '').trim();
            if (!url) {
                return;
            }

            let quill = window.__activeAdminQuill || null;
            if (!quill && Array.isArray(window.__adminQuillEditors) && window.__adminQuillEditors.length > 0) {
                quill = window.__adminQuillEditors[0];
            }
            if (!quill) {
                return;
            }

            let range = quill.getSelection();
            if (!range && quill.__lastRange) {
                range = quill.__lastRange;
                quill.setSelection(range.index, range.length || 0, 'silent');
            }
            if (!range) {
                return;
            }

            if (range.length > 0) {
                quill.formatText(range.index, range.length, 'link', url, 'user');
                quill.setSelection(range.index + range.length, 0, 'silent');
                return;
            }

            const text = label !== '' ? label : url;
            quill.insertText(range.index, text, 'link', url, 'user');
            quill.setSelection(range.index + text.length, 0, 'silent');
        });
    }
};

const initLivewireEditorMorphGuard = () => {
    if (window.__adminEditorMorphGuardReady === true) {
        return;
    }

    const isManagedEditorWrapper = (element) => {
        if (!(element instanceof HTMLElement)) {
            return false;
        }

        if (element.classList.contains('admin-ace-inline')) {
            const anchor = element.previousElementSibling;
            return anchor instanceof HTMLTextAreaElement && anchor.matches('textarea[data-ace-inline]');
        }

        if (element.classList.contains('admin-quill')) {
            const anchor = element.previousElementSibling;
            return anchor instanceof HTMLTextAreaElement && anchor.matches('textarea[data-quill-editor]');
        }

        return false;
    };

    const register = () => {
        if (window.__adminEditorMorphGuardReady === true) {
            return;
        }
        if (!window.Livewire || typeof window.Livewire.hook !== 'function') {
            return;
        }

        window.__adminEditorMorphGuardReady = true;
        window.Livewire.hook('morph.removing', ({ el, skip }) => {
            if (isManagedEditorWrapper(el)) {
                skip();
            }
        });
    };

    if (window.Livewire && typeof window.Livewire.hook === 'function') {
        register();
        return;
    }

    document.addEventListener('livewire:init', register, { once: true });
};

const initMediaImageEditor = () => {
    if (!document.body || document.body.dataset.mediaImageEditorReady === '1') {
        return;
    }

    const currentPath = window.location?.pathname || '';
    const isAdminPath = currentPath === '/admin' || currentPath.startsWith('/admin/');
    if (!isAdminPath) {
        return;
    }

    document.body.dataset.mediaImageEditorReady = '1';

    const clamp = (value, min = 0, max = 100) => Math.max(min, Math.min(max, value));
    const toFixed = (value) => Number.parseFloat(String(value || 0)).toFixed(1);

    const createModal = () => {
        const existing = document.getElementById('admin-image-edit-overlay');
        if (existing instanceof HTMLElement) {
            return existing;
        }

        const overlay = document.createElement('div');
        overlay.id = 'admin-image-edit-overlay';
        overlay.className = 'admin-image-edit-overlay';
        overlay.setAttribute('aria-hidden', 'true');
        overlay.innerHTML = `
            <div class="admin-image-edit-modal" role="dialog" aria-modal="true" aria-labelledby="admin-image-edit-title">
                <div class="admin-image-edit-header">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Image Edit</p>
                        <h2 id="admin-image-edit-title" class="mt-1 text-base font-semibold tracking-tight text-slate-900">Crop & Focus</h2>
                    </div>
                    <button type="button" id="admin-image-edit-close" class="rounded-md border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-100">Close</button>
                </div>
                <div class="admin-image-edit-body">
                    <div class="admin-image-edit-canvas-wrap">
                        <div class="admin-image-edit-canvas" id="admin-image-edit-canvas"></div>
                        <span class="admin-image-focal-dot" id="admin-image-focal-dot" aria-hidden="true"></span>
                    </div>
                    <div class="admin-image-edit-side">
                        <label class="admin-switch">
                            <input type="checkbox" id="admin-image-edit-crop-enabled" />
                            <span class="admin-switch-slider" aria-hidden="true"></span>
                            <span>Use crop box</span>
                        </label>
                        <div class="admin-image-edit-meta">
                            <div class="admin-image-meta-row"><span>Focal X</span><strong id="admin-image-meta-focal-x">50.0%</strong></div>
                            <div class="admin-image-meta-row"><span>Focal Y</span><strong id="admin-image-meta-focal-y">50.0%</strong></div>
                            <div class="admin-image-meta-row"><span>Crop X</span><strong id="admin-image-meta-crop-x">0.0%</strong></div>
                            <div class="admin-image-meta-row"><span>Crop Y</span><strong id="admin-image-meta-crop-y">0.0%</strong></div>
                            <div class="admin-image-meta-row"><span>Crop W</span><strong id="admin-image-meta-crop-w">100.0%</strong></div>
                            <div class="admin-image-meta-row"><span>Crop H</span><strong id="admin-image-meta-crop-h">100.0%</strong></div>
                        </div>
                        <p class="text-xs text-slate-500">Tip: drag the crop box to frame conversion area. Click image to set focus point.</p>
                    </div>
                </div>
                <div class="admin-image-edit-footer">
                    <button type="button" id="admin-image-edit-reset" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Reset</button>
                    <button type="button" id="admin-image-edit-cancel" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
                    <button type="button" id="admin-image-edit-apply" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">Apply</button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        return overlay;
    };

    const overlay = createModal();
    const closeButton = document.getElementById('admin-image-edit-close');
    const cancelButton = document.getElementById('admin-image-edit-cancel');
    const resetButton = document.getElementById('admin-image-edit-reset');
    const applyButton = document.getElementById('admin-image-edit-apply');
    const canvasHost = document.getElementById('admin-image-edit-canvas');
    const canvasWrap = canvasHost?.closest('.admin-image-edit-canvas-wrap');
    const focalDot = document.getElementById('admin-image-focal-dot');
    const cropEnabledInput = document.getElementById('admin-image-edit-crop-enabled');

    const metaFocalX = document.getElementById('admin-image-meta-focal-x');
    const metaFocalY = document.getElementById('admin-image-meta-focal-y');
    const metaCropX = document.getElementById('admin-image-meta-crop-x');
    const metaCropY = document.getElementById('admin-image-meta-crop-y');
    const metaCropW = document.getElementById('admin-image-meta-crop-w');
    const metaCropH = document.getElementById('admin-image-meta-crop-h');

    if (
        !(overlay instanceof HTMLElement) ||
        !(closeButton instanceof HTMLButtonElement) ||
        !(cancelButton instanceof HTMLButtonElement) ||
        !(resetButton instanceof HTMLButtonElement) ||
        !(applyButton instanceof HTMLButtonElement) ||
        !(canvasHost instanceof HTMLElement) ||
        !(canvasWrap instanceof HTMLElement) ||
        !(focalDot instanceof HTMLElement) ||
        !(cropEnabledInput instanceof HTMLInputElement) ||
        !(metaFocalX instanceof HTMLElement) ||
        !(metaFocalY instanceof HTMLElement) ||
        !(metaCropX instanceof HTMLElement) ||
        !(metaCropY instanceof HTMLElement) ||
        !(metaCropW instanceof HTMLElement) ||
        !(metaCropH instanceof HTMLElement)
    ) {
        return;
    }

    /** @type {Cropper|null} */
    let cropper = null;
    let openState = null;
    let busy = false;

    const parseBool = (value) => {
        const raw = String(value ?? '').toLowerCase();
        return raw === '1' || raw === 'true' || raw === 'yes';
    };

    const updateMeta = (state) => {
        metaFocalX.textContent = `${toFixed(state.focalX)}%`;
        metaFocalY.textContent = `${toFixed(state.focalY)}%`;
        metaCropX.textContent = `${toFixed(state.cropX)}%`;
        metaCropY.textContent = `${toFixed(state.cropY)}%`;
        metaCropW.textContent = `${toFixed(state.cropWidth)}%`;
        metaCropH.textContent = `${toFixed(state.cropHeight)}%`;
    };

    const updateFocalDot = (state) => {
        focalDot.style.left = `${state.focalX}%`;
        focalDot.style.top = `${state.focalY}%`;
    };

    const toNaturalPercent = (state, cropData) => {
        if (!state.imageNaturalWidth || !state.imageNaturalHeight) {
            return null;
        }

        const x = clamp((cropData.x / state.imageNaturalWidth) * 100);
        const y = clamp((cropData.y / state.imageNaturalHeight) * 100);
        const width = clamp((cropData.width / state.imageNaturalWidth) * 100, 1, 100);
        const height = clamp((cropData.height / state.imageNaturalHeight) * 100, 1, 100);

        return { x, y, width, height };
    };

    const setCropDataFromPercent = (state) => {
        if (!cropper || !state.imageNaturalWidth || !state.imageNaturalHeight) {
            return;
        }

        cropper.setData({
            x: (state.cropX / 100) * state.imageNaturalWidth,
            y: (state.cropY / 100) * state.imageNaturalHeight,
            width: (state.cropWidth / 100) * state.imageNaturalWidth,
            height: (state.cropHeight / 100) * state.imageNaturalHeight,
        });
    };

    const syncCropperMode = () => {
        if (!cropper || !openState) {
            return;
        }

        if (openState.cropEnabled) {
            cropper.setDragMode('crop');
            cropper.crop();
            setCropDataFromPercent(openState);
        } else {
            cropper.clear();
            cropper.setDragMode('move');
        }
    };

    const destroyCropper = () => {
        if (!cropper) {
            return;
        }

        cropper.destroy();
        cropper = null;
        canvasHost.innerHTML = '';
    };

    const close = () => {
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        destroyCropper();
        openState = null;
        busy = false;
        applyButton.disabled = false;
    };

    const open = (button) => {
        const imageUrl = String(button.dataset.imageUrl || '').trim();
        const mediaId = Number.parseInt(String(button.dataset.mediaId || ''), 10);
        if (!imageUrl || Number.isNaN(mediaId)) {
            return;
        }

        const state = {
            trigger: button,
            mediaId,
            imageUrl,
            wireId: button.closest('[wire\\:id]')?.getAttribute('wire:id') || '',
            focalX: clamp(Number.parseFloat(String(button.dataset.focalX || '50')) || 50),
            focalY: clamp(Number.parseFloat(String(button.dataset.focalY || '50')) || 50),
            cropEnabled: parseBool(button.dataset.cropEnabled),
            cropX: clamp(Number.parseFloat(String(button.dataset.cropX || '0')) || 0),
            cropY: clamp(Number.parseFloat(String(button.dataset.cropY || '0')) || 0),
            cropWidth: clamp(Number.parseFloat(String(button.dataset.cropWidth || '100')) || 100, 1, 100),
            cropHeight: clamp(Number.parseFloat(String(button.dataset.cropHeight || '100')) || 100, 1, 100),
            imageNaturalWidth: 0,
            imageNaturalHeight: 0,
        };

        openState = state;
        cropEnabledInput.checked = state.cropEnabled;
        updateMeta(state);
        updateFocalDot(state);

        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        applyButton.disabled = false;
        busy = false;

        destroyCropper();
        const img = document.createElement('img');
        img.src = imageUrl;
        img.alt = '';
        img.className = 'admin-image-edit-image';
        canvasHost.appendChild(img);

        cropper = new Cropper(img, {
            viewMode: 1,
            autoCropArea: 1,
            background: false,
            responsive: true,
            zoomable: false,
            scalable: false,
            rotatable: false,
            guides: true,
            center: true,
            movable: true,
            ready() {
                if (!openState || !cropper) {
                    return;
                }
                const imageData = cropper.getImageData();
                openState.imageNaturalWidth = imageData.naturalWidth || 0;
                openState.imageNaturalHeight = imageData.naturalHeight || 0;
                syncCropperMode();
            },
            crop(event) {
                if (!openState || !cropper || !openState.cropEnabled) {
                    return;
                }
                const next = toNaturalPercent(openState, event.detail);
                if (!next) {
                    return;
                }

                openState.cropX = next.x;
                openState.cropY = next.y;
                openState.cropWidth = next.width;
                openState.cropHeight = next.height;
                openState.focalX = clamp(next.x + next.width / 2);
                openState.focalY = clamp(next.y + next.height / 2);
                updateMeta(openState);
                updateFocalDot(openState);
            },
        });
    };

    const setFocalFromClick = (event) => {
        if (!openState || !canvasWrap) {
            return;
        }

        const image = canvasHost.querySelector('img');
        if (!(image instanceof HTMLImageElement)) {
            return;
        }

        const rect = image.getBoundingClientRect();
        if (rect.width <= 0 || rect.height <= 0) {
            return;
        }

        const clickX = ((event.clientX - rect.left) / rect.width) * 100;
        const clickY = ((event.clientY - rect.top) / rect.height) * 100;
        openState.focalX = clamp(clickX);
        openState.focalY = clamp(clickY);
        updateFocalDot(openState);
        updateMeta(openState);
    };

    const notify = (type, message) => {
        window.dispatchEvent(new CustomEvent('admin:notify', {
            detail: { type, message },
        }));
    };

    const getLivewireComponent = (wireId) => {
        if (!window.Livewire) {
            return null;
        }

        if (wireId && typeof window.Livewire.find === 'function') {
            const found = window.Livewire.find(wireId);
            if (found) {
                return found;
            }
        }

        if (typeof window.Livewire.all === 'function') {
            const all = window.Livewire.all();
            if (Array.isArray(all) && all.length) {
                return all[0];
            }
        }

        return null;
    };

    const apply = async () => {
        if (busy || !openState) {
            return;
        }

        const component = getLivewireComponent(openState.wireId);
        if (!component || typeof component.call !== 'function') {
            notify('danger', 'Livewire component not available.');
            return;
        }

        busy = true;
        applyButton.disabled = true;

        try {
            await component.call('saveImageEditFromModal', openState.mediaId, {
                focal_x: openState.focalX,
                focal_y: openState.focalY,
                crop_enabled: openState.cropEnabled,
                crop_x: openState.cropX,
                crop_y: openState.cropY,
                crop_width: openState.cropWidth,
                crop_height: openState.cropHeight,
            });
            close();
        } catch (error) {
            console.error('Failed to save image edit', error);
            notify('danger', 'Failed to save crop/focus.');
            busy = false;
            applyButton.disabled = false;
        }
    };

    cropEnabledInput.addEventListener('change', () => {
        if (!openState) {
            return;
        }
        openState.cropEnabled = cropEnabledInput.checked;
        syncCropperMode();
        updateMeta(openState);
    });

    resetButton.addEventListener('click', () => {
        if (!openState) {
            return;
        }
        openState.focalX = 50;
        openState.focalY = 50;
        openState.cropEnabled = false;
        openState.cropX = 0;
        openState.cropY = 0;
        openState.cropWidth = 100;
        openState.cropHeight = 100;
        cropEnabledInput.checked = false;
        syncCropperMode();
        updateFocalDot(openState);
        updateMeta(openState);
    });

    closeButton.addEventListener('click', close);
    cancelButton.addEventListener('click', close);
    applyButton.addEventListener('click', apply);

    canvasWrap.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        if (
            target === canvasWrap ||
            target === focalDot ||
            target instanceof HTMLImageElement ||
            target.closest('.cropper-container')
        ) {
            setFocalFromClick(event);
        }
    });

    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
            close();
        }
    });

    window.addEventListener('keydown', (event) => {
        if (!overlay.classList.contains('is-open')) {
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
        }
    });

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        const button = target.closest('[data-image-edit-open]');
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        event.preventDefault();
        open(button);
    });
};

const initAceInline = () => {
    if (!document.body) {
        return;
    }

    const selector = 'textarea[data-ace-inline]';

    const readTextareaValue = (textarea) => {
        if (!(textarea instanceof HTMLTextAreaElement)) {
            return '';
        }

        if ((textarea.value ?? '') !== '') {
            return textarea.value;
        }

        // Livewire can patch textarea contents without firing input/change.
        return textarea.textContent || '';
    };

    const bindElement = (textarea) => {
        if (!(textarea instanceof HTMLTextAreaElement)) {
            return;
        }

        const existingMount = textarea.nextElementSibling;
        const existingState = existingMount instanceof HTMLElement
            && existingMount.classList.contains('admin-ace-inline')
            ? existingMount.__adminAceInlineState
            : null;

        if (existingState?.textarea === textarea) {
            textarea.dataset.aceInlineBound = '1';
            textarea.__adminAceInlineState = existingState;
            textarea.style.display = 'none';
            textarea.setAttribute('aria-hidden', 'true');
            textarea.tabIndex = -1;
            setTimeout(existingState.syncEditorFromTextarea, 0);

            return;
        }

        if (existingMount instanceof HTMLElement && existingMount.classList.contains('admin-ace-inline')) {
            existingState?.destroy?.();
            existingMount.remove();
        }

        textarea.dataset.aceInlineBound = '1';

        const mount = document.createElement('div');
        mount.className = 'admin-ace-inline';
        const rows = Number.parseInt(textarea.getAttribute('rows') || '8', 10);
        const minHeight = Number.isNaN(rows) ? 220 : Math.max(180, rows * 26);
        mount.style.minHeight = `${minHeight}px`;
        textarea.insertAdjacentElement('afterend', mount);
        textarea.style.display = 'none';
        textarea.setAttribute('aria-hidden', 'true');
        textarea.tabIndex = -1;

        let editor = null;
        let syncTimer = null;
        let syncingFromEditor = false;
        let syncingFromTextarea = false;
        let valueObserver = null;

        const syncTextareaFromEditor = () => {
            if (!editor || syncingFromTextarea) {
                return;
            }

            const value = editor.getValue();
            if (textarea.value === value) {
                return;
            }

            syncingFromEditor = true;
            textarea.value = value;
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            textarea.dispatchEvent(new Event('change', { bubbles: true }));
            syncingFromEditor = false;
        };

        const scheduleSyncTextarea = () => {
            if (syncTimer) {
                clearTimeout(syncTimer);
            }
            syncTimer = setTimeout(syncTextareaFromEditor, 120);
        };

        const syncEditorFromTextarea = (explicitValue = null) => {
            if (!editor || syncingFromEditor) {
                return;
            }

            const value = typeof explicitValue === 'string'
                ? explicitValue
                : readTextareaValue(textarea);
            if (editor.getValue() === value) {
                return;
            }

            syncingFromTextarea = true;
            editor.setValue(value, -1);
            syncingFromTextarea = false;
        };

        const destroy = () => {
            if (syncTimer) {
                clearTimeout(syncTimer);
            }
            valueObserver?.disconnect();
            editor?.destroy();
            delete textarea.__adminAceInlineState;
            delete mount.__adminAceInlineState;
        };

        const state = { textarea, syncEditorFromTextarea, destroy };
        textarea.__adminAceInlineState = state;
        mount.__adminAceInlineState = state;

        textarea.addEventListener('input', syncEditorFromTextarea);
        textarea.addEventListener('change', syncEditorFromTextarea);

        valueObserver = new MutationObserver(() => {
            syncEditorFromTextarea();
        });
        valueObserver.observe(textarea, {
            childList: true,
            characterData: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['value'],
        });

        loadAce()
            .then((ace) => {
                editor = ace.edit(mount);
                editor.session.setMode('ace/mode/html');
                editor.setTheme('ace/theme/tomorrow_night');
                editor.setOptions({
                    fontSize: '13px',
                    showPrintMargin: false,
                    useSoftTabs: true,
                    tabSize: 2,
                    enableBasicAutocompletion: true,
                    enableLiveAutocompletion: true,
                });
                editor.session.setUseWorker(false);
                editor.setValue(readTextareaValue(textarea), -1);
                editor.session.on('change', scheduleSyncTextarea);
                editor.on('blur', syncTextareaFromEditor);

                // Hydration can finish right after mount.
                setTimeout(syncEditorFromTextarea, 0);
                setTimeout(syncEditorFromTextarea, 200);
            })
            .catch((error) => {
                console.error('Failed to initialize inline Ace editor', error);
                delete textarea.__adminAceInlineState;
                delete mount.__adminAceInlineState;
                mount.remove();
                textarea.style.display = '';
                textarea.removeAttribute('aria-hidden');
                textarea.tabIndex = 0;

                if (!aceInlineFailureNotified) {
                    aceInlineFailureNotified = true;
                    window.dispatchEvent(new CustomEvent('admin:notify', {
                        detail: { type: 'danger', message: 'Inline Ace editor failed to load.' },
                    }));
                }
            });
    };

    const bindAll = (root) => {
        if (!root) {
            return;
        }
        if (root instanceof HTMLTextAreaElement && root.matches(selector)) {
            bindElement(root);
        }
        root.querySelectorAll?.(selector).forEach(bindElement);
    };

    bindAll(document);

    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node instanceof HTMLElement || node instanceof HTMLTextAreaElement) {
                    bindAll(node);
                }
            });
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
    });

    if (window.__adminAceInlineTemplateEventBound !== '1') {
        window.__adminAceInlineTemplateEventBound = '1';
        window.addEventListener('content-block-template-loaded', (event) => {
            const template = typeof event.detail?.template === 'string'
                ? event.detail.template
                : '';

            document.querySelectorAll(selector).forEach((textarea) => {
                textarea.value = template;
                bindElement(textarea);
                textarea.__adminAceInlineState?.syncEditorFromTextarea(template);
            });
        });
    }

    if (window.__adminAceInlineLivewireHookBound !== '1') {
        const registerMorphHook = () => {
            if (window.__adminAceInlineLivewireHookBound === '1') {
                return;
            }
            if (!window.Livewire || typeof window.Livewire.hook !== 'function') {
                return;
            }

            const syncEditors = (root) => {
                const textareas = [];

                if (root instanceof HTMLTextAreaElement && root.matches(selector)) {
                    textareas.push(root);
                }
                root?.querySelectorAll?.(selector).forEach((textarea) => textareas.push(textarea));

                textareas.forEach((textarea) => {
                    bindElement(textarea);

                    const mount = textarea.nextElementSibling;
                    const state = textarea.__adminAceInlineState
                        ?? (mount instanceof HTMLElement ? mount.__adminAceInlineState : null);

                    if (typeof state?.syncEditorFromTextarea === 'function') {
                        setTimeout(state.syncEditorFromTextarea, 0);
                    }
                });
            };

            window.__adminAceInlineLivewireHookBound = '1';
            window.Livewire.hook('morphed', ({ el }) => {
                if (el instanceof HTMLElement || el instanceof HTMLTextAreaElement) {
                    syncEditors(el);
                }
            });
        };

        if (window.Livewire && typeof window.Livewire.hook === 'function') {
            registerMorphHook();
        } else {
            document.addEventListener('livewire:init', registerMorphHook, { once: true });
        }
    }
};

const initDashboardCharts = () => {
    if (!document.body || document.body.dataset.dashboardChartsReady === '1') {
        return;
    }
    document.body.dataset.dashboardChartsReady = '1';

    const selector = 'canvas[data-dashboard-chart]';
    const instances = new WeakMap();

    const parseConfig = (canvas) => {
        const raw = canvas.getAttribute('data-chart-payload');
        if (!raw) {
            return null;
        }

        try {
            const config = JSON.parse(raw);
            if (!config || typeof config !== 'object') {
                return null;
            }

            return config;
        } catch (error) {
            return null;
        }
    };

    const destroyChart = (canvas) => {
        if (!(canvas instanceof HTMLCanvasElement)) {
            return;
        }

        const chart = instances.get(canvas);
        if (!chart) {
            return;
        }

        chart.destroy();
        instances.delete(canvas);
    };

    const bindCanvas = (canvas) => {
        if (!(canvas instanceof HTMLCanvasElement)) {
            return;
        }

        const config = parseConfig(canvas);
        if (!config) {
            destroyChart(canvas);
            return;
        }

        destroyChart(canvas);

        const context = canvas.getContext('2d');
        if (!context) {
            return;
        }

        try {
            const chart = new Chart(context, {
                type: config.type || 'line',
                data: config.data || { labels: [], datasets: [] },
                options: config.options || {},
            });
            instances.set(canvas, chart);
        } catch (error) {
            console.error('Failed to render dashboard chart', error);
        }
    };

    const bindAll = (root) => {
        if (!root) {
            return;
        }
        if (root instanceof HTMLCanvasElement && root.matches(selector)) {
            bindCanvas(root);
        }
        root.querySelectorAll?.(selector).forEach(bindCanvas);
    };

    const destroyFromNode = (node) => {
        if (!node) {
            return;
        }
        if (node instanceof HTMLCanvasElement && node.matches(selector)) {
            destroyChart(node);
        }
        if (node instanceof HTMLElement) {
            node.querySelectorAll(selector).forEach(destroyChart);
        }
    };

    bindAll(document);

    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.type === 'attributes' && mutation.target instanceof HTMLCanvasElement) {
                bindCanvas(mutation.target);
                return;
            }

            mutation.removedNodes.forEach(destroyFromNode);
            mutation.addedNodes.forEach((node) => {
                if (node instanceof HTMLElement || node instanceof HTMLCanvasElement) {
                    bindAll(node);
                }
            });
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['data-chart-payload'],
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        initAdminSidebar();
        initLivewireEditorMorphGuard();
        initAceLauncher();
        initAceInline();
        initQuillEditors();
        initMediaImageEditor();
        initTomSelect();
        initDashboardCharts();
    }, { once: true });
} else {
    initAdminSidebar();
    initLivewireEditorMorphGuard();
    initAceLauncher();
    initAceInline();
    initQuillEditors();
    initMediaImageEditor();
    initTomSelect();
    initDashboardCharts();
}

document.addEventListener('livewire:navigated', () => {
    initAdminSidebar();
    initTomSelect();
});
