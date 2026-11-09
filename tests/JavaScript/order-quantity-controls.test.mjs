import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const read = (path) => readFileSync(new URL('../../public/front-theme/scripts/' + path, import.meta.url), 'utf8');

test('pack quantities round upward from the configured minimum and stop at the last valid package', () => {
    const window = {};
    vm.runInNewContext(read('order-quantity.js'), { window });
    const input = { min: '4', step: '4', max: '999', value: '4' };
    assert.equal(window.ShopOrderQuantity.normalize(input, 1), 4);
    assert.equal(window.ShopOrderQuantity.normalize(input, 5), 8);
    assert.equal(window.ShopOrderQuantity.normalize(input, 999), 996);
    assert.equal(window.ShopOrderQuantity.move(input, -1), 4);
    assert.equal(window.ShopOrderQuantity.move(input, 1), 8);
    // The minimum is the anchor; a minimum of 2 with a step of 3 permits 2, 5, 8.
    assert.equal(window.ShopOrderQuantity.normalize({ min: '2', step: '3', max: '10' }, 6), 8);
    assert.equal(window.ShopOrderQuantity.normalize({ min: '2', step: '3', max: '10' }, 10), 8);
});

test('cart controls change a battery pack by four pieces and never drop below its minimum', () => {
    const input = { min: '4', step: '4', max: '999', value: '4', dispatchEvent() {} };
    const clicks = new Map();
    const decrease = { addEventListener: (_, listener) => clicks.set('decrease', listener) };
    const increase = { addEventListener: (_, listener) => clicks.set('increase', listener) };
    const control = { dataset: {}, querySelector: (selector) => ({ '[data-cart-qty-input]': input, '[data-cart-qty-dec]': decrease, '[data-cart-qty-inc]': increase })[selector] };
    const document = { readyState: 'complete', querySelectorAll: () => [control] };
    const window = {};
    const context = { document, window, Event: class {} };
    vm.runInNewContext(read('order-quantity.js'), context);
    vm.runInNewContext(read('cart-quantity.js'), context);
    clicks.get('increase')();
    assert.equal(input.value, '8');
    clicks.get('decrease')();
    clicks.get('decrease')();
    assert.equal(input.value, '4');
});

test('product card controls preserve the imported package step', () => {
    const input = { min: '2', step: '2', max: '999', value: '2' };
    let click;
    const control = { querySelector: () => input };
    class Element {
        constructor(increase) { this.increase = increase; }
        closest(selector) { return selector === '[data-product-detail-form]' ? null : selector === '[data-qty-control]' ? control : this; }
        matches() { return this.increase; }
    }
    const document = { readyState: 'complete', addEventListener: (_, listener) => { click = listener; } };
    const window = {};
    const context = { document, window, Element };
    vm.runInNewContext(read('order-quantity.js'), context);
    vm.runInNewContext(read('product-card-quantity.js'), context);
    click({ target: new Element(true) });
    assert.equal(input.value, '4');
    click({ target: new Element(false) });
    click({ target: new Element(false) });
    assert.equal(input.value, '2');
});
