import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/front-theme/scripts/product-detail.js', import.meta.url), 'utf8');

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
        const form = {
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
                    '[data-product-local-stock]': [stock],
                    '[data-product-supplier-stock]': [supplierStock],
                }[selector] || [];
            },
        };
        vm.runInNewContext(source, { document, window: {}, Event: class {} });
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
                    '[data-product-delivery-time]': [delivery],
                }[selector] || [];
            },
        };
        vm.runInNewContext(source, { document, window: {}, Event: class {} });
        ready.get('DOMContentLoaded')();
        assert.equal(delivery.textContent, '14 dana');
        assert.equal(quantity.max, '99');
        selected = true;
        listeners.get('change')();
        assert.equal(delivery.textContent, '48 sati');
        assert.equal(quantity.max, '99');
        assert.equal(quantity.value, '8');
        option.dataset.optionAvailable = '0';
        listeners.get('change')();
        assert.equal(delivery.textContent, 'Nedostupno');
        assert.equal(quantity.max, '1');
        assert.equal(quantity.value, '1');
    });
}
