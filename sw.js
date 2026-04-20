/**
 * TaskMaster Service Worker
 * Cache-first strategy para suporte offline completo
 */

const CACHE_NAME   = 'taskmaster-v2.0.0';
const OFFLINE_URL  = 'index.php';

// Arquivos essenciais que ficam em cache
const PRECACHE = [
    'index.php',
    'manifest.json',
    'https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono&display=swap',
];

// ── Install: pré-carrega recursos essenciais ─────────────────────────────────
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(PRECACHE))
            .then(() => self.skipWaiting())
    );
});

// ── Activate: limpa caches antigos ───────────────────────────────────────────
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys
                    .filter(key => key !== CACHE_NAME)
                    .map(key => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

// ── Fetch: network-first com fallback para cache ──────────────────────────────
self.addEventListener('fetch', event => {
    // Ignora requisições não-GET (POST de formulários)
    if (event.request.method !== 'GET') return;

    // Ignora extensões do browser e URLs externas problemáticas
    if (!event.request.url.startsWith(self.location.origin) &&
        !event.request.url.startsWith('https://fonts.')) return;

    event.respondWith(
        fetch(event.request)
            .then(response => {
                // Clona e armazena em cache apenas respostas válidas
                if (response && response.status === 200 && response.type !== 'opaque') {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
                }
                return response;
            })
            .catch(() => caches.match(event.request)
                .then(cached => cached || caches.match(OFFLINE_URL))
            )
    );
});
