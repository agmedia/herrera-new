import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../public/front-theme/scripts/storefront-theme.js', import.meta.url), 'utf8');
const themeHead = readFileSync(new URL('../../resources/views/front/partials/storefront-theme-head.blade.php', import.meta.url), 'utf8');
const boot = themeHead.match(/<script\b[^>]*data-storefront-theme-boot[^>]*>([\s\S]*?)<\/script>/)?.[1];
assert.ok(boot, 'The theme preference must be restored in the document head before paint.');

const storageKey = 'herrera.storefront.theme';

function harness({ saved = null, readyState = 'complete', initialize = true, denyAccess = false } = {}) {
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
        const attributes = new Map();
        return {
            hidden: true,
            dataset: new Proxy({}, {
                get(_, key) {
                    return attributes.get(`data-${key.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`)}`);
                },
                set(_, key, value) {
                    attributes.set(`data-${key.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`)}`, String(value));
                    return true;
                },
            }),
            setAttribute(name, value) { attributes.set(name, String(value)); },
            getAttribute(name) { return attributes.get(name) ?? null; },
            hasAttribute(name) { return attributes.has(name); },
            removeAttribute(name) { attributes.delete(name); },
        };
    };
    const root = element();
    const controls = [element(), element()];
    for (const control of controls) {
        control.dataset.storefrontThemeToggle = '';
        control.dataset.themeLabelDark = 'Uključi tamni prikaz';
        control.dataset.themeLabelLight = 'Uključi svijetli prikaz';
        control.setAttribute('role', 'switch');
        control.setAttribute('aria-label', 'Tamni prikaz');
        control.closest = (selector) => selector === '[data-storefront-theme-toggle]' ? control : null;
    }
    const storage = { value: saved, reads: 0, writes: [], denyRead: false, denyWrite: false };
    const localStorage = {
        getItem(key) {
            assert.equal(key, storageKey);
            storage.reads++;
            if (storage.denyRead) throw new Error('Storage reads are disabled.');
            return storage.value;
        },
        setItem(key, value) {
            assert.equal(key, storageKey);
            if (storage.denyWrite) throw new Error('Storage writes are disabled.');
            storage.value = value;
            storage.writes.push(value);
        },
    };
    const document = Object.assign(events(), {
        readyState,
        documentElement: root,
        querySelectorAll(selector) {
            assert.equal(selector, '[data-storefront-theme-toggle]');
            return controls;
        },
    });
    const window = Object.assign(events(), {
        matchMedia() { throw new Error('Light mode is the default, regardless of system preference.'); },
    });
    Object.defineProperty(window, 'localStorage', {
        get() {
            if (denyAccess) throw new Error('Storage access is disabled.');
            return localStorage;
        },
    });
    const context = vm.createContext({ document, window });
    vm.runInContext(boot, context);
    if (initialize) vm.runInContext(script, context);
    return {
        root, controls, storage, document, window,
        initialize() { vm.runInContext(script, context); },
        click(index = 0, nested = false) {
            const control = controls[index];
            const target = nested ? { closest: (selector) => control.closest(selector) } : control;
            document.emit('click', { target, preventDefault() {} });
        },
        storageEvent(key, newValue) {
            if (key === storageKey || key === null) storage.value = newValue;
            window.emit('storage', { key, newValue, storageArea: localStorage });
        },
    };
}

function assertTheme(page, theme) {
    assert.equal(page.root.getAttribute('data-theme'), theme);
    for (const control of page.controls) {
        assert.equal(control.hidden, false);
        assert.equal(control.getAttribute('aria-checked'), String(theme === 'dark'));
        assert.equal(control.title ?? control.getAttribute('title'), theme === 'dark' ? 'Uključi svijetli prikaz' : 'Uključi tamni prikaz');
        assert.equal(control.getAttribute('aria-label'), 'Tamni prikaz');
    }
}

test('head boot defaults to light and accepts only an explicit saved dark preference', () => {
    for (const saved of [null, '', 'light', 'auto', 'DARK']) {
        const page = harness({ saved, initialize: false });
        assert.equal(page.root.getAttribute('data-theme'), 'light');
        assert.equal(page.controls[0].hidden, true);
    }
    const page = harness({ saved: 'dark', initialize: false });
    assert.equal(page.root.getAttribute('data-theme'), 'dark');
    assert.equal(page.controls[0].hidden, true);
    assert.deepEqual(page.storage.writes, []);
});

test('both header switches initialize from the early preference and stay synchronized on repeated clicks', () => {
    const page = harness({ saved: 'dark' });
    assertTheme(page, 'dark');
    page.click(0, true);
    assertTheme(page, 'light');
    page.click(1);
    assertTheme(page, 'dark');
    page.click(0);
    assertTheme(page, 'light');
    assert.deepEqual(page.storage.writes, ['light', 'dark', 'light']);
    page.document.emit('click', { target: { closest: () => null } });
    assertTheme(page, 'light');
    assert.equal(page.storage.writes.length, 3);
});

test('initialization waits for the controls when the document is still loading', () => {
    const page = harness({ saved: 'dark', readyState: 'loading' });
    assert.equal(page.controls[0].hidden, true);
    page.document.readyState = 'interactive';
    page.document.emit('DOMContentLoaded');
    assertTheme(page, 'dark');
    page.click();
    assertTheme(page, 'light');
    assert.deepEqual(page.storage.writes, ['light']);
});

test('blocked storage access leaves a working light default and an immediate usable switch', () => {
    const page = harness({ denyAccess: true });
    assertTheme(page, 'light');
    page.click();
    assertTheme(page, 'dark');
    page.click(1);
    assertTheme(page, 'light');
});

test('a rejected preference write does not prevent changing either header control', () => {
    const page = harness();
    page.storage.denyWrite = true;
    page.click();
    assertTheme(page, 'dark');
    page.click(1);
    assertTheme(page, 'light');
    assert.deepEqual(page.storage.writes, []);
});

test('changes in another tab synchronize controls, ignore unrelated keys, and clear invalid preferences', () => {
    const page = harness();
    page.storageEvent(storageKey, 'dark');
    assertTheme(page, 'dark');
    page.storageEvent('some.other.preference', 'light');
    assertTheme(page, 'dark');
    page.storageEvent(storageKey, 'invalid');
    assertTheme(page, 'light');
    page.storageEvent(storageKey, 'dark');
    assertTheme(page, 'dark');
    page.storageEvent(storageKey, null);
    assertTheme(page, 'light');
    page.storageEvent(storageKey, 'dark');
    page.storageEvent(null, null);
    assertTheme(page, 'light');
    assert.deepEqual(page.storage.writes, []);
});

test('returning through browser history refreshes the preference and keeps the current theme if reading fails', () => {
    const page = harness();
    page.storage.value = 'dark';
    page.window.emit('pageshow', { persisted: false });
    assertTheme(page, 'light');
    page.window.emit('pageshow', { persisted: true });
    assertTheme(page, 'dark');
    page.storage.value = 'light';
    page.storage.denyRead = true;
    page.window.emit('pageshow', { persisted: true });
    assertTheme(page, 'dark');
    page.storage.denyRead = false;
    page.window.emit('pageshow', { persisted: true });
    assertTheme(page, 'light');
    assert.deepEqual(page.storage.writes, []);
});
