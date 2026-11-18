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

    const afterCurrent = { id: 4, title: 'After current', audio_url: 'https://audio.test/4.mp3', is_playable: true };
    const afterFirst = { id: 5, title: 'After first', audio_url: 'https://audio.test/5.mp3', is_playable: true };
    const lastInQueue = { id: 6, title: 'Last in queue', audio_url: 'https://audio.test/6.mp3', is_playable: true };
    const nextTrack = { id: 7, title: 'Next track', audio_url: 'https://audio.test/7.mp3', is_playable: true };

    assert.equal(player.queueTrackNext(nextTrack), true);
    assert.deepEqual(player.queue.map((track) => track.id), [1, 3, 7]);
    assert.equal(player.queueTrackEnd(afterCurrent), true);
    assert.deepEqual(player.queue.map((track) => track.id), [1, 3, 7, 4]);
    assert.equal(player.queueTrackNext(afterCurrent), true);
    assert.deepEqual(player.queue.map((track) => track.id), [1, 3, 4, 7]);
    assert.equal(player.queueTrackAfter(afterFirst, 0), true);
    assert.deepEqual(player.queue.map((track) => track.id), [1, 5, 3, 4, 7]);
    assert.equal(player.currentTrack.id, 3);
    assert.equal(player.queueTrackAfter(afterCurrent, 0), true);
    assert.deepEqual(player.queue.map((track) => track.id), [1, 4, 5, 3, 7]);
    assert.equal(player.currentTrack.id, 3);
    assert.equal(player.queueTrackNext(afterFirst), true);
    assert.deepEqual(player.queue.map((track) => track.id), [1, 4, 3, 5, 7]);
    assert.equal(player.currentTrack.id, 3);
    assert.equal(player.queueTrackEnd(lastInQueue), true);
    assert.deepEqual(player.queue.map((track) => track.id), [1, 4, 3, 5, 7, 6]);
    assert.equal(player.queueTrackAfter(missing, 0), false);
    console.log('Player queue insertion and unavailable-track handling passed.');
} finally {
    await server.close();
}
