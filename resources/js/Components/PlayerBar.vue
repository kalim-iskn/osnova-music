<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { formatCount } from '../utils/pluralize';
import { usePlayerStore } from '../stores/player';
import QueueDrawer from './QueueDrawer.vue';
import LikeButton from './LikeButton.vue';
import PlayerQueueList from './PlayerQueueList.vue';
import TrackArtists from './TrackArtists.vue';
import { formatSeconds } from '../utils/time';

const audioRef = ref(null);
const mobilePlayerScrollRef = ref(null);
const mobileQueueSectionRef = ref(null);
const player = usePlayerStore();
const isCompactViewport = ref(false);
const isMobilePlayerOpen = ref(false);
const isMobilePlayerFull = ref(false);

let mediaQueryList = null;
let detachViewportListener = null;

onMounted(() => {
    player.attach(audioRef.value);

    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
        return;
    }

    mediaQueryList = window.matchMedia('(max-width: 900px)');
    isCompactViewport.value = mediaQueryList.matches;

    const handleViewportChange = (event) => {
        isCompactViewport.value = event.matches;

        if (!event.matches) {
            closeMobilePlayer();
        }
    };

    if (typeof mediaQueryList.addEventListener === 'function') {
        mediaQueryList.addEventListener('change', handleViewportChange);
        detachViewportListener = () => mediaQueryList?.removeEventListener('change', handleViewportChange);
    } else {
        mediaQueryList.addListener(handleViewportChange);
        detachViewportListener = () => mediaQueryList?.removeListener(handleViewportChange);
    }
});

onBeforeUnmount(() => {
    detachViewportListener?.();
    document.body.style.overflow = '';
});

const currentTrack = computed(() => player.currentTrack);
const totalDuration = computed(() => player.duration || currentTrack.value?.duration_seconds || 0);
const trackLengthLabel = computed(() => currentTrack.value?.duration_human ?? formatSeconds(totalDuration.value));
const progress = computed({
    get: () => Math.min(player.currentTime, totalDuration.value),
    set: (value) => player.seekTo(value),
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

const resetMobilePlayerState = () => {
    isMobilePlayerFull.value = false;

    if (mobilePlayerScrollRef.value) {
        mobilePlayerScrollRef.value.scrollTop = 0;
    }
};

const openMobilePlayer = async () => {
    if (!currentTrack.value || !isCompactViewport.value) {
        return;
    }

    isMobilePlayerOpen.value = true;
    isMobilePlayerFull.value = false;
    document.body.style.overflow = 'hidden';

    await nextTick();
    resetMobilePlayerState();
};

const closeMobilePlayer = () => {
    isMobilePlayerOpen.value = false;
    isMobilePlayerFull.value = false;
    document.body.style.overflow = '';

    if (mobilePlayerScrollRef.value) {
        mobilePlayerScrollRef.value.scrollTop = 0;
    }
};

const handleMobilePlayerScroll = (event) => {
    const scrollTop = event.target?.scrollTop ?? 0;

    if (!isMobilePlayerFull.value && scrollTop > 24) {
        isMobilePlayerFull.value = true;
        return;
    }

    if (isMobilePlayerFull.value && scrollTop <= 0) {
        isMobilePlayerFull.value = false;
    }
};

const handleMiniPlayerActivate = () => {
    if (!currentTrack.value) {
        return;
    }

    openMobilePlayer();
};

const handleMiniPlayerKeydown = (event) => {
    if (event.key !== 'Enter' && event.key !== ' ') {
        return;
    }

    event.preventDefault();
    handleMiniPlayerActivate();
};

const toggleQueueExperience = () => {
    if (isCompactViewport.value) {
        openMobilePlayer();
        return;
    }

    player.toggleQueue();
};

const scrollQueueIntoView = () => {
    mobileQueueSectionRef.value?.scrollIntoView({
        behavior: 'smooth',
        block: 'start',
    });
};

watch(currentTrack, (track) => {
    if (!track) {
        closeMobilePlayer();
    }
});

watch(isMobilePlayerOpen, (isOpen) => {
    if (!isOpen) {
        document.body.style.overflow = '';
    }
});
</script>

<template>
    <QueueDrawer />

    <div class="player-bar" :class="{ 'player-bar--empty': !currentTrack }">
        <audio ref="audioRef" preload="metadata"></audio>

        <div class="player-bar__desktop">
            <div class="player-bar__main">
                <div class="player-bar__top">
                    <div v-if="currentTrack" class="player-bar__track">
                        <img :src="currentTrack.cover_image_url" :alt="currentTrack.title" class="player-bar__cover">

                        <div class="player-bar__meta">
                            <strong class="player-bar__title">
                                <Link :href="currentTrack.show_url ?? `/tracks/${currentTrack.id}`">
                                    {{ currentTrack.title }}
                                </Link>
                            </strong>

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

                    <div class="player-bar__controls-wrap" aria-label="Управление плеером">
                        <div class="player-bar__controls">
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
                    </div>

                    <div class="player-bar__secondary">
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

                        <button class="ghost-button ghost-button--small player-bar__queue-button" type="button" @click="toggleQueueExperience">
                            В очередь
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

        <div
            v-if="currentTrack"
            class="player-bar__mobile-mini"
            role="button"
            tabindex="0"
            @click="handleMiniPlayerActivate"
            @keydown="handleMiniPlayerKeydown"
        >
            <img :src="currentTrack.cover_image_url" :alt="currentTrack.title" class="player-bar__mobile-cover">

            <div class="player-bar__mobile-meta">
                <strong>{{ currentTrack.title }}</strong>
                <small><TrackArtists :track="currentTrack" :links="false" /></small>
            </div>

            <button
                class="player-button player-button--primary player-bar__mobile-play"
                type="button"
                :disabled="!currentTrack"
                @click.stop="player.togglePlayback()"
            >
                {{ player.isPlaying ? '❚❚' : '▶' }}
            </button>
        </div>
    </div>

    <transition name="mobile-player-fade">
        <div v-if="isMobilePlayerOpen && currentTrack" class="mobile-player">
            <button class="mobile-player__backdrop" type="button" aria-label="Закрыть плеер" @click="closeMobilePlayer"></button>

            <section class="mobile-player__sheet" :class="{ 'mobile-player__sheet--full': isMobilePlayerFull }">
                <div class="mobile-player__topline">
                    <button class="mobile-player__grabber" type="button" aria-label="Свернуть плеер" @click="closeMobilePlayer">
                        <span></span>
                    </button>
                </div>

                <div ref="mobilePlayerScrollRef" class="mobile-player__scroll" @scroll="handleMobilePlayerScroll">
                    <div class="mobile-player__hero">
                        <Link :href="currentTrack.show_url ?? `/tracks/${currentTrack.id}`" class="mobile-player__cover-link">
                            <img :src="currentTrack.cover_image_url" :alt="currentTrack.title" class="mobile-player__cover">
                        </Link>

                        <div class="mobile-player__meta">
                            <strong class="mobile-player__title">
                                <Link :href="currentTrack.show_url ?? `/tracks/${currentTrack.id}`">
                                    {{ currentTrack.title }}
                                </Link>
                            </strong>

                            <div class="mobile-player__artists">
                                <TrackArtists :track="currentTrack" />
                            </div>

                            <Link v-if="currentTrack.album" :href="`/albums/${currentTrack.album.slug}`" class="mobile-player__album-link">
                                {{ currentTrack.album.title }}
                            </Link>
                        </div>
                    </div>

                    <div class="mobile-player__timeline">
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

                        <span>{{ trackLengthLabel }}</span>
                    </div>

                    <div class="mobile-player__controls">
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

                    <div class="mobile-player__actions">
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

                        <button class="icon-button mobile-player__mute" type="button" :disabled="!currentTrack" @click="player.toggleMute()">
                            <span aria-hidden="true">{{ player.isMuted ? '🔇' : '🔊' }}</span>
                        </button>

                        <button class="ghost-button ghost-button--small mobile-player__queue-jump" type="button" @click="scrollQueueIntoView">
                            Очередь
                            <span class="badge">{{ player.queue.length }}</span>
                        </button>
                    </div>

                    <section ref="mobileQueueSectionRef" class="mobile-player__queue-block">
                        <div class="mobile-player__queue-header">
                            <div class="mobile-player__queue-heading">
                                <h3>Очередь</h3>
                                <p>{{ formatCount(player.queue.length, ['трек', 'трека', 'треков']) }}</p>
                            </div>

                            <button class="ghost-button ghost-button--small" type="button" @click="player.clearQueue()">
                                Очистить
                            </button>
                        </div>

                        <PlayerQueueList v-if="player.queue.length" />

                        <div v-else class="empty-state empty-state--large">
                            <p>Очередь пока пуста. Добавьте треки и продолжайте слушать в удобном порядке.</p>
                        </div>
                    </section>
                </div>
            </section>
        </div>
    </transition>
</template>

<style scoped>
.player-bar__desktop {
    display: block;
}

.player-bar__top {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
    align-items: center;
    gap: 1rem;
}

.player-bar__track,
.player-bar__meta {
    min-width: 0;
}

.player-bar__title a,
.player-bar__track-meta a,
.mobile-player__title a,
.mobile-player__album-link {
    color: inherit;
    text-decoration: none;
}

.player-bar__controls-wrap {
    display: inline-flex;
    align-items: center;
    justify-self: center;
    gap: 0.9rem;
    min-width: 0;
}

.player-bar__controls {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.8rem;
}

.player-bar__secondary {
    justify-self: end;
}

.player-bar__timeline input {
    cursor: pointer;
}

.player-bar__mobile-mini {
    display: none;
}

.mobile-player {
    position: fixed;
    inset: 0;
    z-index: 80;
}

.mobile-player__backdrop {
    position: absolute;
    inset: 0;
    border: 0;
    background: rgba(3, 6, 14, 0.72);
}

.mobile-player__sheet {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: min(85dvh, 860px);
    border-radius: 28px 28px 0 0;
    background: linear-gradient(180deg, rgba(14, 19, 33, 0.98) 0%, rgba(7, 11, 20, 0.99) 100%);
    box-shadow: 0 -24px 80px rgba(0, 0, 0, 0.44);
    overflow: hidden;
    transition: height 0.28s cubic-bezier(0.22, 1, 0.36, 1), border-radius 0.28s ease;
}

.mobile-player__sheet--full {
    height: 100dvh;
    border-radius: 0;
}

.mobile-player__topline {
    display: flex;
    justify-content: center;
    padding: 12px 16px 0;
}

.mobile-player__grabber {
    width: 100%;
    max-width: 80px;
    height: 22px;
    border: 0;
    background: transparent;
    display: inline-flex;
    justify-content: center;
    align-items: center;
    cursor: pointer;
}

.mobile-player__grabber span {
    width: 54px;
    height: 5px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.22);
}

.mobile-player__scroll {
    height: calc(100% - 26px);
    overflow-y: auto;
    padding: 12px 18px calc(28px + env(safe-area-inset-bottom));
    display: grid;
    gap: 1.25rem;
    overscroll-behavior: contain;
}

.mobile-player__hero {
    display: grid;
    gap: 1rem;
}

.mobile-player__cover-link {
    display: block;
}

.mobile-player__cover {
    width: min(72vw, 360px);
    aspect-ratio: 1;
    display: block;
    margin: 0 auto;
    object-fit: cover;
    border-radius: 28px;
    box-shadow: 0 24px 48px rgba(0, 0, 0, 0.32);
}

.mobile-player__meta {
    display: grid;
    gap: 0.55rem;
    text-align: center;
}

.mobile-player__title {
    font-size: 1.35rem;
    line-height: 1.2;
}

.mobile-player__artists {
    display: flex;
    justify-content: center;
}

.mobile-player__album-link {
    color: rgba(255, 255, 255, 0.72);
}

.mobile-player__timeline,
.mobile-player__controls,
.mobile-player__actions {
    display: flex;
    align-items: center;
}

.mobile-player__timeline {
    gap: 0.9rem;
}

.mobile-player__timeline input {
    flex: 1 1 auto;
}

.mobile-player__timeline span {
    font-variant-numeric: tabular-nums;
    color: rgba(255, 255, 255, 0.62);
}

.mobile-player__controls {
    justify-content: center;
    gap: 0.9rem;
}

.mobile-player__actions {
    justify-content: center;
    gap: 0.8rem;
    flex-wrap: wrap;
}

.mobile-player__queue-block {
    display: grid;
    gap: 1rem;
    padding-top: 0.5rem;
}

.mobile-player__queue-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.mobile-player__queue-heading {
    display: grid;
    gap: 0.3rem;
}

.mobile-player__queue-heading p {
    color: rgba(255, 255, 255, 0.6);
}

.mobile-player-fade-enter-active,
.mobile-player-fade-leave-active {
    transition: opacity 0.22s ease;
}

.mobile-player-fade-enter-from,
.mobile-player-fade-leave-to {
    opacity: 0;
}

@media (max-width: 980px) {
    .player-bar__top {
        grid-template-columns: 1fr;
    }

    .player-bar__controls-wrap,
    .player-bar__secondary {
        justify-self: stretch;
    }

    .player-bar__controls-wrap {
        justify-content: center;
    }

    .player-bar__secondary {
        justify-content: space-between;
    }
}

@media (max-width: 900px) {
    .player-bar--empty {
        display: none;
    }

    .player-bar__desktop {
        display: none;
    }

    .player-bar__mobile-mini {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        align-items: center;
        gap: 0.85rem;
        min-height: 72px;
    }

    .player-bar__mobile-cover {
        width: 52px;
        height: 52px;
        border-radius: 16px;
        object-fit: cover;
        box-shadow: 0 10px 24px rgba(0, 0, 0, 0.24);
    }

    .player-bar__mobile-meta {
        min-width: 0;
        display: grid;
        gap: 0.35rem;
    }

    .player-bar__mobile-meta strong,
    .player-bar__mobile-meta small {
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .player-bar__mobile-play {
        min-width: 52px;
    }
}

@media (max-width: 520px) {
    .mobile-player__scroll {
        padding-inline: 14px;
    }

    .mobile-player__cover {
        width: min(80vw, 320px);
        border-radius: 24px;
    }

    .mobile-player__actions {
        gap: 0.65rem;
    }
}
</style>
