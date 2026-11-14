import assert from 'node:assert/strict';
import { createPinia } from 'pinia';
import { createServer } from 'vite';

globalThis.localStorage = { getItem: () => null, setItem: () => {}, removeItem: () => {} };
const server = await createServer({
    configFile: false,
    optimizeDeps: { noDiscovery: true },
    server: { middlewareMode: true },
    appType: 'custom',
});

try {
    const { usePlayerStore } = await server.ssrLoadModule('/resources/js/stores/player.js');
    const player = usePlayerStore(createPinia());
    const first = { id: 1, title: 'First', audio_url: 'https://audio.test/1.mp3', is_playable: true };
    const missing = { id: 2, title: 'Missing', audio_url: null, playback_url: null, is_playable: false };
    const last = { id: 3, title: 'Last', audio_url: 'https://audio.test/3.mp3', is_playable: true };

    await player.playTrack(first, [first, missing, last]);
    assert.deepEqual(player.queue.map((track) => track.id), [1, 3]);
    player.addToQueue(missing);
    await player.playTrack(missing);
    assert.deepEqual(player.queue.map((track) => track.id), [1, 3]);
    assert.equal(player.currentTrack.id, 1);
    await player.playNext();
    assert.equal(player.currentTrack.id, 3);
    console.log('Player skips tracks without audio.');
} finally {
    await server.close();
}
