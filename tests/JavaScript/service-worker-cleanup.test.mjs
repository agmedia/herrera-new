import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../public/_service-worker.js', import.meta.url), 'utf8');

test('legacy worker cleans only AppKit caches and unregisters without refreshing open pages', async () => {
    const listeners = new Map();
    const calls = [];
    let pageNavigations = 0;
    let clientLookups = 0;
    const self = {
        addEventListener: (type, callback) => listeners.set(type, callback),
        skipWaiting: () => calls.push('skipWaiting'),
        clients: {
            claim: async () => calls.push('claim'),
            matchAll: async () => {
                clientLookups++;
                return [{
                    url: 'https://shop.example/shop',
                    navigate: async () => { pageNavigations++; },
                }];
            },
        },
        registration: { unregister: async () => calls.push('unregister') },
    };
    const caches = {
        keys: async () => ['AppKit-v1', 'Appkit-v2', 'other-app-v1', 'my-AppKit-backup'],
        delete: async (name) => { calls.push('delete:'+name); return true; },
    };

    vm.runInNewContext(script, { self, caches });
    assert.deepEqual([...listeners.keys()], ['install', 'activate']);
    listeners.get('install')({});
    let activation;
    listeners.get('activate')({ waitUntil: (promise) => { activation = promise; } });
    assert.ok(activation instanceof Promise || typeof activation?.then === 'function');
    await activation;

    assert.deepEqual(calls, ['skipWaiting', 'delete:AppKit-v1', 'delete:Appkit-v2', 'claim', 'unregister']);
    assert.equal(clientLookups, 0);
    assert.equal(pageNavigations, 0);
});
