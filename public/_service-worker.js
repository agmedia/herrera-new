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
    })());
});
