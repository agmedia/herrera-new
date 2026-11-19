import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = path => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

function locationControl() {
    const inputs = ['latitude', 'longitude', 'accuracy'].map(coordinate => ({
        dataset: { coordinate }, value: '', dispatchEvent() {},
    }));
    const status = {};
    const field = { dataset: {}, querySelector: () => status, querySelectorAll: () => inputs };
    const button = { closest: () => field };
    let clickHandler;
    const requests = [];
    vm.runInNewContext(source('public/front-theme/scripts/device-location.js'), {
        window: {}, Event: class {},
        document: { addEventListener: (_, handler) => { clickHandler = handler; } },
        navigator: { geolocation: { getCurrentPosition: (success, error) => requests.push({ success, error }) } },
    });
    return { click: () => clickHandler({ target: { closest: () => button } }), requests, inputs, button, status };
}

test('location is requested once on demand and can be removed before saving', () => {
    const { click, requests, inputs, button } = locationControl();
    assert.equal(requests.length, 0);
    click();
    assert.equal(button.disabled, true);
    requests[0].success({ coords: { latitude: 45.81, longitude: 15.98, accuracy: 6.4 } });
    assert.deepEqual(inputs.map(input => input.value), ['45.81', '15.98', '6.4']);
    click();
    assert.deepEqual(inputs.map(input => input.value), ['', '', '']);
    assert.equal(requests.length, 1);
});

test('declining location leaves the form usable with empty coordinates', () => {
    const { click, requests, button, inputs, status } = locationControl();
    click();
    requests[0].error();
    assert.equal(button.disabled, false);
    assert.ok(inputs.every(input => input.value === ''));
    assert.match(status.textContent, /bez nje/);
});

test('EAN switch reveals and hides the table header and purchased item codes', () => {
    const view = source('resources/views/front/desktop/account/b2b-product-list.blade.php');
    const columns = [{ hidden: true }, { hidden: true }];
    let change;
    const toggle = {
        checked: false, closest: () => ({ querySelectorAll: () => columns }),
        addEventListener: (_, handler) => { change = handler; },
    };
    vm.runInNewContext(view.match(/<script>([\s\S]*?)<\/script>/)[1], {
        document: { querySelector: () => toggle },
    });
    for (const checked of [true, false]) {
        toggle.checked = checked;
        change.call(toggle);
        assert.ok(columns.every(cell => cell.hidden === !checked));
    }
});
