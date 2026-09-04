var LEGACY_CACHE_PREFIXES = ['AppKit-', 'Appkit-'];

function isLegacyCache(cacheName) {
    return LEGACY_CACHE_PREFIXES.some(function(prefix) {
        return cacheName.indexOf(prefix) === 0;
    });
}

self.addEventListener('install', function(event) {
    self.skipWaiting();
});

self.addEventListener('activate', function(event) {
    event.waitUntil((async function() {
        var cacheNames = await caches.keys();

        await Promise.all(cacheNames.filter(isLegacyCache).map(function(cacheName) {
            return caches.delete(cacheName);
        }));

        await self.clients.claim();
        await self.registration.unregister();

        var clients = await self.clients.matchAll({
            type: 'window',
            includeUncontrolled: true
        });

        await Promise.all(clients.map(function(client) {
            if (client.url && 'navigate' in client) {
                return client.navigate(client.url).catch(function() {});
            }

            return Promise.resolve();
        }));
    })());
});
