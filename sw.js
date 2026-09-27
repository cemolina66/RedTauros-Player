/* C:\Users\desktop\Documents\Antigravity\RedTauros Player\sw.js */

const CACHE_NAME = 'fiesta-musical-cache-v1';
const ASSETS = [
    './',
    './index.html',
    './mobile.css',
    './mobile.js',
    './manifest.json'
];

// Install Event
self.addEventListener('install', (e) => {
    e.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(ASSETS);
        }).then(() => {
            return self.skipWaiting();
        })
    );
});

// Activate Event
self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.map((key) => {
                    if (key !== CACHE_NAME) {
                        return caches.delete(key);
                    }
                })
            );
        }).then(() => {
            return self.clients.claim();
        })
    );
});

// Fetch Event
self.addEventListener('fetch', (e) => {
    const url = new URL(e.request.url);

    // Bypass caching for API endpoints entirely
    if (url.pathname.includes('/api/')) {
        return; // Network only
    }

    // Network-First strategy for static assets
    e.respondWith(
        fetch(e.request).then((response) => {
            // Check if valid response
            if (!response || response.status !== 200 || response.type !== 'basic') {
                return response;
            }
            
            // Clone response and cache it
            const responseToCache = response.clone();
            caches.open(CACHE_NAME).then((cache) => {
                cache.put(e.request, responseToCache);
            });
            
            return response;
        }).catch(() => {
            // Network failed, serve from Cache
            return caches.match(e.request).then((cachedResponse) => {
                if (cachedResponse) {
                    return cachedResponse;
                }
                
                // Fallback for html pages
                if (e.request.headers.get('accept').includes('text/html')) {
                    return caches.match('./index.html');
                }
            });
        })
    );
});
