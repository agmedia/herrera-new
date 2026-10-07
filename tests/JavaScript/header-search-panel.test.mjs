import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../public/front-theme/scripts/header-search-panel.js', import.meta.url), 'utf8');

function harness({ enabled = true, mobile = false, persistent = true, breakpoint = '1279' } = {}) {
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
            setAttribute(name, value) { attributes.set(name, value); },
            getAttribute(name) { return attributes.get(name); },
            hasAttribute(name) { return attributes.has(name); },
            contains(target) { return this === target || this.children.some((child) => child.contains(target)); },
            getBoundingClientRect() { return { top: 0 }; },
            querySelectorAll() { return []; },
        });
    };
    const panel = element();
    panel.hasAttribute = () => persistent;
    panel.dataset.headerSearchBreakpoint = breakpoint;
    const form = element();
    const input = element();
    const toggle = element();
    panel.children.push(form);
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
    form.querySelector = (selector) => nodes.get(selector);
    const document = Object.assign(events(), {
        readyState: 'complete', activeElement: null,
        querySelector: (selector) => nodes.get(selector),
        querySelectorAll: (selector) => selector === '[data-header-search-toggle]' ? [toggle] : [],
        createElement: element,
    });
    input.focus = () => { document.activeElement = input; input.emit('focus'); };
    input.blur = () => { document.activeElement = null; };
    toggle.focus = () => { document.activeElement = toggle; };
    const media = Object.assign(events(), { matches: mobile });
    const window = Object.assign(events(), {
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
        input, form, panel, toggle, document, media, window, requests, nodes,
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
