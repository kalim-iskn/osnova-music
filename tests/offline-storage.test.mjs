import assert from 'node:assert/strict';
import { reactive } from 'vue';

const data = new Map();
const persistCalls = [];
const fakeDatabase = {
    createObjectStore() { return this.transaction().objectStore(); },
    transaction() {
        const transaction = {
            objectStore() {
                return {
                    createIndex() {},
                    index() {
                        return {
                            getAll(id) {
                                return request(() => [...data.values()].filter((value) => value.ownerId === id), transaction);
                            },
                        };
                    },
                    put(record) { return request(() => { data.set(record.key, record); return record.key; }, transaction); },
                    delete(key) { return request(() => data.delete(key), transaction); },
                };
            },
        };
        return transaction;
    },
    close() {},
};
function request(callback, transaction) {
    const result = {};
    queueMicrotask(() => {
        try { result.result = callback(); result.onsuccess?.(); }
        catch (error) { result.error = error; result.onerror?.(); }
        setTimeout(() => transaction.oncomplete?.(), 0);
    });
    return result;
}

globalThis.window = globalThis;
globalThis.location = { origin: 'https://waveflow.test' };
globalThis.indexedDB = {
    open() {
        const result = {};
        queueMicrotask(() => { result.result = fakeDatabase; result.onsuccess?.(); });
        return result;
    },
};
Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { storage: { persist: async () => { persistCalls.push(1); return true; } } } });
globalThis.dispatchEvent = () => {};
const audioBytes = new Uint8Array([0x49, 0x44, 0x33, 0x03, 0x00, 0x00]);
globalThis.fetch = async (url) => {
    if (url.includes('/stream')) {
        return new Response(audioBytes, { status: 200, headers: { 'Content-Type': 'audio/mpeg' } });
    }
    return new Response(new Uint8Array([1, 2]), { status: 200, headers: { 'Content-Type': 'image/png' } });
};
const { downloadOfflineTrack, deleteOfflineTrack, getOfflineTracks } = await import('../resources/js/utils/offlineTracks.js');

const track = reactive({
    id: 42, title: 'Test song', playback_url: '/tracks/42/stream', is_playable: true,
    cover_image_url: '/icon-192.png', duration_seconds: 120, artists: [{ id: 9, name: 'Test artist', credit_role: 'featured' }],
    album: { id: 5, title: 'Test release', slug: 'test-release' },
});
const saved = await downloadOfflineTrack('7', track);
assert.equal(saved.id, 42);
assert.equal(saved.audio.type, 'audio/mpeg');
assert.equal(saved.audio.size, audioBytes.length);
assert.equal(saved.artwork.type, 'image/png');
assert.equal(saved.artist, 'Test artist');
assert.equal(saved.album, 'Test release');
assert.equal(structuredClone(saved.catalog).artists[0].credit_role, 'featured');
assert.equal(saved.catalog.album.slug, 'test-release');
assert.equal((await getOfflineTracks('7')).length, 1);
assert.equal((await getOfflineTracks('8')).length, 0);
assert.equal(persistCalls.length, 1);
await deleteOfflineTrack('7', 42);
assert.equal((await getOfflineTracks('7')).length, 0);

console.log('IndexedDB offline download, user isolation and removal tests passed.');
