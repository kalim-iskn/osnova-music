<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { usePlayerStore } from '../stores/player';
import QueueDrawer from './QueueDrawer.vue';
import LikeButton from './LikeButton.vue';
import { formatSeconds } from '../utils/time';

const audioRef = ref(null);
const player = usePlayerStore();

onMounted(() => {
    player.attach(audioRef.value);
});

const currentTrack = computed(() => player.currentTrack);
const totalDuration = computed(() => player.duration || currentTrack.value?.duration_seconds || 0);
const progress = computed({
    get: () => Math.min(player.currentTime, totalDuration.value),
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
            <div class="player-bar__top">
                <div v-if="currentTrack" class="player-bar__track">
                    <img :src="currentTrack.cover_image_url" :alt="currentTrack.title" class="player-bar__cover">

                    <div class="player-bar__meta">
                        <strong class="player-bar__title">{{ currentTrack.title }}</strong>

                        <div class="player-bar__track-meta">
                            <Link :href="`/artists/${currentTrack.artist.slug}`">{{ currentTrack.artist.name }}</Link>

                            <template v-if="currentTrack.album">
                                <span class="player-bar__separator">•</span>
                                <Link :href="`/albums/${currentTrack.album.slug}`">{{ currentTrack.album.title }}</Link>
                            </template>
                        </div>
                    </div>
                </div>

                <div v-else class="player-bar__placeholder">
                    Выберите трек, чтобы начать прослушивание.
                </div>

                <div class="player-bar__controls" aria-label="Управление плеером">
                    <button class="player-button" type="button" :disabled="!currentTrack" @click="player.playPrevious()">
                        ⏮
                    </button>

                    <button
                        class="player-button player-button--primary"
                        type="button"
                        :disabled="!currentTrack"
                        @click="player.togglePlayback()"
                    >
                        {{ player.isPlaying ? '❚❚' : '▶' }}
                    </button>

                    <button class="player-button" type="button" :disabled="!currentTrack" @click="player.playNext()">
                        ⏭
                    </button>
                </div>

                <div class="player-bar__secondary">
                    <LikeButton v-if="currentTrack" :track-id="currentTrack.id" icon-only />

                    <label class="volume-control">
                        <button class="icon-button player-bar__mute-button" type="button" :disabled="!currentTrack" @click="player.toggleMute()">
                            <span aria-hidden="true">{{ player.isMuted ? '🔇' : '🔊' }}</span>
                        </button>

                        <input v-model="volume" type="range" min="0" max="100" step="1" aria-label="Громкость" :disabled="!currentTrack">
                    </label>

                    <button class="ghost-button ghost-button--small player-bar__queue-button" type="button" @click="player.toggleQueue()">
                        Очередь
                        <span class="badge">{{ player.queue.length }}</span>
                    </button>
                </div>
            </div>

            <div class="player-bar__timeline">
                <span>{{ formatSeconds(player.currentTime) }}</span>

                <input
                    v-model="progress"
                    type="range"
                    min="0"
                    :max="totalDuration"
                    step="1"
                    :disabled="!currentTrack"
                >

                <span>{{ formatSeconds(totalDuration) }}</span>
            </div>
        </div>
    </div>
</template>
