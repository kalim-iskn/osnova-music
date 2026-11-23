import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

// No browser or npm dependency required: exercise the SW's actual fetch handler.
const audio = new Blob([new Uint8Array([0, 1, 2, 3, 4, 5, 6, 7, 8, 9])], { type: 'audio/mpeg' });
const artwork = new Blob([new Uint8Array([11, 22])], { type: 'image/png' });
const handlers = {};
const records = { '5:12': { audio, artwork } };
const indexedDB = {
    open() {
        const request = {};
        queueMicrotask(() => {
            request.result = {
                transaction() {
                    const transaction = {
                        objectStore() {
                            return {
                                get(key) {
                                    const get = {};
                                    queueMicrotask(() => {
                                        get.result = records[key];
                                        get.onsuccess();
                                        queueMicrotask(() => transaction.oncomplete?.());
                                    });
                                    return get;
                                },
                            };
                        },
                    };
                    return transaction;
                },
                close() {},
            };
            request.onsuccess();
        });
        return request;
    },
};
const sw = {
    location: { origin: 'https://waveflow.test' },
    addEventListener(type, handler) { handlers[type] = handler; },
};
const context = { self: sw, indexedDB, Response, Request, Blob, URL, caches: {}, fetch: () => Promise.reject(new Error('offline')) };
vm.runInNewContext(readFileSync(new URL('../public/sw.js', import.meta.url), 'utf8'), context);

async function requestMedia(path, range = null) {
    const headers = range ? { Range: range } : {};
    let handled;
    handlers.fetch({
        request: new Request(`https://waveflow.test${path}`, { headers }),
        respondWith(response) { handled = response; },
    });
    return await handled;
}

const full = await requestMedia('/__waveflow_offline__/5/12/audio');
assert.equal(full.status, 200);
assert.equal(full.headers.get('Content-Length'), '10');
assert.equal((await full.arrayBuffer()).byteLength, 10);

const first = await requestMedia('/__waveflow_offline__/5/12/audio', 'bytes=0-1');
assert.equal(first.status, 206);
assert.equal(first.headers.get('Content-Range'), 'bytes 0-1/10');
assert.deepEqual([...new Uint8Array(await first.arrayBuffer())], [0, 1]);

const suffix = await requestMedia('/__waveflow_offline__/5/12/audio', 'bytes=-3');
assert.equal(suffix.status, 206);
assert.equal(suffix.headers.get('Content-Range'), 'bytes 7-9/10');
assert.deepEqual([...new Uint8Array(await suffix.arrayBuffer())], [7, 8, 9]);

const toEnd = await requestMedia('/__waveflow_offline__/5/12/audio', 'bytes=5-');
assert.equal(toEnd.status, 206);
assert.equal(toEnd.headers.get('Content-Range'), 'bytes 5-9/10');
assert.equal((await toEnd.arrayBuffer()).byteLength, 5);

const invalid = await requestMedia('/__waveflow_offline__/5/12/audio', 'bytes=99-');
assert.equal(invalid.status, 416);
assert.equal(invalid.headers.get('Content-Range'), 'bytes */10');

const missing = await requestMedia('/__waveflow_offline__/5/77/audio');
assert.equal(missing.status, 404);

const cover = await requestMedia('/__waveflow_offline__/5/12/artwork');
assert.equal(cover.status, 200);
assert.equal(cover.headers.get('Content-Type'), 'image/png');

console.log('Offline SW audio, artwork and byte-range tests passed.');
