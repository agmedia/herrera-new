import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../public/front-theme/scripts/header-cart-popover.js', import.meta.url), 'utf8');

function harness({ keys = Array.from({ length: 20 }, (_, index) => `${index + 1}:0`), height = 800 } = {}) {
    let now = 0;
    let timerId = 0;
    let fixtureId = 0;
    const timers = new Map();
    const fixtures = new Map();
    const requests = [];

    class Node {
        constructor() {
            this.children = [];
            this.parentNode = null;
            this.listeners = new Map();
        }

        addEventListener(type, callback) {
            this.listeners.set(type, [...(this.listeners.get(type) || []), callback]);
        }

        dispatchEvent(event) {
            event.target ??= this;
            event.preventDefault ??= function () { this.defaultPrevented = true; };
            event.currentTarget = this;
            for (const callback of this.listeners.get(event.type) || []) callback(event);
            if (event.bubbles && this.parentNode) this.parentNode.dispatchEvent(event);
            return !event.defaultPrevented;
        }

        emit(type, properties = {}) {
            const event = { type, ...properties };
            this.dispatchEvent(event);
            return event;
        }

        appendChild(child) {
            child.parentNode = this;
            this.children.push(child);
            return child;
        }

        contains(target) {
            return this === target || this.children.some((child) => child.contains(target));
        }

        get isConnected() {
            return document.contains(this);
        }
    }

    class Element extends Node {
        constructor(tag = 'div', { classes = [], data = {} } = {}) {
            super();
            this.tagName = tag.toUpperCase();
            this.dataset = { ...data };
            this.attributes = new Map();
            this.textContent = '';
            const classNames = new Set(classes);
            this.classList = {
                add: (name) => classNames.add(name),
                remove: (name) => classNames.delete(name),
                contains: (name) => classNames.has(name),
            };
            const styles = new Map();
            this.style = {
                setProperty: (name, value) => styles.set(name, value),
                getPropertyValue: (name) => styles.get(name) || '',
            };
        }

        setAttribute(name, value) { this.attributes.set(name, String(value)); }
        getAttribute(name) { return this.attributes.get(name) ?? null; }

        matches(selector) {
            if (selector.startsWith('.')) return this.classList.contains(selector.slice(1));
            const data = selector.match(/^\[data-([\w-]+)\]$/);
            if (data) {
                const key = data[1].replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
                return Object.hasOwn(this.dataset, key);
            }
            if (selector === 'button[type="submit"]') {
                return this.tagName === 'BUTTON' && this.getAttribute('type') === 'submit';
            }
            return this.tagName.toLowerCase() === selector;
        }

        querySelectorAll(selector) {
            const selectors = selector.split(',').map((part) => part.trim());
            const result = [];
            const visit = (node) => {
                for (const child of node.children) {
                    if (child instanceof Element && selectors.some((part) => child.matches(part))) result.push(child);
                    visit(child);
                }
            };
            visit(this);
            return result;
        }

        querySelector(selector) {
            const separator = selector.indexOf(' ');
            if (separator !== -1) {
                const parentSelector = selector.slice(0, separator);
                const childSelector = selector.slice(separator + 1);
                for (const parent of this.querySelectorAll(parentSelector)) {
                    const found = parent.querySelector(childSelector);
                    if (found) return found;
                }
                return null;
            }
            return this.querySelectorAll(selector)[0] ?? null;
        }

        closest(selector) {
            let current = this;
            while (current instanceof Element) {
                if (current.matches(selector)) return current;
                current = current.parentNode;
            }
            return null;
        }

        focus(options = {}) {
            this.lastFocusOptions = options;
            const previous = document.activeElement;
            if (previous === this) return;
            document.activeElement = this;
            previous?.emit('focusout', { bubbles: true, relatedTarget: this });
            this.emit('focusin', { bubbles: true, relatedTarget: previous });
        }

        replaceWith(next) {
            const parent = this.parentNode;
            parent.children[parent.children.indexOf(this)] = next;
            next.parentNode = parent;
            this.parentNode = null;
            if (this.contains(document.activeElement)) document.activeElement = document.body;
        }
    }

    class HTMLFormElement extends Element {
        constructor() { super('form', { data: { headerCartRemove: '' } }); }
    }

    const document = new Element('document');
    document.readyState = 'complete';
    document.body = document.appendChild(new Element('body'));
    document.activeElement = document.body;

    function content(rowKeys) {
        const preview = new Element('div', { data: { headerCartContent: '' } });
        const list = preview.appendChild(new Element('div', { classes: ['header-cart-items'] }));
        list.clientHeight = 248;
        list.scrollHeight = rowKeys.length * 124;
        let scrollTop = 0;
        Object.defineProperty(list, 'scrollTop', {
            get: () => scrollTop,
            set: (value) => { scrollTop = Math.max(0, Math.min(value, list.scrollHeight - list.clientHeight)); },
        });
        const rows = rowKeys.map((key) => {
            const row = list.appendChild(new Element('article', { data: { cartLineKey: key } }));
            row.image = row.appendChild(new Element('a'));
            const copy = row.appendChild(new Element());
            row.name = copy.appendChild(new Element('a'));
            row.form = row.appendChild(new HTMLFormElement());
            row.form.action = `/cart/items/${key.split(':')[0]}`;
            row.remove = row.form.appendChild(new Element('button'));
            row.remove.setAttribute('type', 'submit');
            return row;
        });
        const summary = preview.appendChild(new Element('footer'));
        preview.viewCart = summary.appendChild(new Element('a', { classes: ['header-cart-view-action'] }));
        preview.list = list;
        preview.rows = rows;
        return preview;
    }

    const root = document.body.appendChild(new Element('div', { data: { headerCart: '' } }));
    root.top = 36;
    root.getBoundingClientRect = () => ({ top: root.top });
    const trigger = root.appendChild(new Element('a', { data: { headerCartTrigger: '' } }));
    const popover = root.appendChild(new Element('div', { data: { headerCartPopover: '', previewUrl: '/cart/preview' } }));
    popover.offsetTop = 87;
    const initialContent = popover.appendChild(content(keys));
    const count = trigger.appendChild(new Element('span', { data: { cartCount: '' } }));

    document.createElement = (tag) => {
        assert.equal(tag, 'template');
        const template = { content: new Element() };
        Object.defineProperty(template, 'innerHTML', {
            set(value) { template.content.appendChild(fixtures.get(value)); },
        });
        return template;
    };

    const window = new Node();
    window.innerHeight = height;
    window.matchMedia = () => ({ matches: true });
    window.setTimeout = (callback, delay) => {
        const id = ++timerId;
        timers.set(id, { callback, at: now + delay });
        return id;
    };
    window.clearTimeout = (id) => timers.delete(id);

    const fetch = (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject }));
    class CustomEvent {
        constructor(type, properties = {}) { this.type = type; Object.assign(this, properties); }
    }
    class FormData {
        constructor(form) { this.form = form; }
    }

    vm.runInNewContext(script, { document, window, fetch, Node, Element, HTMLFormElement, CustomEvent, FormData, AbortController });

    return {
        document, window, root, trigger, popover, count, requests, initialContent,
        get currentContent() { return popover.querySelector('[data-header-cart-content]'); },
        advance(duration) {
            const end = now + duration;
            while (true) {
                const next = [...timers.entries()].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
                if (!next) break;
                timers.delete(next[0]);
                now = next[1].at;
                next[1].callback();
            }
            now = end;
        },
        refresh(rowKeys = keys) {
            document.dispatchEvent(new CustomEvent('cart:updated', { detail: { summary: { item_qty: 120 } } }));
            return this.resolvePreview(requests.at(-1), rowKeys);
        },
        resolvePreview(request, rowKeys) {
            const next = content(rowKeys);
            const token = `preview-${++fixtureId}`;
            fixtures.set(token, next);
            request.resolve({ ok: true, text: async () => token });
            return next;
        },
    };
}

async function settle() {
    for (let index = 0; index < 12; index++) await Promise.resolve();
}

function assertOpen(page, expected) {
    assert.equal(page.root.classList.contains('is-open'), expected);
    assert.equal(page.trigger.getAttribute('aria-expanded'), String(expected));
    assert.equal(page.popover.getAttribute('aria-hidden'), String(!expected));
}

test('opening fits remaining viewport space; resize and scroll refit only while open', () => {
    const page = harness();
    page.root.emit('pointerenter');
    assertOpen(page, true);
    assert.equal(page.popover.style.getPropertyValue('--header-cart-popover-top'), '123px');
    assert.equal(page.popover.style.getPropertyValue('--header-cart-available-height'), '661px');

    page.window.innerHeight = 450;
    page.window.emit('resize');
    assert.equal(page.popover.style.getPropertyValue('--header-cart-available-height'), '311px');
    page.root.top = 12;
    page.window.emit('scroll');
    assert.equal(page.popover.style.getPropertyValue('--header-cart-popover-top'), '99px');
    assert.equal(page.popover.style.getPropertyValue('--header-cart-available-height'), '335px');

    page.window.innerHeight = 90;
    page.window.emit('resize');
    assert.equal(page.popover.style.getPropertyValue('--header-cart-available-height'), '0px');
    page.trigger.emit('keydown', { key: 'Escape', bubbles: true });
    page.window.innerHeight = 800;
    page.window.emit('resize');
    assertOpen(page, false);
    assert.equal(page.popover.style.getPropertyValue('--header-cart-available-height'), '0px');
});

test('Escape inside the dropdown returns focus to its trigger and stays closed after focusin', () => {
    const page = harness();
    const remove = page.initialContent.rows[18].remove;
    remove.focus();
    assertOpen(page, true);
    const escape = remove.emit('keydown', { key: 'Escape', bubbles: true });

    assert.equal(escape.defaultPrevented, true);
    assert.equal(page.document.activeElement, page.trigger);
    assert.equal(page.trigger.lastFocusOptions.preventScroll, true);
    assertOpen(page, false);
    page.advance(1000);
    assertOpen(page, false);
});

test('pointerleave retains an open dropdown while keyboard focus is inside and closes after focus leaves', () => {
    const page = harness();
    page.initialContent.rows[10].name.focus();
    page.root.emit('pointerleave');
    page.advance(1000);
    assertOpen(page, true);

    page.document.body.focus();
    page.advance(129);
    assertOpen(page, true);
    page.advance(1);
    assertOpen(page, false);
});

test('async refresh preserves deep scroll and the same row control on the replacement content', async () => {
    const page = harness();
    page.initialContent.list.scrollTop = 2010;
    page.initialContent.rows[17].name.focus();
    const next = page.refresh();

    assert.equal(page.currentContent, page.initialContent);
    await settle();
    assert.equal(page.currentContent, next);
    assert.equal(next.list.scrollTop, 2010);
    assert.equal(page.document.activeElement, next.rows[17].name);
    assert.equal(next.rows[17].name.lastFocusOptions.preventScroll, true);
    assert.equal(page.count.textContent, '120');
    assertOpen(page, true);
});

test('async refresh preserves focus on the list and the footer cart action', async (suite) => {
    for (const target of ['list', 'viewCart']) {
        await suite.test(target, async () => {
            const page = harness();
            page.initialContent.list.scrollTop = 1900;
            page.initialContent[target].focus();
            const next = page.refresh();
            await settle();
            assert.equal(page.document.activeElement, next[target]);
            assert.equal(next.list.scrollTop, 1900);
            assert.equal(next[target].lastFocusOptions.preventScroll, true);
        });
    }
});

test('successful removal focuses the next or nearest remove control and retains or clamps deep scroll', async (suite) => {
    for (const scenario of [
        { name: 'middle row', index: 15, scroll: 1850, nextIndex: 15, expectedScroll: 1850 },
        { name: 'last row', index: 19, scroll: 2232, nextIndex: 18, expectedScroll: 2108 },
    ]) {
        await suite.test(scenario.name, async () => {
            const page = harness();
            const row = page.initialContent.rows[scenario.index];
            page.initialContent.list.scrollTop = scenario.scroll;
            row.remove.focus();
            const submitted = row.form.emit('submit', { bubbles: true });
            assert.equal(submitted.defaultPrevented, true);
            assert.equal(row.remove.disabled, true);
            assert.equal(page.requests[0].options.method, 'POST');

            page.requests[0].resolve({ ok: true, json: async () => ({ ok: true, summary: { item_qty: 19 } }) });
            await settle();
            assert.equal(page.requests.length, 2);
            const remainingKeys = page.initialContent.rows.filter((item) => item !== row).map((item) => item.dataset.cartLineKey);
            const next = page.resolvePreview(page.requests[1], remainingKeys);
            await settle();

            assert.equal(page.document.activeElement, next.rows[scenario.nextIndex].remove);
            assert.equal(next.rows[scenario.nextIndex].remove.lastFocusOptions.preventScroll, true);
            assert.equal(next.list.scrollTop, scenario.expectedScroll);
            assert.equal(page.count.textContent, '19');
            assertOpen(page, true);
        });
    }
});
