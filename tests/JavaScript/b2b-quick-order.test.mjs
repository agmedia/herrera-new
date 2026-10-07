import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../public/front-theme/scripts/b2b-quick-order.js', import.meta.url), 'utf8');
const product = (overrides = {}) => ({key: '1:0', product_id: 1, product_option_value_id: null, name: 'Radne rukavice', identifier: 'GLOVE', code: 'GLOVE', unit_price: 4, minimum_quantity: 1, quantity_step: 1, maximum_quantity: 20, quantity: 1, ...overrides});

function harness(initial = []) {
    const events = () => ({
        listeners: new Map(),
        addEventListener(type, callback) { this.listeners.set(type, [...(this.listeners.get(type) || []), callback]); },
        emit(type, event = {}) { return Promise.all((this.listeners.get(type) || []).map((callback) => callback(event))); },
    });
    const node = () => Object.assign(events(), {
        dataset: {}, hidden: false, children: [], value: '', textContent: '', attributes: new Map(),
        classList: {toggle() {}},
        setAttribute(key, value) { this.attributes.set(key, value); },
        removeAttribute(key) { this.attributes.delete(key); },
        appendChild(child) { this.children.push(child); return child; },
        append(...children) { this.children.push(...children); },
        replaceChildren(...children) { this.children = children; },
        focus() {}, scrollIntoView() {},
        contains(target) { return this === target || this.children.some((child) => child.contains(target)); },
        querySelectorAll(selector) {
            const key = selector === '[data-quick-order-result]' ? 'quickOrderResult' : null;
            return this.children.filter((child) => key && child.dataset[key] !== undefined);
        },
    });
    const selectors = ['search', 'results', 'spinner', 'lines', 'empty', 'footer', 'count', 'total', 'submit', 'initial', 'draft-status', 'announcement', 'clear', 'undo', 'suggestions', 'suggestions-panel', 'suggestion-items', 'bulk-lines', 'import', 'import-feedback', 'form'];
    const nodes = Object.fromEntries(selectors.map((key) => [key, node()]));
    nodes.initial.textContent = JSON.stringify(initial);
    nodes.suggestions.textContent = '{}';
    const token = node(); token.value = 'token';
    const builder = node();
    builder.dataset = {searchUrl: '/search', syncUrl: '/draft', resolveUrl: '/resolve', storageKey: 'draft-9', savedLabel: 'Saved', savingLabel: 'Saving', saveErrorLabel: 'Stored here', addedLabel: 'Added', limitLabel: 'Limit', importingLabel: 'Checking', importSuccessLabel: 'Added lines', emptySearchLabel: 'No items'};
    builder.querySelector = (selector) => selector === 'input[name="_token"]' ? token : nodes[selector.match(/data-quick-order-(.*)\]/)?.[1]];
    builder.querySelectorAll = () => [];
    nodes.form.submitCount = 0;
    nodes.form.submit = () => nodes.form.submitCount++;
    const document = Object.assign(events(), {querySelectorAll: () => [builder], createElement: node});
    const requests = [];
    const storage = new Map();
    const window = {location: {origin: 'https://store.test'}, setTimeout, clearTimeout, sessionStorage: {getItem: (key) => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value), removeItem: (key) => storage.delete(key)}};
    const fetch = (url, options) => new Promise((resolve, reject) => {
        requests.push({url, options, body: options.body ? JSON.parse(options.body) : null, respond: (payload = {}, ok = true) => resolve({ok, status: ok ? 200 : 422, json: async () => payload}), reject});
    });
    vm.runInNewContext(script, {document, window, fetch, URL, AbortController, Intl, Promise});
    return {nodes, requests, storage};
}

async function settle() { for (let index = 0; index < 12; index++) await Promise.resolve(); }
function textTree(node) { return [node.textContent, ...node.children.map(textTree)].join(' '); }

test('clear and undo keep valid pack quantities and serialize the newest draft after an in-flight save', async () => {
    const page = harness([product({minimum_quantity: 2, quantity_step: 3, maximum_quantity: 12, quantity: 10})]);
    assert.equal(page.nodes.count.textContent, '1 stavka · 11 kom');
    await page.nodes.clear.emit('click');
    assert.equal(page.nodes.footer.hidden, true);
    assert.equal(page.nodes.undo.hidden, false);
    assert.deepEqual(page.requests[0].body.items, []);
    await page.nodes.undo.emit('click');
    assert.equal(page.nodes.count.textContent, '1 stavka · 11 kom');
    assert.equal(page.requests.length, 1);
    page.requests[0].respond(); await settle();
    assert.equal(page.requests.length, 2);
    assert.equal(page.requests[1].body.items[0].quantity, 11);
    page.requests[1].respond(); await settle();
    assert.equal(page.nodes['draft-status'].textContent, 'Saved');
    assert.equal(page.storage.size, 0);
});

test('submitting waits for the draft save so a late write cannot recreate the submitted order', async () => {
    const page = harness([product()]);
    let prevented = false;
    const submitted = page.nodes.form.emit('submit', {preventDefault: () => { prevented = true; }, target: page.nodes.form});
    assert.equal(prevented, true);
    assert.equal(page.nodes.form.submitCount, 0);
    assert.equal(page.nodes.submit.disabled, true);
    assert.equal(page.requests[0].body.items[0].product_id, 1);
    page.requests[0].respond();
    await submitted;
    assert.equal(page.nodes.form.submitCount, 1);
});

test('Enter selects a search result without first using arrows and never submits the whole order', async () => {
    const page = harness();
    page.nodes.search.value = 'GLOVE';
    await page.nodes.search.emit('focus');
    page.requests[0].respond({items: [product()]}); await settle();
    let prevented = false;
    await page.nodes.search.emit('keydown', {key: 'Enter', preventDefault: () => { prevented = true; }});
    assert.equal(prevented, true);
    assert.equal(page.nodes.count.textContent, '1 stavka · 1 kom');
    assert.equal(page.nodes.search.value, '');
    assert.equal(page.nodes.form.submitCount, 0);
    page.requests[1].respond(); await settle();
});

test('bulk import preserves variant IDs and leaves only unresolved lines for correction', async () => {
    const page = harness();
    page.nodes['bulk-lines'].value = 'SHIRT-XL; 3\nMISSING; 2';
    const importing = page.nodes.import.emit('click');
    assert.equal(page.nodes.import.disabled, true);
    assert.equal(page.requests[0].body.lines, 'SHIRT-XL; 3\nMISSING; 2');
    page.requests[0].respond({items: [product({key: '7:9', product_id: 7, product_option_value_id: 9, quantity: 3})], errors: [{line: 2, identifier: 'MISSING', message: 'Not found'}], warnings: []});
    await importing;
    assert.equal(page.nodes.count.textContent, '1 stavka · 3 kom');
    assert.equal(page.nodes['bulk-lines'].value, 'MISSING; 2');
    assert.equal(page.requests[1].body.items[0].product_option_value_id, 9);
    assert.match(textTree(page.nodes['import-feedback']), /2\. MISSING: Not found/);
    assert.equal(page.nodes.import.disabled, false);
    page.requests[1].respond(); await settle();
});
