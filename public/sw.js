/* Dedicated offline navigation shell and local audio byte-range responses. */
const SHELL_CACHE = 'waveflow-offline-shell-v1';
const DB_NAME = 'waveflow-offline-v1';
const MEDIA_PREFIX = '/__waveflow_offline__/';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(SHELL_CACHE).then((cache) => cache.addAll([
        '/offline.html', '/favicon.svg', '/track-placeholder.svg', '/icon-192.png', '/icon-512.png',
    ])).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(Promise.all([
        caches.keys().then((keys) => Promise.all(keys.filter((key) => key.startsWith('waveflow-offline-shell-') && key !== SHELL_CACHE).map((key) => caches.delete(key)))),
        self.clients.claim(),
    ]));
});

const lookup = (key) => new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = () => {
        const store = request.result.createObjectStore('tracks', { keyPath: 'key' });
        store.createIndex('ownerId', 'ownerId');
    };
    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
        const db = request.result;
        const transaction = db.transaction('tracks', 'readonly');
        const get = transaction.objectStore('tracks').get(key);
        get.onsuccess = () => resolve(get.result || null);
        get.onerror = () => reject(get.error);
        transaction.oncomplete = () => db.close();
        transaction.onerror = () => db.close();
    };
});

const serveMedia = async (request, pathname) => {
    const match = /^\/__waveflow_offline__\/([^/]+)\/([^/]+)\/(audio|artwork)$/.exec(pathname);
    if (!match) return new Response('Not found', { status: 404 });
    const owner = decodeURIComponent(match[1]);
    const track = decodeURIComponent(match[2]);
    const record = await lookup(`${owner}:${track}`);
    const blob = match[3] === 'audio' ? record?.audio : record?.artwork;
    if (!(blob instanceof Blob)) return new Response('Not found', { status: 404 });

    const headers = {
        'Content-Type': blob.type || (match[3] === 'audio' ? 'audio/mpeg' : 'image/jpeg'),
        'Accept-Ranges': 'bytes',
        'Cache-Control': 'no-store',
    };
    const range = request.headers.get('Range');
    if (!range || match[3] !== 'audio') {
        return new Response(request.method === 'HEAD' ? null : blob, {
            status: 200, headers: { ...headers, 'Content-Length': String(blob.size) },
        });
    }

    const parts = /^bytes=(\d*)-(\d*)$/.exec(range);
    if (!parts || (!parts[1] && !parts[2])) {
        return new Response(null, { status: 416, headers: { ...headers, 'Content-Range': `bytes */${blob.size}` } });
    }
    let start = parts[1] ? Number(parts[1]) : Math.max(0, blob.size - Number(parts[2]));
    let end = parts[2] && parts[1] ? Number(parts[2]) : blob.size - 1;
    if (!Number.isSafeInteger(start) || !Number.isSafeInteger(end) || start >= blob.size || start > end || end < 0) {
        return new Response(null, { status: 416, headers: { ...headers, 'Content-Range': `bytes */${blob.size}` } });
    }
    end = Math.min(end, blob.size - 1);
    const length = end - start + 1;
    return new Response(request.method === 'HEAD' ? null : blob.slice(start, end + 1, headers['Content-Type']), {
        status: 206,
        headers: { ...headers, 'Content-Length': String(length), 'Content-Range': `bytes ${start}-${end}/${blob.size}` },
    });
};

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (url.origin !== self.location.origin) return;

    if (['/offline.html', '/track-placeholder.svg', '/favicon.svg', '/icon-192.png', '/icon-512.png'].includes(url.pathname)) {
        event.respondWith(caches.match(event.request).then((cached) => cached || fetch(event.request)));
        return;
    }

    if (url.pathname.startsWith(MEDIA_PREFIX)) {
        event.respondWith(serveMedia(event.request, url.pathname).catch(() => new Response('Offline storage error', { status: 500 })));
        return;
    }

    if (event.request.mode === 'navigate') {
        event.respondWith(fetch(event.request).catch(async () => {
            const cache = await caches.open(SHELL_CACHE);
            return (await cache.match('/offline.html')) || Response.error();
        }));
    }
});
