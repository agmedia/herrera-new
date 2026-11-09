import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/front-theme/scripts/product-detail.js', import.meta.url), 'utf8');
const quantitySource = readFileSync(new URL('../../public/front-theme/scripts/order-quantity.js', import.meta.url), 'utf8');

for (const selectorType of ['radio', 'select']) {
    test(`${selectorType} option selection updates SKU, price, stock and quantity together`, () => {
        const ready = new Map();
        const fieldListeners = new Map();
        let selected = false;
        const output = () => ({ textContent: '', classList: { toggle() {} } });
        const sku = output();
        const price = output();
        const stock = output();
        const supplierStock = output();
        const relatedStock = { ...output(), textContent: '42' };
        const relatedSupplierStock = { ...output(), textContent: '73' };
        const quantity = { value: '8', max: '', dispatchEvent() {} };
        const option = {
            dataset: { optionSku: 'LAMP-20W', optionStock: '3', optionPriceCurrent: '25.00 €', optionPriceCurrentValue: '25.00' },
            addEventListener(type, listener) { fieldListeners.set(type, listener); },
        };
        const select = {
            disabled: false, selectedIndex: 0, options: [option],
            get value() { return selected ? '1' : ''; },
            addEventListener(type, listener) { fieldListeners.set(type, listener); },
        };
        const field = selectorType === 'radio' ? option : select;
        const displayRoot = {
            querySelectorAll: (selector) => ({ '[data-product-local-stock]': [stock], '[data-product-supplier-stock]': [supplierStock] })[selector] || [],
        };
        const form = {
            closest: (selector) => selector === '[data-product-detail]' ? displayRoot : null,
            dataset: {
                productBaseSku: 'LAMP', productStockVisible: '1', productDefaultLocalStock: '5', productDefaultSupplierStock: '4',
                productDefaultPriceCurrent: '10.00 €', productDefaultPriceCurrentValue: '10.00',
            },
            querySelector(selector) {
                if (selector === 'input[name="quantity"]') return quantity;
                if (selector === 'input[name="product_option_value_id"]:checked') return selectorType === 'radio' && selected ? option : null;
                if (selector === 'select[name="product_option_value_id"]') return selectorType === 'select' ? select : null;
                return null;
            },
            querySelectorAll() { return [field]; },
            addEventListener() {},
        };
        const document = {
            documentElement: { lang: 'hr' },
            addEventListener(type, listener) { ready.set(type, listener); },
            querySelector: (selector) => selector.startsWith('[data-product-detail],') ? form : null,
            querySelectorAll(selector) {
                return {
                    '[data-product-detail-form]': [form],
                    '[data-product-sku-value]': [sku],
                    '[data-product-price-current]': [price],
                    '[data-product-local-stock]': [stock, relatedStock],
                    '[data-product-supplier-stock]': [supplierStock, relatedSupplierStock],
                }[selector] || [];
            },
        };
        const window = {};
        vm.runInNewContext(quantitySource, { window });
        vm.runInNewContext(source, { document, window, Event: class {} });
        ready.get('DOMContentLoaded')();
        assert.equal(sku.textContent, 'LAMP');
        assert.equal(price.textContent, '10.00 €');
        assert.equal(stock.textContent, '5');
        assert.equal(supplierStock.textContent, '4');

        selected = true;
        fieldListeners.get('change')();
        assert.equal(sku.textContent, 'LAMP-20W');
        assert.equal(price.textContent, '25.00 €');
        assert.equal(stock.textContent, '3');
        assert.equal(supplierStock.textContent, '0');
        assert.equal(quantity.max, '3');
        assert.equal(quantity.value, '3');
        assert.equal(relatedStock.textContent, '42');
        assert.equal(relatedSupplierStock.textContent, '73');

        selected = false;
        fieldListeners.get('change')();
        assert.equal(sku.textContent, 'LAMP');
        assert.equal(price.textContent, '10.00 €');
        assert.equal(stock.textContent, '5');
        assert.equal(supplierStock.textContent, '4');
        assert.equal(quantity.max, '9');
    });
}

for (const selectorType of ['radio', 'select']) {
    test(`${selectorType} customer selection updates delivery without receiving exact stock or constraining quantity to it`, () => {
        const ready = new Map();
        const listeners = new Map();
        let selected = false;
        const delivery = { textContent: '', classList: { toggle() {} } };
        const relatedDelivery = { textContent: '22–26 dana', classList: { toggle() {} } };
        const quantity = { value: '8', max: '', dispatchEvent() {} };
        const option = {
            dataset: { optionSku: 'LAMP-20W', optionAvailable: '1' },
            addEventListener(type, listener) { listeners.set(type, listener); },
        };
        const select = {
            disabled: false, selectedIndex: 0, options: [option],
            get value() { return selected ? '1' : ''; },
            addEventListener(type, listener) { listeners.set(type, listener); },
        };
        const form = {
            closest: (selector) => selector === '[data-product-detail]' ? { querySelectorAll: (selector) => selector === '[data-product-delivery-time]' ? [delivery] : [] } : null,
            dataset: {
                productBaseSku: 'LAMP', productStockVisible: '0',
                productDefaultLocalAvailable: '0', productDefaultSupplierAvailable: '1',
                productDeliveryLocal: '48 sati', productDeliverySupplier: '14 dana', productDeliveryUnavailable: 'Nedostupno',
            },
            querySelector(selector) {
                if (selector === 'input[name="quantity"]') return quantity;
                if (selector === 'input[name="product_option_value_id"]:checked') return selectorType === 'radio' && selected ? option : null;
                if (selector === 'select[name="product_option_value_id"]') return selectorType === 'select' ? select : null;
                return null;
            },
            querySelectorAll() { return [selectorType === 'radio' ? option : select]; },
            addEventListener() {},
        };
        const document = {
            documentElement: { lang: 'hr' },
            addEventListener(type, listener) { ready.set(type, listener); },
            querySelector: (selector) => selector.startsWith('[data-product-detail],') ? form : null,
            querySelectorAll(selector) {
                return {
                    '[data-product-detail-form]': [form],
                    '[data-product-delivery-time]': [delivery, relatedDelivery],
                }[selector] || [];
            },
        };
        const window = {};
        vm.runInNewContext(quantitySource, { window });
        vm.runInNewContext(source, { document, window, Event: class {} });
        ready.get('DOMContentLoaded')();
        assert.equal(delivery.textContent, '14 dana');
        assert.equal(relatedDelivery.textContent, '22–26 dana');
        assert.equal(quantity.max, '999');
        selected = true;
        listeners.get('change')();
        assert.equal(delivery.textContent, '48 sati');
        assert.equal(relatedDelivery.textContent, '22–26 dana');
        assert.equal(quantity.max, '999');
        assert.equal(quantity.value, '8');
        option.dataset.optionAvailable = '0';
        listeners.get('change')();
        assert.equal(delivery.textContent, 'Nedostupno');
        assert.equal(quantity.max, '1');
        assert.equal(quantity.value, '1');
        assert.equal(relatedDelivery.textContent, '22–26 dana');
    });
}

test('main product quantity and option requests never overwrite a related card delivery estimate', async () => {
    const ready = new Map();
    const formListeners = new Map();
    const optionListeners = new Map();
    const timers = new Map();
    const requests = [];
    let timerId = 0;
    let selected = false;
    const mainDelivery = { textContent: '', classList: { toggle() {} } };
    const relatedDelivery = { textContent: '5–9 dana', classList: { toggle() {} } };
    const quantity = { value: '4', min: '4', step: '4', max: '999', dispatchEvent() {}, matches: (selector) => selector === 'input[name="quantity"]' };
    const option = { dataset: { optionAvailable: '1' }, addEventListener: (type, listener) => optionListeners.set(type, listener) };
    const displayRoot = { querySelectorAll: (selector) => selector === '[data-product-delivery-time]' ? [mainDelivery] : [] };
    const form = {
        dataset: {
            productStockVisible: '0', productDefaultLocalAvailable: '0', productDefaultSupplierAvailable: '1',
            productDeliveryLocal: '48 sati', productDeliverySupplier: '14 dana', productDeliveryUnavailable: 'Nedostupno',
            productDeliveryUrl: '/product/lamp/delivery',
        },
        closest: (selector) => selector === '[data-product-detail]' ? displayRoot : null,
        querySelector(selector) {
            if (selector === 'input[name="quantity"]') return quantity;
            if (selector === 'input[name="product_option_value_id"]:checked') return selected ? option : null;
            return null;
        },
        querySelectorAll: (selector) => selector.includes('product_option_value_id') ? [option] : [],
        addEventListener: (type, listener) => formListeners.set(type, listener),
    };
    const document = {
        documentElement: { lang: 'hr' },
        addEventListener: (type, listener) => ready.set(type, listener),
        querySelector: (selector) => selector.startsWith('[data-product-detail],') ? displayRoot : null,
        querySelectorAll: (selector) => ({
            '[data-product-detail-form]': [form],
            '[data-product-delivery-time]': [mainDelivery, relatedDelivery],
        })[selector] || [],
    };
    const window = {};
    const context = {
        document, window, Event: class {}, URLSearchParams, AbortController,
        FormData: class { get(name) { return name === 'product_option_value_id' && selected ? '7' : null; } },
        setTimeout: (callback) => { timers.set(++timerId, callback); return timerId; },
        clearTimeout: (id) => timers.delete(id),
        fetch: async (url) => {
            requests.push(url);
            return { ok: true, json: async () => ({ label: selected ? '19–23 dana' : '18–22 dana' }) };
        },
    };
    const runLatestRequest = async () => {
        const [id, callback] = [...timers.entries()].at(-1);
        timers.delete(id);
        await callback();
    };
    vm.runInNewContext(quantitySource, context);
    vm.runInNewContext(source, context);
    ready.get('DOMContentLoaded')();
    assert.equal(mainDelivery.textContent, '14 dana');
    assert.equal(relatedDelivery.textContent, '5–9 dana');
    await runLatestRequest();
    assert.equal(mainDelivery.textContent, '18–22 dana');
    assert.equal(relatedDelivery.textContent, '5–9 dana');

    quantity.value = '12';
    formListeners.get('change')({ target: quantity });
    await runLatestRequest();
    assert.match(requests.at(-1), /quantity=12/);
    assert.equal(relatedDelivery.textContent, '5–9 dana');

    selected = true;
    optionListeners.get('change')();
    assert.equal(mainDelivery.textContent, '48 sati');
    assert.equal(relatedDelivery.textContent, '5–9 dana');
    await runLatestRequest();
    assert.match(requests.at(-1), /product_option_value_id=7/);
    assert.equal(mainDelivery.textContent, '19–23 dana');
    assert.equal(relatedDelivery.textContent, '5–9 dana');
});
