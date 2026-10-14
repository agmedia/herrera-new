import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../public/front-theme/scripts/reference-gallery.js', import.meta.url), 'utf8');

function harness({ count = 3, invalidJson = false, supported = true } = {}) {
    let document;
    const element = () => ({
        listeners: new Map(), dataset: {}, style: {}, hidden: false, disabled: false, attributes: new Map(), isConnected: true,
        addEventListener(type, callback) { this.listeners.set(type, [...(this.listeners.get(type) || []), callback]); },
        emit(type, event = {}) { for (const callback of this.listeners.get(type) || []) callback(event); },
        setAttribute(name, value) { this.attributes.set(name, String(value)); },
        getAttribute(name) { return this.attributes.get(name) ?? null; },
        focus() { document.activeElement = this; },
        closest() { return null; },
        scrollIntoView() {},
    });
    const root = element();
    const dialog = element();
    const image = element();
    const caption = element();
    const counter = element();
    const close = element();
    const previous = element();
    const next = element();
    const stage = element();
    const thumbnails = Array.from({ length: count }, (_, index) => Object.assign(element(), { dataset: { referenceLightboxThumb: String(index) } }));
    const triggers = Array.from({ length: count }, (_, index) => {
        const trigger = Object.assign(element(), { dataset: { referenceOpen: String(index) } });
        trigger.closest = (selector) => selector === '[data-reference-open]' ? trigger : null;
        return trigger;
    });
    const items = Array.from({ length: count }, (_, index) => ({ src: `/photo-${index}.webp`, alt: `Photo ${index}`, caption: `Caption ${index}` }));
    const data = { textContent: invalidJson ? '{broken' : JSON.stringify(items) };
    const selectors = new Map([
        ['[data-reference-lightbox-image]', image], ['[data-reference-lightbox-caption]', caption],
        ['[data-reference-lightbox-count]', counter], ['[data-reference-lightbox-close]', close],
        ['[data-reference-lightbox-prev]', previous], ['[data-reference-lightbox-next]', next],
        ['[data-reference-lightbox-stage]', stage],
    ]);
    root.querySelector = (selector) => selector === '[data-reference-lightbox]' ? dialog : selector === '[data-reference-gallery-items]' ? data : null;
    root.contains = (trigger) => triggers.includes(trigger);
    dialog.querySelector = (selector) => selectors.get(selector) || null;
    dialog.querySelectorAll = (selector) => selector === '[data-reference-lightbox-thumb]' ? thumbnails : [close, previous, next, ...thumbnails];
    dialog.dataset.countTemplate = 'Fotografija :current od :total';
    dialog.open = false;
    if (supported) dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { dialog.open = false; dialog.emit('close'); };
    const body = { style: { overflow: 'auto', position: '', top: '', left: '', width: '', paddingRight: '5px' } };
    document = { body, documentElement: { clientWidth: 1000, style: { scrollBehavior: 'smooth' } }, activeElement: triggers[0], querySelector: () => root };
    const scrolls = [];
    const window = { scrollX: 0, scrollY: 520, innerWidth: 1024, getComputedStyle: () => ({ paddingRight: '10px' }), scrollTo: (...args) => scrolls.push(args) };
    const context = vm.createContext({ document, window });
    vm.runInContext(script, context);
    return {
        root, dialog, image, caption, counter, close, previous, next, stage, thumbnails, triggers, body, document, scrolls,
        initialize() { vm.runInContext(script, context); },
        click(index = 0, extras = {}) {
            let prevented = false;
            root.emit('click', { target: triggers[index], button: 0, preventDefault() { prevented = true; }, ...extras });
            return prevented;
        },
        key(key, shiftKey = false) {
            let prevented = false;
            dialog.emit('keydown', { key, shiftKey, preventDefault() { prevented = true; } });
            return prevented;
        },
    };
}

test('click opens selected image with captions, count and thumbnail; arrows wrap without navigating away', () => {
    const page = harness();
    assert.equal(page.click(1), true);
    assert.equal(page.dialog.open, true);
    assert.equal(page.image.src, '/photo-1.webp');
    assert.equal(page.counter.textContent, 'Fotografija 2 od 3');
    assert.equal(page.thumbnails[1].getAttribute('aria-current'), 'true');
    page.next.emit('click');
    page.next.emit('click');
    assert.equal(page.image.src, '/photo-0.webp');
    page.previous.emit('click');
    assert.equal(page.image.src, '/photo-2.webp');
    page.thumbnails[0].emit('click');
    assert.equal(page.caption.textContent, 'Caption 0');
});

test('Escape closes, restores the original body styles and page position, and returns focus to opener', () => {
    const page = harness();
    page.click(2);
    assert.equal(page.body.style.position, 'fixed');
    assert.equal(page.body.style.top, '-520px');
    assert.equal(page.body.style.paddingRight, '34px');
    assert.equal(page.document.activeElement, page.close);
    assert.equal(page.key('Escape'), true);
    assert.equal(page.dialog.open, false);
    assert.equal(page.body.style.overflow, 'auto');
    assert.equal(page.body.style.position, '');
    assert.equal(page.body.style.paddingRight, '5px');
    assert.deepEqual(page.scrolls, [[0, 520]]);
    assert.equal(page.document.activeElement, page.triggers[2]);
});

test('keyboard navigation and focus trap keep interaction inside the dialog', () => {
    const page = harness();
    page.click();
    page.key('ArrowLeft');
    assert.equal(page.image.src, '/photo-2.webp');
    page.key('Home');
    assert.equal(page.image.src, '/photo-0.webp');
    page.key('End');
    assert.equal(page.image.src, '/photo-2.webp');
    page.key('Tab', true);
    assert.equal(page.document.activeElement, page.thumbnails[2]);
    page.key('Tab');
    assert.equal(page.document.activeElement, page.close);
});

test('backdrop and native cancel close, while clicking the photograph leaves the dialog open', () => {
    const page = harness();
    page.click();
    page.dialog.emit('click', { target: page.image });
    assert.equal(page.dialog.open, true);
    page.dialog.emit('click', { target: page.stage });
    assert.equal(page.dialog.open, false);
    page.click();
    let prevented = false;
    page.dialog.emit('cancel', { preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(page.dialog.open, false);
});

test('horizontal swipe changes photo; vertical scroll and multi-touch do not', () => {
    const page = harness();
    page.click();
    page.stage.emit('touchstart', { target: page.image, touches: [{ clientX: 200, clientY: 100 }] });
    page.stage.emit('touchend', { changedTouches: [{ clientX: 100, clientY: 115 }] });
    assert.equal(page.image.src, '/photo-1.webp');
    page.stage.emit('touchstart', { target: page.image, touches: [{ clientX: 200, clientY: 100 }] });
    page.stage.emit('touchend', { changedTouches: [{ clientX: 180, clientY: 240 }] });
    assert.equal(page.image.src, '/photo-1.webp');
    page.stage.emit('touchstart', { target: page.image, touches: [{ clientX: 200, clientY: 100 }, { clientX: 250, clientY: 100 }] });
    page.stage.emit('touchend', { changedTouches: [{ clientX: 100, clientY: 100 }] });
    assert.equal(page.image.src, '/photo-1.webp');
});

test('one-photo galleries hide irrelevant arrows; repeated initialization does not double bind', () => {
    const page = harness({ count: 1 });
    page.initialize();
    page.click();
    assert.equal(page.previous.hidden, true);
    assert.equal(page.next.hidden, true);
    assert.equal(page.root.listeners.get('click').length, 1);
});

test('unsupported dialogs, invalid data and modified clicks preserve the original image link fallback', () => {
    for (const options of [{ supported: false }, { invalidJson: true }]) {
        const page = harness(options);
        assert.equal(page.click(), false);
        assert.equal(page.dialog.open, false);
    }
    const page = harness();
    assert.equal(page.click(0, { ctrlKey: true }), false);
    assert.equal(page.dialog.open, false);
});
