import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../public/front-theme/scripts/storefront-page-reveal.js', import.meta.url), 'utf8');
const layout = readFileSync(new URL('../../resources/views/front/desktop/layouts/store.blade.php', import.meta.url), 'utf8');
const boot = layout.match(/<script data-storefront-page-reveal-boot>([\s\S]*?)<\/script>/)[1];
const loading = 'data-storefront-page-loading';
const ready = 'data-storefront-page-ready';
const deferred = () => {
    let resolve;
    const promise = new Promise((done) => { resolve = done; });
    return { promise, resolve };
};
const flush = async () => {
    for (let i = 0; i < 12; i++) await Promise.resolve();
};

function page({ images: states = [], fonts = Promise.resolve(), domReady = true,
    readyState = domReady ? 'complete' : 'loading', bootstrap = true, controller = true } = {}) {
    const listeners = new Map();
    const root = {
        attributes: new Map(),
        setAttribute(name, value) { this.attributes.set(name, value); },
        removeAttribute(name) { this.attributes.delete(name); },
        hasAttribute(name) { return this.attributes.has(name); },
    };
    class Image {
        constructor(state) {
            Object.assign(this, {
                complete: true, naturalWidth: 100, currentSrc: '/image.svg',
                rect: { top: 20, bottom: 120, left: 20, right: 120, width: 100, height: 100 },
                style: { visibility: 'visible', display: 'block', opacity: '1' },
            }, state);
            this.listeners = new Map();
            this.decodeCalls = 0;
        }
        getAttribute(name) { return name === 'src' ? this.currentSrc : null; }
        closest() { return this.overlay ? {} : null; }
        getBoundingClientRect() { return this.rect; }
        addEventListener(type, callback) { this.listeners.set(type, callback); }
        removeEventListener(type) { this.listeners.delete(type); }
        emit(type) { this.listeners.get(type)?.(); }
        decode() { this.decodeCalls++; return this.decoding || Promise.resolve(); }
    }
    const images = states.map((state) => new Image(state));
    const document = {
        documentElement: root, images, fonts: { ready: fonts },
        readyState,
        addEventListener(type, callback) { listeners.set(`document:${type}`, callback); },
    };
    let now = 0;
    let timerId = 0;
    const timers = new Map();
    const frames = [];
    const window = {
        innerWidth: 390, innerHeight: 800,
        addEventListener(type, callback) {
            listeners.set(type, [...(listeners.get(type) || []), callback]);
        },
        setTimeout(callback, delay) {
            timers.set(++timerId, { callback, at: now + delay });
            return timerId;
        },
        clearTimeout(id) { timers.delete(id); },
        requestAnimationFrame(callback) { frames.push(callback); },
        getComputedStyle: (image) => image.style,
    };
    const context = { window, document };
    if (bootstrap) vm.runInNewContext(boot, context);
    if (controller) vm.runInNewContext(script, context);
    return {
        root, images, frames,
        domReady() { document.readyState = 'interactive'; listeners.get('document:DOMContentLoaded')?.(); },
        emit(type, event = {}) { for (const callback of listeners.get(type) || []) callback(event); },
        frame() { frames.splice(0).forEach((callback) => callback()); },
        advance(ms) {
            now += ms;
            for (const [id, timer] of [...timers]) {
                if (timer.at <= now) { timers.delete(id); timer.callback(); }
            }
        },
    };
}

test('bootstrap covers every viewport and releases even when the deferred controller is missing', () => {
    // The harness has no matchMedia: reveal is intentionally viewport independent.
    const p = page({ controller: false });
    assert.equal(p.root.hasAttribute(loading), true);
    p.advance(4999);
    assert.equal(p.root.hasAttribute(loading), true);
    p.advance(1);
    assert.equal(p.root.hasAttribute(loading), false);
});

test('without the early bootstrap the controller leaves the visible page alone', async () => {
    const p = page({ bootstrap: false, images: [{}] });
    await flush();
    assert.equal(p.root.attributes.size, 0);
    assert.equal(p.images[0].decodeCalls, 0);
    assert.equal(p.frames.length, 0);
});

test('cached above-fold images decode before two settled frames and the short fade', async () => {
    const p = page({ images: [{}] });
    await flush();
    assert.equal(p.images[0].decodeCalls, 1);
    assert.equal(p.root.hasAttribute(ready), false);
    p.frame();
    assert.equal(p.root.hasAttribute(ready), false);
    p.frame();
    assert.equal(p.root.hasAttribute(ready), true);
    assert.equal(p.root.hasAttribute(loading), true);
    p.frame();
    assert.equal(p.root.hasAttribute(loading), false);
    p.advance(220);
    assert.equal(p.root.attributes.size, 0);
});

test('DOM, fonts and the current visible image settle before revealing', async () => {
    const fonts = deferred();
    const decoding = deferred();
    const p = page({ domReady: false, fonts: fonts.promise,
        images: [{ complete: false, decoding: decoding.promise }] });
    await flush();
    assert.equal(p.images[0].listeners.size, 0);
    p.domReady();
    assert.equal(p.images[0].listeners.size, 2);
    p.emit('pageshow', { persisted: false });
    assert.equal(p.root.hasAttribute(loading), true);
    fonts.resolve();
    p.images[0].emit('load');
    await flush();
    assert.equal(p.frames.length, 0);
    decoding.resolve();
    await flush();
    assert.equal(p.frames.length, 1);
    p.frame(); p.frame(); p.frame();
    assert.equal(p.root.hasAttribute(loading), false);
});

test('interactive documents wait for later deferred initializers and DOMContentLoaded', async () => {
    const p = page({ readyState: 'interactive', images: [{}] });
    await flush();
    p.frame(); p.frame(); p.advance(1500);
    assert.equal(p.images[0].decodeCalls, 0);
    assert.equal(p.root.hasAttribute(ready), false);
    // The later deferred initializer completes before DOMContentLoaded.
    p.images[0].rect.top = 1000;
    p.domReady();
    await flush();
    assert.equal(p.images[0].decodeCalls, 0);
    p.frame(); p.frame(); p.frame();
    assert.equal(p.root.hasAttribute(loading), false);
});

test('below-fold, offscreen, hidden and overlay images are never decoded or awaited', async () => {
    const p = page({ images: [
        { complete: false, loading: 'lazy', rect: { top: 900, bottom: 1000, left: 0, right: 100, width: 100, height: 100 } },
        { complete: false, rect: { top: 0, bottom: 100, left: -100, right: 0, width: 100, height: 100 } },
        { complete: false, style: { visibility: 'hidden', display: 'block', opacity: '1' } },
        { complete: false, style: { visibility: 'visible', display: 'block', opacity: '0' } },
        { complete: false, overlay: true },
        { complete: false, currentSrc: '' },
    ] });
    await flush();
    for (const image of p.images) {
        assert.equal(image.decodeCalls, 0);
        assert.equal(image.listeners.size, 0);
    }
    p.frame(); p.frame(); p.frame();
    assert.equal(p.root.hasAttribute(loading), false);
});

test('image failures and rejected font/decode promises do not hold the veil', async () => {
    const failedDecode = deferred();
    const failedFonts = deferred();
    const p = page({ fonts: failedFonts.promise, images: [
        { naturalWidth: 0 }, { decoding: failedDecode.promise }, { complete: false, naturalWidth: 0 },
    ] });
    failedDecode.resolve(Promise.reject(new Error('decode failed')));
    failedFonts.resolve(Promise.reject(new Error('fonts failed')));
    p.images[2].emit('error');
    await flush();
    p.frame(); p.frame(); p.frame();
    assert.equal(p.root.hasAttribute(loading), false);
    assert.equal(p.images[0].decodeCalls, 0);
});

test('stalled resources release at the DOM deadline and remove image listeners', async () => {
    const p = page({ fonts: new Promise(() => {}), images: [{ complete: false }] });
    p.advance(1499);
    assert.equal(p.frames.length, 0);
    p.advance(1);
    p.frame(); p.frame(); p.frame();
    assert.equal(p.root.hasAttribute(loading), false);
    assert.equal(p.images[0].listeners.size, 0);
    await flush();
    assert.equal(p.frames.length, 0);
});

test('pagehide and BFCache pageshow clear waiting and fading states permanently', async () => {
    const fonts = deferred();
    const waiting = page({ fonts: fonts.promise, images: [{ complete: false }] });
    waiting.emit('pagehide');
    assert.equal(waiting.root.attributes.size, 0);
    assert.equal(waiting.images[0].listeners.size, 0);
    fonts.resolve();
    await flush();
    waiting.advance(5000);
    waiting.frame();
    assert.equal(waiting.root.attributes.size, 0);

    const fading = page();
    await flush();
    fading.frame(); fading.frame();
    assert.equal(fading.root.hasAttribute(ready), true);
    fading.emit('pageshow', { persisted: true });
    fading.frame(); fading.advance(5000);
    assert.equal(fading.root.attributes.size, 0);

    const bootOnly = page({ controller: false });
    bootOnly.emit('pageshow', { persisted: true });
    assert.equal(bootOnly.root.attributes.size, 0);
});
