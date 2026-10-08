import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../public/front-theme/scripts/desktop-header-menu.js', import.meta.url), 'utf8');

function harness({ savedSections = {}, sectionKeys = Object.keys(savedSections), hrefs = [],
    currentPath = '/shop', parents = {}, linkSections = [], categoriesButton = false,
    headerSpacer = false, headerHeight = 176, compactHeaderHeight = 130, scrollY = 0 } = {}) {
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
            this.style = {
                values: new Map(),
                setProperty(name, value) { this.values.set(name, value); },
                getPropertyValue(name) { return this.values.get(name) || ''; },
            };
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
    header.expandedHeight = headerHeight;
    header.compactHeight = compactHeaderHeight;
    header.getBoundingClientRect = () => ({
        height: header.classList.contains('is-sticky') ? header.compactHeight : header.expandedHeight,
    });
    const spacer = headerSpacer ? new Element() : null;
    const openButton = new Element();
    const panel = new Element();
    const overlay = new Element();
    const sections = sectionKeys.map((key) => new Details(key));
    const sectionsByKey = new Map(sections.map((section) => [section.dataset.menuSectionKey, section]));
    sections.forEach((section) => {
        const parent = sectionsByKey.get(parents[section.dataset.menuSectionKey]);
        if (parent) {
            const wrapper = new Element(); wrapper.children = []; wrapper.tagName = 'UL';
            wrapper.closest = (selector) => selector === 'details' ? parent : null;
            section.parentElement = wrapper;
        }
    });
    const links = hrefs.map((href) => Object.assign(new Element(), {
        href: new URL(href, 'https://shop.example/shop').href,
    }));
    links.forEach((link, index) => {
        link.closest = (selector) => selector === 'details' ? sectionsByKey.get(linkSections[index]) || null : null;
    });
    const sectionToggles = sections.map((section) => {
        const toggle = new Element(); toggle.closest = () => section; return toggle;
    });
    if (categoriesButton) openButton.setAttribute('data-mobile-menu-open-categories', '');
    root.querySelector = (selector) => ({
        '[data-mobile-menu-panel]': panel, '[data-mobile-menu-close]': overlay,
        '[data-mobile-menu-catalog]': sectionsByKey.get('catalog'),
    })[selector] || null;
    root.querySelectorAll = (selector) => ({
        '[data-mobile-menu-accordion]': sections, '[data-mobile-menu-close]': [overlay],
        '[data-mobile-menu-toggle]': sectionToggles,
        'a[href]': links,
    })[selector] || [];
    const document = new Element();
    document.body = new Element(); document.readyState = 'complete';
    document.querySelector = (selector) => ({
        '.site-main-header': header, '[data-mobile-menu-root]': root,
        '[data-site-main-header-spacer]': spacer,
    })[selector] || null;
    document.querySelectorAll = (selector) => selector === '[data-mobile-menu-open]' ? [openButton] : [];
    const window = new Element();
    window.location = { origin: 'https://shop.example', href: new URL(currentPath, 'https://shop.example').href };
    window.scrollY = scrollY;
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
        root, header, spacer, window, document, openButton, overlay, sections, storage, links, sectionToggles,
        open() { openButton.emit('click', { preventDefault() {}, currentTarget: openButton }); },
        flushToggles() {
            while (queuedToggles.size > 0) {
                const pending = [...queuedToggles]; queuedToggles.clear();
                pending.forEach((section) => section.emit('toggle'));
            }
        },
    };
}

test('restored scrolling reserves the expanded header height before compacting', () => {
    const page = harness({ headerSpacer: true, scrollY: 300 });
    assert.equal(page.header.classList.contains('is-sticky'), true);
    assert.equal(page.spacer.style.getPropertyValue('--site-main-header-expanded-height'), '176px');
});

test('sticky threshold retains hysteresis and the measured height after BFCache restoration', () => {
    const page = harness({ headerSpacer: true });
    page.window.scrollY = 177; page.window.emit('scroll');
    assert.equal(page.header.classList.contains('is-sticky'), true);
    page.window.scrollY = 170; page.window.emit('scroll');
    assert.equal(page.header.classList.contains('is-sticky'), true);
    page.window.scrollY = 150; page.window.emit('pageshow', { persisted: true });
    assert.equal(page.header.classList.contains('is-sticky'), false);
    assert.equal(page.spacer.style.getPropertyValue('--site-main-header-expanded-height'), '176px');
    assert.equal(page.window.listeners.get('scroll').length, 1);
    assert.equal(page.window.listeners.get('resize').length, 1);
});

test('resizing a compact header updates the spacer from its expanded layout', () => {
    const page = harness({ headerSpacer: true, scrollY: 300 });
    page.header.expandedHeight = 220;
    page.header.compactHeight = 150;
    page.window.emit('resize');
    assert.equal(page.spacer.style.getPropertyValue('--site-main-header-expanded-height'), '220px');
    assert.equal(page.header.classList.contains('is-sticky'), true);
    page.window.scrollY = 190;
    page.window.emit('resize');
    assert.equal(page.header.classList.contains('is-sticky'), false);
});

test('pageshow and image loading refresh header geometry without another scroll', () => {
    const page = harness({ headerSpacer: true });
    page.window.scrollY = 300;
    page.window.emit('pageshow', { persisted: false });
    assert.equal(page.header.classList.contains('is-sticky'), true);
    page.header.expandedHeight = 194;
    page.window.emit('load');
    assert.equal(page.spacer.style.getPropertyValue('--site-main-header-expanded-height'), '194px');
});

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

test('old saved category branches are ignored and only the outer catalogue opens', () => {
    const page = harness({ savedSections: { catalog: true, rasvjeta: true, unutarnja: true, paneli: true }, categoriesButton: true });
    assert.equal(page.sections.every((section) => !section.open), true);
    page.open();
    page.flushToggles();
    assert.deepEqual(page.sections.map((section) => section.open), [true, false, false, false]);
    assert.equal(page.storage.reads, 0);
    assert.equal(page.storage.writes, 0);
});

test('the active category stays highlighted without automatically revealing its ancestors', () => {
    const page = harness({ sectionKeys: ['catalog', 'rasvjeta', 'unutarnja'], categoriesButton: true,
        parents: { rasvjeta: 'catalog', unutarnja: 'rasvjeta' }, linkSections: ['unutarnja'],
        hrefs: ['/categories/paneli'], currentPath: '/categories/paneli' });
    assert.equal(page.sections.every((section) => !section.open), true);
    assert.equal(page.links[0].getAttribute('aria-current'), 'page');
    page.open();
    page.flushToggles();
    assert.deepEqual(page.sections.map((section) => section.open), [true, false, false]);
    assert.equal(page.links[0].getAttribute('aria-current'), 'page');
});

test('deliberate category toggling works while every manual reopening starts with closed children', () => {
    const page = harness({ sectionKeys: ['catalog', 'rasvjeta'], categoriesButton: true });
    page.open(); page.flushToggles();
    page.sectionToggles[1].emit('click', { preventDefault() {}, stopPropagation() {} });
    page.flushToggles();
    assert.equal(page.sections[1].open, true);
    page.overlay.emit('click'); page.open(); page.flushToggles();
    assert.deepEqual(page.sections.map((section) => section.open), [true, false]);
    assert.equal(page.storage.reads, 0);
    assert.equal(page.storage.writes, 0);
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
