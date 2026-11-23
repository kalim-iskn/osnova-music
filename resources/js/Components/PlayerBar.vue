<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { formatCount } from '../utils/pluralize';
import { usePlayerStore } from '../stores/player';
import QueueDrawer from './QueueDrawer.vue';
import LikeButton from './LikeButton.vue';
import PlayerQueueList from './PlayerQueueList.vue';
import TrackArtists from './TrackArtists.vue';
import AppIcon from './AppIcon.vue';
import PlayerSeekBar from './PlayerSeekBar.vue';
import { offlineMediaUrl } from '../utils/offlineTracks';

const audioRef = ref(null);
const mobilePlayerScrollRef = ref(null);
const mobileQueueSectionRef = ref(null);
const mobilePlayerSheetRef = ref(null);
const player = usePlayerStore();
const page = usePage();
const isCompactViewport = ref(false);
const isMobilePlayerOpen = ref(false);
const isMobilePlayerFull = ref(false);
const mobileSheetDragY = ref(0);
const isMobileSheetDragging = ref(false);
const isMobileSheetSettling = ref(false);

let mediaQueryList = null;
let detachViewportListener = null;
let mobileSheetGesture = null;
let mobileSheetTimer = 0;
let suppressMobileSheetClick = false;

onMounted(() => {
    player.attach(audioRef.value);
    installMediaSession();
    updateMediaMetadata();

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
    clearMediaSession();
    detachViewportListener?.();
    detachPointerSheetListeners();
    window.clearTimeout(mobileSheetTimer);
    document.body.style.overflow = '';
});

const currentTrack = computed(() => player.currentTrack);
const totalDuration = computed(() => player.duration || currentTrack.value?.duration_seconds || 0);
const currentUrl = computed(() => page.url || '/');
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
const mobileSheetStyle = computed(() => ({
    transform: `translate3d(0, ${mobileSheetDragY.value}px, 0)`,
}));
const mobileBackdropStyle = computed(() => {
    const viewportHeight = typeof window === 'undefined' ? 1 : window.innerHeight;
    const progress = Math.min(1, mobileSheetDragY.value / Math.max(1, viewportHeight * 0.72));

    return { opacity: String(1 - progress * 0.88) };
});

const resetMobileSheetDrag = () => {
    window.clearTimeout(mobileSheetTimer);
    mobileSheetTimer = 0;
    mobileSheetGesture = null;
    mobileSheetDragY.value = 0;
    isMobileSheetDragging.value = false;
    isMobileSheetSettling.value = false;
    suppressMobileSheetClick = false;
    detachPointerSheetListeners();
};

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
    resetMobileSheetDrag();

    if (mobilePlayerScrollRef.value) {
        mobilePlayerScrollRef.value.scrollTop = 0;
    }
};

const canStartMobileSheetGesture = (target) => {
    if ((mobilePlayerScrollRef.value?.scrollTop ?? 0) > 1) {
        return false;
    }

    return !target?.closest?.('button, input, .queue-item');
};

const startMobileSheetGesture = (clientX, clientY, target) => {
    if (!canStartMobileSheetGesture(target)) {
        return false;
    }

    window.clearTimeout(mobileSheetTimer);
    isMobileSheetSettling.value = false;
    mobileSheetGesture = {
        startX: clientX,
        startY: clientY,
        startTime: performance.now(),
        lastY: clientY,
    };

    return true;
};

const moveMobileSheetGesture = (clientX, clientY, event) => {
    if (!mobileSheetGesture) {
        return;
    }

    const deltaX = clientX - mobileSheetGesture.startX;
    const deltaY = clientY - mobileSheetGesture.startY;

    if (!isMobileSheetDragging.value) {
        if (deltaY < 8 || Math.abs(deltaY) <= Math.abs(deltaX)) {
            return;
        }

        isMobileSheetDragging.value = true;
        suppressMobileSheetClick = true;
    }

    event?.preventDefault?.();
    mobileSheetGesture.lastY = clientY;
    mobileSheetDragY.value = Math.max(0, deltaY);
};

const finishMobileSheetGesture = () => {
    if (!mobileSheetGesture) {
        return;
    }

    const elapsed = Math.max(1, performance.now() - mobileSheetGesture.startTime);
    const velocity = mobileSheetDragY.value / elapsed;
    const closeThreshold = Math.min(130, (typeof window === 'undefined' ? 800 : window.innerHeight) * 0.16);
    const shouldClose = isMobileSheetDragging.value
        && (mobileSheetDragY.value >= closeThreshold || (mobileSheetDragY.value >= 48 && velocity > 0.55));

    mobileSheetGesture = null;
    isMobileSheetDragging.value = false;
    isMobileSheetSettling.value = true;

    if (shouldClose) {
        mobileSheetDragY.value = typeof window === 'undefined' ? 900 : window.innerHeight;
        mobileSheetTimer = window.setTimeout(closeMobilePlayer, 220);
        return;
    }

    mobileSheetDragY.value = 0;
    mobileSheetTimer = window.setTimeout(() => {
        isMobileSheetSettling.value = false;
        suppressMobileSheetClick = false;
    }, 220);
};

const handleMobileSheetTouchStart = (event) => {
    if (event.touches.length !== 1) {
        return;
    }

    const touch = event.touches[0];
    startMobileSheetGesture(touch.clientX, touch.clientY, event.target);
};

const handleMobileSheetTouchMove = (event) => {
    if (event.touches.length !== 1) {
        return;
    }

    const touch = event.touches[0];
    moveMobileSheetGesture(touch.clientX, touch.clientY, event);
};

const handleMobileSheetTouchEnd = () => {
    finishMobileSheetGesture();
};

const handlePointerSheetMove = (event) => {
    moveMobileSheetGesture(event.clientX, event.clientY, event);
};

const handlePointerSheetUp = () => {
    detachPointerSheetListeners();
    finishMobileSheetGesture();
};

function detachPointerSheetListeners() {
    window.removeEventListener('pointermove', handlePointerSheetMove);
    window.removeEventListener('pointerup', handlePointerSheetUp);
    window.removeEventListener('pointercancel', handlePointerSheetUp);
}

const handleMobileSheetPointerDown = (event) => {
    if (event.pointerType !== 'mouse' || event.button !== 0) {
        return;
    }

    if (!startMobileSheetGesture(event.clientX, event.clientY, event.target)) {
        return;
    }

    window.addEventListener('pointermove', handlePointerSheetMove, { passive: false });
    window.addEventListener('pointerup', handlePointerSheetUp);
    window.addEventListener('pointercancel', handlePointerSheetUp);
};

const handleMobileSheetClickCapture = (event) => {
    if (!suppressMobileSheetClick) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();
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

const mediaActions = ['play', 'pause', 'stop', 'previoustrack', 'nexttrack', 'seekto', 'seekbackward', 'seekforward'];
const mediaSession = () => typeof navigator !== 'undefined' ? navigator.mediaSession : null;

const setMediaAction = (action, handler) => {
    try { mediaSession()?.setActionHandler(action, handler); } catch { /* Unsupported on this iOS version. */ }
};

const installMediaSession = () => {
    if (!mediaSession()) return;
    setMediaAction('play', () => { if (audioRef.value?.paused) void player.togglePlayback(); });
    setMediaAction('pause', () => { if (!audioRef.value?.paused) void player.togglePlayback(); });
    setMediaAction('stop', () => { audioRef.value?.pause(); player.seekTo(0); });
    setMediaAction('nexttrack', () => { void player.playNext(); });
    setMediaAction('previoustrack', () => { void player.playPrevious(); });
    setMediaAction('seekto', (details) => {
        if (Number.isFinite(details.seekTime)) player.seekTo(details.seekTime);
    });
    // Some systems otherwise show 15-second skipping instead of track navigation.
    setMediaAction('seekbackward', null);
    setMediaAction('seekforward', null);
};

const updateMediaMetadata = () => {
    const session = mediaSession();
    if (!session || typeof MediaMetadata === 'undefined') return;
    const track = currentTrack.value;
    if (!track) {
        session.metadata = null;
        return;
    }
    const ownerId = player.offlineOwnerId;
    const hasLocalArtwork = ownerId && player.offlineArtworkIds.includes(track.id) && navigator.serviceWorker?.controller;
    const cover = hasLocalArtwork ? offlineMediaUrl(ownerId, track.id, 'artwork') : (track.cover_image_url || '/icon-512.png');
    const artwork = cover ? [{ src: new URL(cover, window.location.href).href }] : [];
    session.metadata = new MediaMetadata({
        title: track.title || 'Без названия',
        artist: track.artists?.map((artist) => artist.name).join(', ') || track.artist?.name || '',
        album: track.album?.title || '',
        artwork,
    });
};

const updateMediaPosition = () => {
    const session = mediaSession();
    const duration = Number(player.duration || currentTrack.value?.duration_seconds || 0);
    if (!session || !session.setPositionState || !Number.isFinite(duration) || duration <= 0) return;
    try {
        session.setPositionState({ duration, playbackRate: 1, position: Math.max(0, Math.min(duration, player.currentTime)) });
    } catch { /* WebKit can reject position changes during metadata loading. */ }
};

const clearMediaSession = () => {
    if (!mediaSession()) return;
    mediaActions.forEach((action) => setMediaAction(action, null));
    mediaSession().metadata = null;
    mediaSession().playbackState = 'none';
};

watch([currentTrack, () => player.offlineArtworkIds], () => {
    updateMediaMetadata();
    updateMediaPosition();
});
watch([() => player.currentTime, () => player.duration], updateMediaPosition);
watch(() => player.isPlaying, (playing) => {
    if (mediaSession()) mediaSession().playbackState = currentTrack.value ? (playing ? 'playing' : 'paused') : 'none';
});

watch(currentTrack, (track) => {
    if (!track) {
        closeMobilePlayer();
    }
});

watch(currentUrl, () => {
    if (isMobilePlayerOpen.value) {
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

        <PlayerSeekBar
            class="player-bar__edge-seek"
            :value="progress"
            :max="totalDuration"
            :disabled="!currentTrack"
            edge
            @seek="progress = $event"
            @click.stop
        />

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
                                    <Link v-if="currentTrack.album.slug" :href="`/albums/${currentTrack.album.slug}`">{{ currentTrack.album.title }}</Link>
                                    <span v-else>{{ currentTrack.album.title }}</span>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div v-else class="player-bar__placeholder">
                        Выберите трек, чтобы начать прослушивание.
                    </div>

                    <div class="player-bar__controls-wrap" aria-label="Управление плеером">
                        <div class="player-bar__controls">
                            <button class="player-button" type="button" :disabled="!currentTrack" aria-label="Предыдущий трек" title="Предыдущий трек" @click="player.playPrevious()">
                                <AppIcon name="previous" :size="21" />
                            </button>

                            <button
                                class="player-button player-button--primary"
                                type="button"
                                :disabled="!currentTrack"
                                :aria-label="player.isPlaying ? 'Пауза' : 'Воспроизвести'"
                                @click="player.togglePlayback()"
                            >
                                <AppIcon :name="player.isPlaying ? 'pause' : 'play'" :size="22" />
                            </button>

                            <button class="player-button" type="button" :disabled="!currentTrack" aria-label="Следующий трек" title="Следующий трек" @click="player.playNext()">
                                <AppIcon name="next" :size="21" />
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
                            <AppIcon name="repeat" :size="20" />
                            <small v-if="player.repeatMode === 'track'">1</small>
                            <small v-else-if="player.repeatMode === 'queue'">Q</small>
                        </button>

                        <LikeButton v-if="currentTrack" :track-id="currentTrack.id" icon-only />

                        <label class="volume-control">
                            <button class="icon-button player-bar__mute-button" type="button" :disabled="!currentTrack" :aria-label="player.isMuted ? 'Включить звук' : 'Выключить звук'" @click="player.toggleMute()">
                                <AppIcon :name="player.isMuted ? 'mute' : 'volume'" :size="20" />
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
                            <AppIcon name="queue" :size="18" />
                            Очередь
                            <span class="badge">{{ player.queue.length }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div
            v-if="currentTrack"
            class="player-bar__mobile-mini"
        >
            <button
                class="player-bar__mobile-cover-button"
                type="button"
                :aria-label="player.isPlaying ? 'Пауза' : 'Воспроизвести'"
                @click="player.togglePlayback()"
            >
                <img :src="currentTrack.cover_image_url" alt="" class="player-bar__mobile-cover" draggable="false">
                <span class="player-bar__mobile-cover-overlay" aria-hidden="true">
                    <span class="player-bar__mobile-cover-icon">
                        <AppIcon :name="player.isPlaying ? 'pause' : 'play'" :size="18" />
                    </span>
                </span>
            </button>

            <button
                class="player-bar__mobile-meta"
                type="button"
                aria-label="Открыть полноэкранный плеер"
                @click="handleMiniPlayerActivate"
            >
                <strong>{{ currentTrack.title }}</strong>
                <small><TrackArtists :track="currentTrack" :links="false" /></small>
            </button>

            <div class="player-bar__mobile-controls" role="group" aria-label="Управление воспроизведением">
                <button class="player-button player-bar__mobile-skip" type="button" aria-label="Предыдущий трек" title="Предыдущий трек" @click="player.playPrevious()">
                    <AppIcon name="previous" :size="19" />
                </button>
                <button class="player-button player-bar__mobile-skip" type="button" aria-label="Следующий трек" title="Следующий трек" @click="player.playNext()">
                    <AppIcon name="next" :size="19" />
                </button>
            </div>
        </div>
    </div>

    <transition name="mobile-player-fade">
        <div v-if="isMobilePlayerOpen && currentTrack" class="mobile-player">
            <button
                class="mobile-player__backdrop"
                type="button"
                aria-label="Закрыть плеер"
                :style="mobileBackdropStyle"
                @click="closeMobilePlayer"
            ></button>

            <section
                ref="mobilePlayerSheetRef"
                class="mobile-player__sheet"
                :class="{
                    'mobile-player__sheet--full': isMobilePlayerFull,
                    'mobile-player__sheet--dragging': isMobileSheetDragging,
                    'mobile-player__sheet--settling': isMobileSheetSettling,
                }"
                :style="mobileSheetStyle"
                @click.capture="handleMobileSheetClickCapture"
                @pointerdown="handleMobileSheetPointerDown"
                @touchstart="handleMobileSheetTouchStart"
                @touchmove="handleMobileSheetTouchMove"
                @touchend="handleMobileSheetTouchEnd"
                @touchcancel="handleMobileSheetTouchEnd"
            >
                <div class="mobile-player__topline">
                    <button class="mobile-player__grabber" type="button" aria-label="Свернуть плеер" @click="closeMobilePlayer">
                        <span></span>
                    </button>
                </div>

                <div ref="mobilePlayerScrollRef" class="mobile-player__scroll" @scroll="handleMobilePlayerScroll">
                    <div class="mobile-player__hero">
                        <Link :href="currentTrack.show_url ?? `/tracks/${currentTrack.id}`" class="mobile-player__cover-link" @click="closeMobilePlayer">
                            <img :src="currentTrack.cover_image_url" :alt="currentTrack.title" class="mobile-player__cover" draggable="false">
                        </Link>

                        <div class="mobile-player__meta">
                            <strong class="mobile-player__title">
                                <Link :href="currentTrack.show_url ?? `/tracks/${currentTrack.id}`" @click="closeMobilePlayer">
                                    {{ currentTrack.title }}
                                </Link>
                            </strong>

                            <div class="mobile-player__artists" @click="closeMobilePlayer">
                                <TrackArtists :track="currentTrack" />
                            </div>

                            <Link v-if="currentTrack.album?.slug" :href="`/albums/${currentTrack.album.slug}`" class="mobile-player__album-link" @click="closeMobilePlayer">
                                {{ currentTrack.album.title }}
                            </Link>
                            <span v-else-if="currentTrack.album" class="mobile-player__album-link">{{ currentTrack.album.title }}</span>
                        </div>
                    </div>

                    <PlayerSeekBar
                        class="mobile-player__timeline"
                        :value="progress"
                        :max="totalDuration"
                        :disabled="!currentTrack"
                        show-times
                        @seek="progress = $event"
                    />

                    <div class="mobile-player__controls">
                        <button class="player-button" type="button" :disabled="!currentTrack" aria-label="Предыдущий трек" @click="player.playPrevious()">
                            <AppIcon name="previous" :size="22" />
                        </button>

                        <button
                            class="player-button player-button--primary"
                            type="button"
                            :disabled="!currentTrack"
                            :aria-label="player.isPlaying ? 'Пауза' : 'Воспроизвести'"
                            @click="player.togglePlayback()"
                        >
                            <AppIcon :name="player.isPlaying ? 'pause' : 'play'" :size="24" />
                        </button>

                        <button class="player-button" type="button" :disabled="!currentTrack" aria-label="Следующий трек" @click="player.playNext()">
                            <AppIcon name="next" :size="22" />
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
                            <AppIcon name="repeat" :size="20" />
                            <small v-if="player.repeatMode === 'track'">1</small>
                            <small v-else-if="player.repeatMode === 'queue'">Q</small>
                        </button>

                        <LikeButton v-if="currentTrack" :track-id="currentTrack.id" icon-only />

                        <button class="icon-button mobile-player__mute" type="button" :disabled="!currentTrack" :aria-label="player.isMuted ? 'Включить звук' : 'Выключить звук'" @click="player.toggleMute()">
                            <AppIcon :name="player.isMuted ? 'mute' : 'volume'" :size="20" />
                        </button>

                        <button class="ghost-button ghost-button--small mobile-player__queue-jump" type="button" @click="scrollQueueIntoView">
                            <AppIcon name="queue" :size="18" />
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

.player-bar,
.mobile-player__sheet {
    border-radius: 0;
}

.player-bar__edge-seek {
    --seek-edge-adjust: 2px;
    --seek-track: #dfe4e2;
    --seek-fill: #303b3d;
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

.player-bar__cover {
    box-shadow: 0 4px 10px #0000003d;
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
    transition: opacity 0.22s ease;
}

.mobile-player__sheet {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: min(85dvh, 860px);
    border-radius: 28px 28px 0 0;
    background: #f7f8f7;
    box-shadow: 0 -18px 45px rgba(30, 42, 40, 0.16);
    overflow: hidden;
    transition: height 0.28s cubic-bezier(0.22, 1, 0.36, 1), border-radius 0.28s ease;
    will-change: transform;
}

.mobile-player__sheet--dragging {
    transition: none;
}

.mobile-player__sheet--settling {
    transition: transform 0.22s cubic-bezier(0.22, 1, 0.36, 1), height 0.28s cubic-bezier(0.22, 1, 0.36, 1), border-radius 0.28s ease;
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
    background: #c4cbca;
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
    box-shadow: 0 4px 10px #0000003d;
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

.mobile-player__album-link { color: var(--text-soft); }

.mobile-player__controls,
.mobile-player__actions {
    display: flex;
    align-items: center;
}

.mobile-player__timeline {
    --seek-track: #d8dedb;
    --seek-fill: #303b3d;
}

.mobile-player__controls {
    justify-content: center;
    gap: 0.9rem;
}

.mobile-player__actions {
    display: grid;
    grid-template-columns: repeat(3, 42px) auto;
    justify-content: center;
    gap: 0.7rem;
}

.mobile-player__queue-jump {
    min-height: 44px;
    padding-inline: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    white-space: nowrap;
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
    color: var(--text-soft);
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
    .player-bar__edge-seek { display: none; }
    .player-bar {
        padding-top: 2px;
        border-radius: 0;
    }

    .mobile-player__sheet,
    .mobile-player__sheet--full {
        border-radius: 0;
    }

    .player-bar--empty {
        display: none;
    }

    .player-bar__desktop {
        display: none;
    }

    .player-bar__mobile-mini {
        display: grid;
        grid-template-columns: 52px minmax(0, 1fr) auto;
        align-items: center;
        gap: clamp(0.4rem, 2vw, 0.75rem);
        min-height: 72px;
    }

    .player-bar__mobile-cover-button {
        position: relative;
        display: block;
        width: 52px;
        height: 52px;
        flex: 0 0 52px;
        padding: 0;
        overflow: hidden;
        border: 0;
        border-radius: 14px;
        background: #293235;
        box-shadow: 0 4px 10px #0000003d;
        cursor: pointer;
    }

    .player-bar__mobile-cover {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: cover;
        box-shadow: none;
        transition: transform 0.2s ease, filter 0.2s ease;
    }

    .player-bar__mobile-cover-overlay {
        position: absolute;
        inset: 0;
        display: grid;
        place-items: center;
        background: linear-gradient(180deg, rgb(8 13 14 / 8%), rgb(8 13 14 / 36%));
        color: #fff;
        transition: background 0.2s ease;
    }

    .player-bar__mobile-cover-icon {
        display: grid;
        width: 30px;
        height: 30px;
        place-items: center;
        border: 1px solid rgb(255 255 255 / 52%);
        border-radius: 50%;
        background: rgb(31 41 43 / 78%);
        box-shadow: 0 2px 8px rgb(0 0 0 / 26%);
        backdrop-filter: blur(5px);
    }

    .player-bar__mobile-cover-button:hover .player-bar__mobile-cover {
        transform: scale(1.04);
        filter: brightness(0.88);
    }

    .player-bar__mobile-cover-button:focus-visible,
    .player-bar__mobile-meta:focus-visible,
    .player-bar__mobile-skip:focus-visible {
        outline: 2px solid var(--accent, #f5ed32);
        outline-offset: 3px;
    }

    .player-bar__mobile-meta {
        width: 100%;
        padding: 0;
        border: 0;
        background: transparent;
        color: inherit;
        text-align: left;
        min-width: 0;
        display: grid;
        gap: 0.35rem;
        overflow: hidden;
        cursor: pointer;
    }

    .player-bar__mobile-meta strong,
    .player-bar__mobile-meta small {
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .player-bar__mobile-controls {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex: 0 0 auto;
    }

    .player-bar__mobile-skip {
        display: grid;
        width: 40px;
        min-width: 40px;
        height: 40px;
        min-height: 40px;
        padding: 0;
        place-items: center;
    }
}

@media (max-width: 520px) {
    .player-bar__mobile-mini {
        grid-template-columns: 48px minmax(0, 1fr) auto;
        gap: 0.4rem;
    }

    .player-bar__mobile-cover-button {
        width: 48px;
        height: 48px;
        flex-basis: 48px;
    }

    .player-bar__mobile-controls {
        gap: 0.5rem;
    }

    .player-bar__mobile-skip {
        width: 38px;
        min-width: 38px;
        height: 38px;
        min-height: 38px;
    }

    .mobile-player__scroll {
        padding-inline: 14px;
    }

    .mobile-player__cover {
        width: min(80vw, 320px);
        border-radius: 24px;
    }

    .mobile-player__actions {
        gap: 0.55rem;
    }
}
</style>
