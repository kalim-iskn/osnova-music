import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { computed, reactive, ref, watch } from 'vue';
import * as offline from '../resources/js/utils/offlineTracks.js';

const source = fs.readFileSync(new URL('../resources/js/Pages/Library/Tracks.vue', import.meta.url), 'utf8')
    .match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*;\r?\n/gm, '');
const records = [
    { id: 10, title: 'Saved one', artist: 'SALUKI', album: 'Release one', duration_seconds: 195, audio: new Blob(['audio']) },
    { id: 11, title: 'Saved two', artist: 'GONE.Fludd', album: 'Release two', duration_seconds: 172, audio: new Blob(['audio']) },
    { id: 12, title: 'No longer liked', artist: 'Another artist', album: '', duration_seconds: 100, audio: new Blob(['audio']) },
];
const props = {
    tracks: { data: [{ id: 10, title: 'Saved one', artists: [{ id: 7, name: 'SALUKI', slug: 'saluki' }], album: { title: 'Release one', slug: 'one' } }], meta: { total: 1 } },
    artists: [{ id: 7, name: 'SALUKI' }, { id: 8, name: 'GONE.Fludd' }], releases: { data: [] },
};
const context = vm.createContext({
    computed, reactive, ref, watch, URLSearchParams, AppLayout: {},
    defineOptions() {}, defineProps: () => props,
    usePage: () => ({ url: '/library/tracks', props: { auth: { user: { id: 1 } } } }),
    usePlayerStore: () => ({}), onMounted() {}, onBeforeUnmount() {},
    ...offline, getOfflineTracks: async () => records,
});
vm.runInContext(source + `
    globalThis.library = {
        refreshSavedTracks,
        get artists() { return artistOptions.value; },
        get tracks() { return visibleTracks.value; },
        get saved() { return savedTracks.value; },
        selectSavedArtist(id) { collectionTab.value = 'saved'; selectedArtist.value = id; },
    };
`, context);
assert.equal(context.library.tracks.length, 1);
await context.library.refreshSavedTracks();
assert.deepEqual(Array.from(context.library.artists, artist => artist.name), ['GONE.Fludd', 'SALUKI']);
assert.equal(context.library.tracks.length, 1, 'Loading device tracks must not remove the liked collection');
assert.equal(context.library.saved.length, 3, 'Legacy tracks outside the liked catalog must remain visible');
context.library.selectSavedArtist(8);
assert.equal(context.library.tracks.length, 1);
assert.equal(context.library.tracks[0].title, 'Saved two');
assert.equal(context.library.tracks[0].artists[0].name, 'GONE.Fludd');
assert.equal(context.library.tracks[0].duration_human, '2:52');
assert.equal(context.library.tracks[0].playback_url, '/__waveflow_offline__/1/11/audio');
assert.equal(records[1].artist, 'GONE.Fludd', 'Legacy IndexedDB records must stay intact');
const structured = offline.offlineTrackForLibrary({
    id: 13, title: 'New download', audio: new Blob(['audio']),
    catalog: { artists: [{ id: 9, name: 'Featured artist', credit_role: 'featured' }], album: { title: 'New release', slug: 'new-release' } },
}, 1);
assert.equal(structured.artists[0].id, 9);
assert.equal(structured.artists[0].credit_role, 'featured');
assert.equal(structured.album.slug, 'new-release');
const primaryOnly = offline.offlineTrackForLibrary({ id: 14, catalog: { artist: { id: 20, name: 'Primary artist' } } }, 1);
assert.equal(primaryOnly.artist.id, 20);
console.log('Library survives loading legacy device tracks and filters playable saved music.');
