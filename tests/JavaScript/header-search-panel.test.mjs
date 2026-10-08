import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../public/front-theme/scripts/header-search-panel.js', import.meta.url), 'utf8');

function harness({ enabled = true, mobile = false, persistent = true, breakpoint = '1279', fullscreen = false } = {}) {
    let now = 0;
    let timerId = 0;
    const timers = new Map();
    const requests = [];
    const events = () => ({
        listeners: new Map(),
        addEventListener(type, callback) {
            this.listeners.set(type, [...(this.listeners.get(type) || []), callback]);
        },
        emit(type, event = {}) {
            for (const callback of this.listeners.get(type) || []) callback(event);
        },
    });
    const element = () => {
        const classes = new Set();
        const attributes = new Map();
        return Object.assign(events(), {
            dataset: {}, hidden: true, value: '', textContent: '', children: [],
            classList: {
                add: (value) => classes.add(value), remove: (value) => classes.delete(value),
                contains: (value) => classes.has(value),
                toggle(value, on) { if (on) classes.add(value); else classes.delete(value); },
            },
            set innerHTML(value) { this.children = []; },
            appendChild(child) { this.children.push(child); },
            style: { values: new Map(), setProperty(name, value) { this.values.set(name, value); },
                removeProperty(name) { this.values.delete(name); }, getPropertyValue(name) { return this.values.get(name) || ''; } },
            setAttribute(name, value) { attributes.set(name, value); },
            removeAttribute(name) { attributes.delete(name); },
            getClientRects() { return this.hidden ? [] : [{}]; },
            getAttribute(name) { return attributes.get(name); },
            hasAttribute(name) { return attributes.has(name); },
            contains(target) { return this === target || this.children.some((child) => child.contains(target)); },
            getBoundingClientRect() { return { top: 0 }; },
            scrollIntoView(options) { this.scrolled = options.block; },
            querySelectorAll(selector) {
                const descendants = this.children.flatMap((child) => [child, ...child.querySelectorAll('*')]);
                return selector === '[data-header-search-suggestion]'
                    ? descendants.filter((child) => child.dataset.headerSearchSuggestion === '') : descendants;
            },
        });
    };
    const panel = element();
    panel.hasAttribute = (name) => name === 'data-header-search-persistent' ? persistent
        : name === 'data-header-search-fullscreen' ? fullscreen : false;
    panel.dataset.headerSearchBreakpoint = breakpoint;
    const form = element();
    const input = element();
    const toggle = element();
    const close = element();
    panel.querySelector = (selector) => selector === '[data-header-search-close]' ? close : null;
    panel.children.push(form, close);
    form.children.push(input);
    const nodes = new Map([
        ['[data-header-search-panel]', panel], ['[data-header-search-form]', form],
        ['[data-header-search-input]', input],
    ]);
    for (const name of ['suggestions', 'suggestions-close', 'suggestions-meta', 'suggestions-list', 'loading', 'empty', 'footer', 'view-all']) {
        nodes.set(`[data-header-search-${name}]`, element());
    }
    form.dataset = {
        autocompleteEnabled: enabled ? '1' : '0', autocompleteEndpoint: '/search/autocomplete',
        autocompleteEmptyLabel: 'No results: __QUERY__', autocompleteResultsLabel: '__COUNT__ results',
    };
    form.action = '/shop';
    form.querySelectorAll = () => [];
    panel.querySelectorAll = () => [input, close, nodes.get('[data-header-search-view-all]')];
    panel.querySelectorAll().forEach((node) => { node.hidden = false; });
    form.querySelector = (selector) => nodes.get(selector);
    const document = Object.assign(events(), {
        readyState: 'complete', activeElement: null, body: element(),
        querySelector: (selector) => nodes.get(selector),
        querySelectorAll: (selector) => selector === '[data-header-search-toggle]' ? [toggle] : [],
        createElement: element,
    });
    input.focus = () => { document.activeElement = input; input.emit('focus'); };
    input.blur = () => { document.activeElement = null; };
    toggle.focus = () => { document.activeElement = toggle; };
    close.focus = () => { document.activeElement = close; };
    nodes.get('[data-header-search-view-all]').focus = () => { document.activeElement = nodes.get('[data-header-search-view-all]'); };
    const viewport = Object.assign(events(), { height: 746, offsetTop: 0 });
    const media = Object.assign(events(), { matches: mobile });
    const window = Object.assign(events(), {
        visualViewport: viewport, innerHeight: 746,
        location: { origin: 'https://shop.example', href: 'https://shop.example/shop', assign() {} }, scrollY: 0, scrollTo() {},
        matchMedia(query) { media.query = query; return media; },
        setTimeout(callback, delay) { const id = ++timerId; timers.set(id, { callback, at: now + delay }); return id; },
        clearTimeout(id) { timers.delete(id); },
        fetch(url, options) {
            return new Promise((resolve, reject) => {
                requests.push({ url, signal: options.signal, resolve: (payload) => resolve({ ok: true, json: async () => payload }), reject });
            });
        },
    });
    vm.runInNewContext(script, { document, window, URL, AbortController, Date: { now: () => now } });
    return {
        input, form, panel, toggle, close, document, media, window, viewport, requests, nodes,
        type(value) { input.value = value; input.emit('input'); },
        advance(duration) {
            const end = now + duration;
            while (true) {
                const next = [...timers.entries()].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
                if (!next) break;
                timers.delete(next[0]); now = next[1].at; next[1].callback();
            }
            now = end;
        },
    };
}

async function settle() {
    for (let i = 0; i < 8; i++) await Promise.resolve();
}

test('typing debounces once, repeated focus shares the pending request, short input cancels immediately', () => {
    const page = harness();
    page.type('ve');
    page.advance(100);
    page.type('vent');
    page.advance(249);
    assert.equal(page.requests.length, 0);
    page.advance(1);
    assert.equal(page.requests.length, 1);
    assert.equal(new URL(page.requests[0].url).searchParams.get('q'), 'vent');
    page.input.focus(); page.input.focus();
    assert.equal(page.requests.length, 1);
    page.type('v');
    assert.equal(page.requests[0].signal.aborted, true);
    assert.equal(page.nodes.get('[data-header-search-suggestions]').hidden, true);
    page.advance(1000);
    assert.equal(page.requests.length, 1);
});

test('changed text aborts before the next debounce and stale failures cannot close newer results', async () => {
    const page = harness();
    page.input.value = 'ventilator'; page.input.focus();
    page.type('alati');
    assert.equal(page.requests[0].signal.aborted, true);
    page.advance(250);
    page.requests[1].resolve({ total: 0, items: [] });
    await settle();
    assert.equal(page.nodes.get('[data-header-search-empty]').textContent, 'No results: alati');
    page.requests[0].reject(new Error('Late network failure'));
    await settle();
    assert.equal(page.nodes.get('[data-header-search-suggestions]').hidden, false);
    assert.equal(page.nodes.get('[data-header-search-empty]').textContent, 'No results: alati');
});

test('closing search prevents a late successful response from reopening it', async () => {
    const page = harness();
    page.input.value = 'ventilator'; page.input.focus();
    page.nodes.get('[data-header-search-suggestions-close]').emit('click');
    page.requests[0].resolve({ total: 0, items: [] });
    await settle();
    assert.equal(page.requests[0].signal.aborted, true);
    assert.equal(page.nodes.get('[data-header-search-suggestions]').hidden, true);
});

test('recent page-local results avoid repeat fetches, expire quickly, and are cleared on page exit', async () => {
    const page = harness();
    page.input.value = 'ventilator'; page.input.focus();
    page.requests[0].resolve({ total: 0, items: [] });
    await settle();
    page.nodes.get('[data-header-search-suggestions-close]').emit('click');
    page.input.focus();
    assert.equal(page.requests.length, 1);
    assert.equal(page.nodes.get('[data-header-search-suggestions]').hidden, false);
    page.advance(20001);
    page.input.focus();
    assert.equal(page.requests.length, 2);
    page.requests[1].resolve({ total: 0, items: [] });
    await settle();
    page.window.emit('pagehide');
    page.input.focus();
    assert.equal(page.requests.length, 3);
});

test('disabled admin autocomplete never sends requests and native form submission remains available', () => {
    const page = harness({ enabled: false });
    page.type('ventilator'); page.input.focus(); page.advance(1000);
    page.form.emit('submit');
    assert.equal(page.requests.length, 0);
    assert.equal(page.form.action, '/shop');
});

test('mobile opening and delayed panel focus send only one autocomplete request', () => {
    const page = harness({ mobile: true, persistent: false });
    page.input.value = 'ventilator'; page.input.focus();
    page.input.focus(); page.advance(1000);
    assert.equal(page.requests.length, 1);
});

test('mobile search starts closed, icon opens and focuses it, Escape restores the icon focus', () => {
    const page = harness({ mobile: true, persistent: false, breakpoint: '1023' });
    assert.equal(page.media.query, '(max-width: 1023px)');
    assert.equal(page.panel.classList.contains('is-open'), false);
    assert.equal(page.panel.getAttribute('aria-hidden'), 'true');
    assert.equal(page.toggle.getAttribute('aria-expanded'), 'false');
    page.toggle.emit('click');
    assert.equal(page.panel.classList.contains('is-open'), true);
    assert.equal(page.panel.getAttribute('aria-hidden'), 'false');
    assert.equal(page.toggle.getAttribute('aria-expanded'), 'true');
    page.advance(260);
    assert.equal(page.document.activeElement, page.input);
    page.document.emit('keydown', { key: 'Escape' });
    assert.equal(page.panel.classList.contains('is-open'), false);
    assert.equal(page.panel.getAttribute('aria-hidden'), 'true');
    assert.equal(page.toggle.getAttribute('aria-expanded'), 'false');
    assert.equal(page.document.activeElement, page.toggle);
});

test('outside clicks close mobile search without a delayed focus reopening it; desktop search stays visible', () => {
    const page = harness({ mobile: true, persistent: false, breakpoint: '1023' });
    page.toggle.emit('click');
    page.document.emit('click', { target: {} });
    page.advance(1000);
    assert.equal(page.panel.classList.contains('is-open'), false);
    assert.equal(page.document.activeElement, null);
    page.media.matches = false;
    page.media.emit('change');
    assert.equal(page.panel.getAttribute('aria-hidden'), 'false');
    page.input.focus();
    page.document.emit('keydown', { key: 'Escape' });
    assert.equal(page.panel.getAttribute('aria-hidden'), 'false');
    assert.equal(page.document.activeElement, page.input);
    page.media.matches = true;
    page.media.emit('change');
    assert.equal(page.panel.getAttribute('aria-hidden'), 'true');
});

function outsideLink(href = '/categories/rasvjeta', attributes = {}) {
    return {
        href: new URL(href, 'https://shop.example/shop').href,
        closest() { return this; },
        hasAttribute(name) { return Object.hasOwn(attributes, name); },
        getAttribute(name) { return attributes[name] || null; },
    };
}

test('full-page links preserve outgoing mobile search and keyboard until navigation; Back closes it', () => {
    const page = harness({ mobile: true, persistent: false, breakpoint: '1023' });
    page.toggle.emit('click'); page.advance(260);
    let prevented = false;
    page.document.emit('click', { target: outsideLink(), button: 0, preventDefault() { prevented = true; } });
    assert.equal(page.panel.classList.contains('is-open'), true);
    assert.equal(page.document.activeElement, page.input);
    assert.equal(prevented, false);
    page.window.emit('pageshow', { persisted: false });
    assert.equal(page.panel.classList.contains('is-open'), true);
    page.window.emit('pageshow', { persisted: true });
    assert.equal(page.panel.classList.contains('is-open'), false);
    assert.equal(page.document.activeElement, null);
});

test('same-document and auxiliary links still close mobile search', () => {
    const cases = [
        { href: '#catalog' }, { href: '/shop#' }, { href: 'mailto:info@example.com' },
        { href: 'javascript:void(0)' }, { event: { ctrlKey: true } },
        { event: { metaKey: true } }, { event: { shiftKey: true } },
        { event: { altKey: true } }, { event: { button: 1 } },
        { event: { defaultPrevented: true } },
        { attributes: { target: '_blank' } }, { attributes: { download: '' } },
    ];
    for (const entry of cases) {
        const page = harness({ mobile: true, persistent: false, breakpoint: '1023' });
        page.toggle.emit('click'); page.advance(260);
        page.document.emit('click', { target: outsideLink(entry.href, entry.attributes), button: 0, ...entry.event });
        assert.equal(page.panel.classList.contains('is-open'), false, JSON.stringify(entry));
        assert.equal(page.document.activeElement, null);
    }
});

test('page-local result cache stays bounded and evicts older searches', async () => {
    const page = harness();
    for (let index = 0; index < 21; index++) {
        page.input.value = `item ${index}`; page.input.focus();
        page.requests[index].resolve({ total: 0, items: [] });
        await settle();
    }
    page.input.value = 'item 1'; page.input.focus();
    assert.equal(page.requests.length, 21);
    page.input.value = 'item 0'; page.input.focus();
    assert.equal(page.requests.length, 22);
});


test('fullscreen mobile search focuses immediately, fits the keyboard viewport and releases scroll on close', () => {
    const page = harness({ mobile: true, persistent: false, fullscreen: true, breakpoint: '1023' });
    page.window.scrollY = 300;
    page.toggle.emit('click');
    assert.equal(page.document.activeElement, page.input);
    assert.equal(page.document.body.classList.contains('header-search-overlay-open'), true);
    assert.equal(page.panel.getAttribute('role'), 'dialog');
    assert.equal(page.panel.getAttribute('aria-modal'), 'true');
    assert.equal(page.nodes.get('[data-header-search-footer]').hidden, false);
    assert.equal(page.nodes.get('[data-header-search-suggestions]').hidden, false);
    page.viewport.height = 400; page.viewport.offsetTop = 24;
    page.viewport.emit('resize');
    assert.equal(page.panel.style.getPropertyValue('--header-search-viewport-height'), '400px');
    assert.equal(page.panel.style.getPropertyValue('--header-search-viewport-top'), '24px');
    page.close.emit('click');
    assert.equal(page.document.body.classList.contains('header-search-overlay-open'), false);
    assert.equal(page.panel.style.getPropertyValue('--header-search-viewport-height'), '');
    assert.equal(page.panel.getAttribute('aria-modal'), undefined);
    assert.equal(page.document.activeElement, page.toggle);
    assert.equal(page.window.scrollY, 300);
});

test('mobile view all follows the latest query immediately and remains available on empty and failed requests', async () => {
    const page = harness({ mobile: true, persistent: false, fullscreen: true });
    const footer = page.nodes.get('[data-header-search-footer]');
    const link = page.nodes.get('[data-header-search-view-all]');
    page.form.querySelectorAll = () => [{ name: 'category', value: 'rasvjeta' }];
    page.toggle.emit('click');
    page.type('ventilator');
    assert.equal(new URL(link.href).searchParams.get('q'), 'ventilator');
    assert.equal(new URL(link.href).searchParams.get('category'), 'rasvjeta');
    assert.equal(footer.hidden, false);
    page.advance(250);
    assert.equal(footer.hidden, false);
    page.requests[0].resolve({ total: 0, items: [] });
    await settle();
    assert.equal(footer.hidden, false);
    page.type('alati');
    assert.equal(new URL(link.href).searchParams.get('q'), 'alati');
    page.advance(250);
    page.requests[1].reject(new Error('Network unavailable'));
    await settle();
    assert.equal(footer.hidden, false);
    assert.equal(new URL(link.href).searchParams.get('q'), 'alati');
    page.type('a');
    assert.equal(footer.hidden, false);
    assert.equal(new URL(link.href).searchParams.get('q'), 'a');
});

test('mobile full-screen overlay unlocks on desktop resize and never affects persistent search', () => {
    const page = harness({ mobile: true, persistent: false, fullscreen: true });
    page.toggle.emit('click');
    page.media.matches = false; page.media.emit('change');
    assert.equal(page.document.body.classList.contains('header-search-overlay-open'), false);
    assert.equal(page.panel.getAttribute('aria-modal'), undefined);
    assert.equal(page.panel.style.getPropertyValue('--header-search-viewport-height'), '');
    const persistent = harness({ mobile: true, persistent: true });
    assert.equal(persistent.document.body.classList.contains('header-search-overlay-open'), false);
    assert.equal(persistent.panel.getAttribute('aria-modal'), undefined);
});


test('successful mobile product results update the pinned total and keyboard selection scrolls into view', async () => {
    const page = harness({ mobile: true, persistent: false, fullscreen: true });
    page.toggle.emit('click'); page.type('svjetiljka'); page.advance(250);
    page.requests[0].resolve({ total: 23, search_url: '/shop?q=svjetiljka',
        items: [{ name: 'LED svjetiljka', url: '/product/led', price: '15,00 €' }] });
    await settle();
    const footer = page.nodes.get('[data-header-search-footer]');
    const link = page.nodes.get('[data-header-search-view-all]');
    assert.equal(footer.hidden, false);
    assert.equal(link.textContent, 'Prikaži sve (23)');
    const rows = page.nodes.get('[data-header-search-suggestions-list]').querySelectorAll('[data-header-search-suggestion]');
    assert.equal(rows.length, 1);
    assert.equal(page.nodes.get('[data-header-search-suggestions-list]').children[0].classList.contains('has-no-related'), true);
    rows[0].scrollIntoView = (options) => {
        rows[0].scrolled = options.block;
        page.nodes.get('[data-header-search-suggestions-list]').emit('scroll');
    };
    page.input.emit('keydown', { key: 'ArrowDown', preventDefault() {} });
    assert.equal(page.document.activeElement, page.input);
    assert.equal(rows[0].classList.contains('is-active'), true);
    assert.equal(rows[0].scrolled, 'nearest');
    page.type('alati');
    assert.equal(link.textContent, 'Prikaži sve');
    assert.equal(new URL(link.href).searchParams.get('q'), 'alati');
});


test('mobile result touch scrolling still dismisses the software keyboard', () => {
    const page = harness({ mobile: true, persistent: false, fullscreen: true });
    page.toggle.emit('click');
    assert.equal(page.document.activeElement, page.input);
    page.nodes.get('[data-header-search-suggestions-list]').emit('touchmove');
    assert.equal(page.document.activeElement, null);
});

test('categories precede products and keyboard Enter opens the first category', async () => {
    for (const mobile of [false, true]) {
        const page = harness({ mobile, persistent: false, fullscreen: true });
        if (mobile) page.toggle.emit('click');
        page.input.value = 'rasvjeta'; page.input.focus();
        page.requests[0].resolve({ total: 15, groups: {
            categories: { total: 2, items: [
                { name: 'Unutarnja rasvjeta', url: '/category/unutarnja' },
                { name: 'Vanjska rasvjeta', url: '/category/vanjska' },
            ] },
            products: { total: 12, items: [{ name: 'LED svjetiljka', url: '/product/led' }] },
            manufacturers: { total: 1, items: [{ name: 'Braytron', url: '/manufacturer/braytron' }] },
        } });
        await settle();
        const rows = page.nodes.get('[data-header-search-suggestions-list]').querySelectorAll('[data-header-search-suggestion]');
        assert.deepEqual(rows.map((row) => row.href), [
            '/category/unutarnja', '/category/vanjska', '/product/led', '/manufacturer/braytron',
        ]);
        let destination;
        page.window.location.assign = (url) => { destination = url; };
        page.input.emit('keydown', { key: 'ArrowDown', preventDefault() {} });
        page.input.emit('keydown', { key: 'Enter', preventDefault() {} });
        assert.equal(destination, '/category/unutarnja');
    }
});

test('category-only matches remain visible without an empty state', async () => {
    const page = harness({ mobile: true, persistent: false, fullscreen: true });
    page.toggle.emit('click'); page.type('rasvjeta'); page.advance(250);
    page.requests[0].resolve({ total: 1, groups: {
        categories: { total: 1, items: [{ name: 'Rasvjeta', url: '/category/rasvjeta' }] },
    } });
    await settle();
    const rows = page.nodes.get('[data-header-search-suggestions-list]').querySelectorAll('[data-header-search-suggestion]');
    assert.equal(rows.length, 1);
    assert.equal(rows[0].href, '/category/rasvjeta');
    assert.equal(page.nodes.get('[data-header-search-empty]').hidden, true);
    assert.equal(page.nodes.get('[data-header-search-footer]').hidden, false);
});
