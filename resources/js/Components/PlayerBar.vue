<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { usePlayerStore } from '../stores/player';
import QueueDrawer from './QueueDrawer.vue';
import { formatSeconds } from '../utils/time';

const audioRef = ref(null);
const player = usePlayerStore();

onMounted(() => {
    player.attach(audioRef.value);
});

const currentTrack = computed(() => player.currentTrack);
const progress = computed({
    get: () => Math.min(player.currentTime, player.duration || currentTrack.value?.duration_seconds || 0),
    set: (value) => player.seekTo(value),
});
const volume = computed({
    get: () => Math.round(player.volume * 100),
    set: (value) => player.setVolume(Number(value) / 100),
});
</script>

<template>
        <QueueDrawer />

        <div class="player-bar" :class="{ 'player-bar--empty': !currentTrack }">
            <audio ref="audioRef" preload="metadata"></audio>

            <div class="player-bar__main">
                <div v-if="currentTrack" class="player-bar__track">
                    <img :src="currentTrack.cover_image_url" :alt="currentTrack.title" class="player-bar__cover">
                    <div>
                        <strong>{{ currentTrack.title }}</strong>
                        <div class="player-bar__track-meta">
                            <Link :href="`/artists/${currentTrack.artist.slug}`">{{ currentTrack.artist.name }}</Link>
                            <template v-if="currentTrack.album">·</template>
                            <Link v-if="currentTrack.album" :href="`/albums/${currentTrack.album.slug}`">{{ currentTrack.album.title }}</Link>
                        </div>
                    </div>
                </div>
                <div v-else class="player-bar__placeholder">
                    Выберите трек — плеер останется активным при переходе между страницами.
                </div>

                <div class="player-bar__controls">
                    <button class="player-button" type="button" :disabled="!currentTrack" @click="player.playPrevious()">⏮</button>
                    <button class="player-button player-button--primary" type="button" :disabled="!currentTrack" @click="player.togglePlayback()">
                        {{ player.isPlaying ? '❚❚' : '▶' }}
                    </button>
                    <button class="player-button" type="button" :disabled="!currentTrack" @click="player.playNext()">⏭</button>
                </div>

                <div class="player-bar__timeline">
                    <span>{{ formatSeconds(player.currentTime) }}</span>
                    <input
                        v-model="progress"
                        type="range"
                        min="0"
                        :max="player.duration || currentTrack?.duration_seconds || 0"
                        step="1"
                        :disabled="!currentTrack"
                    >
                    <span>{{ formatSeconds(player.duration || currentTrack?.duration_seconds || 0) }}</span>
                </div>

                <div class="player-bar__secondary">
                    <label class="volume-control">
                        <span>🔊</span>
                        <input v-model="volume" type="range" min="0" max="100" step="1">
                    </label>
                    <button class="ghost-button ghost-button--small" type="button" @click="player.toggleQueue()">
                        Очередь <span class="badge">{{ player.queue.length }}</span>
                    </button>
                </div>
            </div>
        </div>
</template>
