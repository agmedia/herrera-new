import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../public/front-theme/scripts/desktop-header-menu.js', import.meta.url), 'utf8');

function harness({ savedSections = {}, hrefs = [] } = {}) {
    const queuedToggles = new Set();
    class Element {
        constructor() {
            this.dataset = {};
            this.listeners = new Map();
            this.attributes = new Map();
            const classes = new Set();
            this.classList = {
                add: (value) => classes.add(value), remove: (value) => classes.delete(value),
                contains: (value) => classes.has(value),
                toggle(value, enabled) { if (enabled) classes.add(value); else classes.delete(value); },
            };
            this.style = { setProperty() {} };
        }
        addEventListener(type, callback) {
            this.listeners.set(type, [...(this.listeners.get(type) || []), callback]);
        }
        emit(type, event = {}) {
            for (const callback of this.listeners.get(type) || []) callback(event);
        }
        querySelector() { return null; }
        querySelectorAll() { return []; }
        hasAttribute(name) { return this.attributes.has(name); }
        getAttribute(name) { return this.attributes.get(name) || null; }
        setAttribute(name, value) { this.attributes.set(name, value); }
        removeAttribute(name) { this.attributes.delete(name); }
        closest() { return null; }
        getBoundingClientRect() { return { height: 72 }; }
    }
    class Details extends Element {
        constructor(key) {
            super(); this.dataset.menuSectionKey = key; this.currentOpen = false;
            this.parentElement = null;
        }
        get open() { return this.currentOpen; }
        set open(value) {
            if (value !== this.currentOpen) { this.currentOpen = value; queuedToggles.add(this); }
        }
    }
    const root = new Element();
    const header = new Element();
    const openButton = new Element();
    const panel = new Element();
    const overlay = new Element();
    const sections = Object.keys(savedSections).map((key) => new Details(key));
    const links = hrefs.map((href) => Object.assign(new Element(), {
        href: new URL(href, 'https://shop.example/shop').href,
    }));
    root.querySelector = (selector) => ({
        '[data-mobile-menu-panel]': panel, '[data-mobile-menu-close]': overlay,
    })[selector] || null;
    root.querySelectorAll = (selector) => ({
        '[data-mobile-menu-accordion]': sections, '[data-mobile-menu-close]': [overlay],
        'a[href]': links,
    })[selector] || [];
    const document = new Element();
    document.body = new Element(); document.readyState = 'complete';
    document.querySelector = (selector) => ({
        '.site-main-header': header, '[data-mobile-menu-root]': root,
    })[selector] || null;
    document.querySelectorAll = (selector) => selector === '[data-mobile-menu-open]' ? [openButton] : [];
    const window = new Element();
    window.location = { origin: 'https://shop.example', href: 'https://shop.example/shop' };
    window.scrollY = 0;
    window.requestAnimationFrame = (callback) => callback();
    const storage = { reads: 0, writes: 0, value: JSON.stringify(savedSections) };
    const sessionStorage = {
        getItem() { storage.reads++; return storage.value; },
        setItem(key, value) { storage.writes++; storage.value = value; },
    };
    vm.runInNewContext(script, {
        document, window, sessionStorage, URL, HTMLElement: Element, HTMLDetailsElement: Details,
    });
    return {
        root, header, window, document, openButton, overlay, sections, storage, links,
        open() { openButton.emit('click', { preventDefault() {}, currentTarget: openButton }); },
        flushToggles() {
            while (queuedToggles.size > 0) {
                const pending = [...queuedToggles]; queuedToggles.clear();
                pending.forEach((section) => section.emit('toggle'));
            }
        },
    };
}

test('initial pageshow leaves an already opened mobile menu visible', () => {
    const page = harness();
    page.open();
    page.window.emit('pageshow', { persisted: false });
    assert.equal(page.root.dataset.menuOpen, '1');
    assert.equal(page.root.inert, false);
    assert.equal(page.document.body.classList.contains('overflow-hidden'), true);
});

test('BFCache restoration closes the previous menu without duplicate input handlers', () => {
    const page = harness();
    page.open();
    page.window.emit('pageshow', { persisted: true });
    assert.equal(page.root.dataset.menuOpen, '0');
    assert.equal(page.root.inert, true);
    assert.equal(page.document.body.classList.contains('overflow-hidden'), false);
    assert.equal(page.openButton.listeners.get('click').length, 1);
    assert.equal(page.window.listeners.get('scroll').length, 1);
    page.open();
    assert.equal(page.root.dataset.menuOpen, '1');
});

test('queued restoration toggles do not write storage; deliberate changes are saved', () => {
    const page = harness({ savedSections: { catalog: true } });
    assert.equal(page.sections[0].open, true);
    page.flushToggles();
    assert.equal(page.storage.writes, 0);
    page.sections[0].open = false;
    page.flushToggles();
    assert.equal(page.storage.writes, 1);
    assert.deepEqual(JSON.parse(page.storage.value), { catalog: false });
});

test('opening the menu repeatedly keeps current accordion state without re-reading storage', () => {
    const page = harness({ savedSections: { catalog: true } });
    page.flushToggles();
    const readsAfterInit = page.storage.reads;
    page.open(); page.overlay.emit('click'); page.open();
    assert.equal(page.sections[0].open, true);
    assert.equal(page.storage.reads, readsAfterInit);
});

test('ordinary full-page link navigation keeps the outgoing drawer and scroll lock still', () => {
    const page = harness({ hrefs: ['/categories/rasvjeta'] });
    page.open();
    let prevented = false;
    page.links[0].emit('click', { button: 0, preventDefault() { prevented = true; } });
    assert.equal(page.root.dataset.menuOpen, '1');
    assert.equal(page.document.body.classList.contains('overflow-hidden'), true);
    assert.equal(prevented, false);
    page.window.emit('pageshow', { persisted: true });
    assert.equal(page.root.dataset.menuOpen, '0');
});

test('manual closing and same-document, auxiliary, download or canceled links still dismiss the drawer', () => {
    const cases = [
        { href: '#catalog' }, { href: '/shop#' }, { href: 'tel:+38512345' },
        { href: 'javascript:void(0)' }, { event: { ctrlKey: true } },
        { event: { metaKey: true } }, { event: { shiftKey: true } },
        { event: { altKey: true } }, { event: { button: 1 } },
        { event: { defaultPrevented: true } },
        { attributes: { target: '_blank' } }, { attributes: { download: '' } },
    ];
    for (const entry of cases) {
        const page = harness({ hrefs: [entry.href || '/categories/rasvjeta'] });
        for (const [name, value] of Object.entries(entry.attributes || {})) page.links[0].setAttribute(name, value);
        page.open();
        page.links[0].emit('click', { button: 0, ...entry.event });
        assert.equal(page.root.dataset.menuOpen, '0', JSON.stringify(entry));
        assert.equal(page.document.body.classList.contains('overflow-hidden'), false);
    }
    const page = harness(); page.open(); page.overlay.emit('click');
    assert.equal(page.root.dataset.menuOpen, '0');
});
