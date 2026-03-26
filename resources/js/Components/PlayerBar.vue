<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { usePlayerStore } from '../stores/player';
import QueueDrawer from './QueueDrawer.vue';
import LikeButton from './LikeButton.vue';
import TrackArtists from './TrackArtists.vue';
import TrackInfoMenu from './TrackInfoMenu.vue';
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
    set: (value) => player.seekTo(Number(value)),
});
const volume = computed({
    get: () => Math.round(player.volume * 100),
    set: (value) => player.setVolume(Number(value) / 100),
});
const repeatLabel = computed(() => {
    if (player.repeatMode === 'track') {
        return 'Повтор трека';
    }

    if (player.repeatMode === 'queue') {
        return 'Повтор очереди';
    }

    return 'Повтор выключен';
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
                        <Link :href="currentTrack.show_url ?? `/tracks/${currentTrack.id}`" class="player-bar__title-link">
                            <strong class="player-bar__title">{{ currentTrack.title }}</strong>
                        </Link>

                        <div class="player-bar__track-meta">
                            <TrackArtists :track="currentTrack" />

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
                    <button class="player-button" type="button" :disabled="!currentTrack" @click="player.playPrevious()" aria-label="Предыдущий трек">
                        ⏮
                    </button>

                    <button
                        class="player-button player-button--primary"
                        type="button"
                        :disabled="!currentTrack"
                        :aria-label="player.isPlaying ? 'Пауза' : 'Воспроизвести'"
                        @click="player.togglePlayback()"
                    >
                        {{ player.isPlaying ? '❚❚' : '▶' }}
                    </button>

                    <button class="player-button" type="button" :disabled="!currentTrack" @click="player.playNext()" aria-label="Следующий трек">
                        ⏭
                    </button>
                </div>

                <div class="player-bar__secondary">
                    <TrackInfoMenu v-if="currentTrack" :track="currentTrack" compact />

                    <button
                        class="icon-button player-bar__repeat-button"
                        :class="{
                            'player-bar__repeat-button--active': player.repeatMode !== 'off',
                            'player-bar__repeat-button--queue': player.repeatMode === 'queue',
                        }"
                        type="button"
                        :disabled="!currentTrack"
                        :aria-label="repeatLabel"
                        :title="repeatLabel"
                        @click="player.cycleRepeatMode()"
                    >
                        <span aria-hidden="true">↻</span>
                        <small v-if="player.repeatMode === 'track'">1</small>
                        <small v-else-if="player.repeatMode === 'queue'">Q</small>
                    </button>

                    <LikeButton v-if="currentTrack" :track-id="currentTrack.id" icon-only />

                    <label class="volume-control">
                        <button class="icon-button player-bar__mute-button" type="button" :disabled="!currentTrack" @click="player.toggleMute()">
                            <span aria-hidden="true">{{ player.isMuted ? '🔇' : '🔊' }}</span>
                        </button>

                        <input
                            :value="volume"
                            type="range"
                            min="0"
                            max="100"
                            step="1"
                            aria-label="Громкость"
                            :disabled="!currentTrack"
                            @input="volume = Number($event.target.value)"
                        >
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
                    :value="progress"
                    type="range"
                    min="0"
                    :max="totalDuration"
                    step="1"
                    :disabled="!currentTrack"
                    @input="progress = Number($event.target.value)"
                >

                <span>{{ formatSeconds(totalDuration) }}</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.player-bar__top {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
    align-items: center;
    gap: 1rem;
}

.player-bar__track {
    min-width: 0;
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: 0.9rem;
    align-items: center;
}

.player-bar__meta {
    min-width: 0;
}

.player-bar__title-link {
    color: inherit;
    text-decoration: none;
}

.player-bar__title-link:hover {
    color: #fff;
}

.player-bar__controls {
    grid-column: 2;
    justify-self: center;
    margin-inline: auto;
}

.player-bar__secondary {
    justify-self: end;
    display: flex;
    align-items: center;
    gap: 0.65rem;
}

.player-bar__timeline input,
.volume-control input {
    cursor: pointer;
}

.player-bar__track-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    align-items: center;
}

@media (max-width: 980px) {
    .player-bar__top {
        grid-template-columns: 1fr;
    }

    .player-bar__track,
    .player-bar__controls,
    .player-bar__secondary {
        grid-column: auto;
        justify-self: stretch;
    }

    .player-bar__controls,
    .player-bar__secondary {
        justify-content: center;
    }

    .player-bar__track {
        justify-content: start;
    }
}
</style>
