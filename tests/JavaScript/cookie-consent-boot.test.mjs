import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const template = readFileSync(new URL('../../resources/views/front/partials/cookie-consent.blade.php', import.meta.url), 'utf8');
const script = template.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace(/@json\((?:[^()]|\([^()]*\))*\)/g, '"test"');

function harness({ readyState = 'loading', validConsent = false } = {}) {
    const listeners = new Map();
    const events = (scope) => ({
        addEventListener(name, fn) { listeners.set(`${scope}:${name}`, fn); },
    });
    let runs = 0;
    let shows = 0;
    const document = {
        ...events('document'), readyState, cookie: '',
        getElementById() { return null; },
        querySelector() { return {}; },
    };
    const window = {
        ...events('window'),
        setTimeout() { throw new Error('Consent must not start on a delayed interaction.'); },
        CookieConsent: {
            run() { runs++; }, validConsent() { return validConsent; },
            show() { shows++; }, acceptedCategory() { return false; },
        },
    };
    vm.runInNewContext(script, { document, window });
    return {
        listeners, get runs() { return runs; }, get shows() { return shows; },
        ready() { listeners.get('document:DOMContentLoaded')?.(); },
    };
}

test('first-visit consent starts when the DOM is ready, before any tap or scroll', async () => {
    const page = harness();
    assert.equal(page.runs, 0);
    page.ready();
    await Promise.resolve();
    assert.equal(page.runs, 1);
    assert.equal(page.shows, 1);
    for (const event of ['pointerdown', 'touchstart', 'keydown', 'scroll']) {
        assert.equal(page.listeners.has(`window:${event}`), false);
    }
});

test('a parsed document initializes without waiting for images or a link tap', async () => {
    const page = harness({ readyState: 'interactive' });
    await Promise.resolve();
    assert.equal(page.runs, 1);
    assert.equal(page.shows, 1);
    assert.equal(page.listeners.has('window:load'), false);
});

test('existing consent keeps the modal closed', async () => {
    const page = harness({ readyState: 'complete', validConsent: true });
    await Promise.resolve();
    assert.equal(page.runs, 1);
    assert.equal(page.shows, 0);
});
