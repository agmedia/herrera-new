import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/front-theme/scripts/product-detail.js', import.meta.url), 'utf8');
const quantitySource = readFileSync(new URL('../../public/front-theme/scripts/order-quantity.js', import.meta.url), 'utf8');

test('whole-box submit sends the package and confirms actual pieces without changing the piece quantity control', async () => {
    const listeners = new Map();
    const ready = new Map();
    const quantity = { value: '4', min: '4', step: '4', max: '999', dispatchEvent() {} };
    const form = {
        action: '/cart/items', dataset: {},
        closest() { return null; },
        querySelector(selector) { return selector === 'input[name="quantity"]' ? quantity : null; },
        querySelectorAll() { return []; },
        addEventListener(name, listener) { listeners.set(name, listener); },
    };
    const document = {
        documentElement: { lang: 'hr' },
        addEventListener(name, listener) { ready.set(name, listener); },
        querySelector(selector) { return selector.startsWith('[data-product-detail],') ? form : null; },
        querySelectorAll(selector) { return selector === '[data-product-detail-form]' ? [form] : []; },
        dispatchEvent() {},
    };
    let confirmation;
    let analytics;
    let sent;
    const window = {
        TermolCartModal: { confirm(...args) { confirmation = args; } },
        ShopAnalytics: { trackAddToCartFromForm(...args) { analytics = args; } },
    };
    class FormData {
        constructor() { this.data = new Map([['product_id', '12'], ['quantity', quantity.value]]); }
        set(key, value) { this.data.set(key, value); }
        get(key) { return this.data.get(key); }
    }
    vm.runInNewContext(quantitySource, { window });
    vm.runInNewContext(source, {
        document, window, FormData, Event: class {}, CustomEvent: class {},
        fetch: async (url, options) => {
            sent = { url, body: options.body };
            return { ok: true, json: async () => ({ ok: true, added_quantity: 24, summary: { item_qty: 24 } }) };
        },
    });
    ready.get('DOMContentLoaded')();
    await listeners.get('submit')({
        preventDefault() {},
        submitter: { value: '8', hasAttribute(name) { return name === 'data-product-box-quantity'; } },
    });
    assert.equal(sent.url, '/cart/items');
    assert.equal(sent.body.get('product_package_id'), '8');
    assert.equal(sent.body.get('package_count'), '1');
    assert.equal(sent.body.get('quantity'), '4');
    assert.equal(confirmation[2], 24);
    assert.equal(analytics[1], 24);
    assert.equal(quantity.value, '4');
});
