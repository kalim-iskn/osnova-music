<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { usePlayerStore } from '../stores/player';
import LikeButton from './LikeButton.vue';
import TrackArtists from './TrackArtists.vue';
import AppIcon from './AppIcon.vue';
import MarqueeText from './MarqueeText.vue';
import TrackActionsMenu from './TrackActionsMenu.vue';

const props = defineProps({
    queueLoader: { type: Function, default: null },
    track: {
        type: Object,
        required: true,
    },
    queue: {
        type: Array,
        default: () => [],
    },
    showAlbum: {
        type: Boolean,
        default: true,
    },
    showArtist: {
        type: Boolean,
        default: true,
    },
    showTrackNumber: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['like-changed']);
const player = usePlayerStore();

const active = computed(() => player.currentTrack?.id === props.track.id);
const isPlaying = computed(() => active.value && player.isPlaying);

const toggleTrack = async () => {
    if (props.track.is_playable === false) return;
    if (active.value) {
        await player.togglePlayback();
        return;
    }

    try {
        const queue = props.queueLoader ? await props.queueLoader() : props.queue;
        await player.playTrack(props.track, queue.length ? queue : [props.track]);
    } catch {
        window.alert('Не удалось открыть очередь. Попробуйте ещё раз.');
    }
};

</script>

<template>
    <article class="track-row" :class="{ 'track-row--active': active, 'track-row--numbered': showTrackNumber }">
        <span v-if="showTrackNumber" class="track-row__number">{{ track.track_number }}</span>
        <button type="button" class="track-row__cover-button" :disabled="track.is_playable === false" @click="toggleTrack" :aria-label="track.is_playable === false ? 'Аудио пока недоступно' : (isPlaying ? 'Пауза' : 'Воспроизвести')">
            <img :src="track.cover_image_url" :alt="track.title" class="track-row__cover">
            <span v-if="track.is_playable !== false" class="track-row__cover-state">
                <AppIcon :name="isPlaying ? 'pause' : 'play'" :size="16" />
            </span>
        </button>

        <div class="track-row__main">
            <h3 class="track-row__title">
                <MarqueeText>
                    <Link :href="track.show_url ?? `/tracks/${track.id}`">
                        {{ track.title }}
                    </Link>
                </MarqueeText>
            </h3>

            <MarqueeText class="track-row__meta">
                <TrackArtists v-if="showArtist" :track="track" />

                <template v-if="showAlbum && track.album">
                    <span v-if="showArtist" class="track-row__separator">•</span>
                    <Link v-if="track.album.slug" :href="`/albums/${track.album.slug}`" @click.stop>
                        {{ track.album.title }}
                    </Link>
                    <span v-else>{{ track.album.title }}</span>
                </template>
            </MarqueeText>
            <MarqueeText v-if="track.is_playable === false" class="track-row__meta">Аудио пока недоступно</MarqueeText>
        </div>

        <div class="track-row__actions">
            <div class="track-row__buttons">
                <slot name="before-actions" :track="track" />
                <LikeButton :track-id="track.id" icon-only @changed="(value) => emit('like-changed', value)" />
                <TrackActionsMenu :track="track" />
            </div>

            <span class="track-row__duration">{{ active ? player.currentTrack?.duration_human ?? track.duration_human : track.duration_human }}</span>
        </div>
    </article>
</template>

<style scoped>
.track-row {
    display: grid;
    grid-template-columns: 56px minmax(0, 1fr) auto;
    align-items: center;
    gap: 0.9rem;
    width: 100%;
    min-width: 0;
}

.track-row--numbered {
    grid-template-columns: 2rem 56px minmax(0, 1fr) auto;
}

.track-row__number {
    color: var(--text-soft);
    font-size: 0.9rem;
    font-variant-numeric: tabular-nums;
    text-align: center;
}

.track-row__cover-button {
    position: relative;
    padding: 0;
    border: 0;
    background: transparent;
    cursor: pointer;
}

.track-row__cover {
    display: block;
    width: 56px;
    height: 56px;
    object-fit: cover;
    border-radius: 14px;
}

.track-row__cover-state {
    position: absolute;
    right: 0.35rem;
    bottom: 0.35rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.6rem;
    height: 1.6rem;
    padding: 0 0.4rem;
    border-radius: 999px;
    color: #fff;
    background: #293235;
    border: 0;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25);
}

.track-row__main {
    min-width: 0;
}

.track-row__title {
    margin: 0;
    font-size: 1rem;
    line-height: 1.35;
}

.track-row__title a,
.track-row__meta a {
    color: inherit;
    text-decoration: none;
}

.track-row__meta {
    color: var(--text-soft);
    margin-top: 0.3rem;
}

.track-row__separator {
    color: #a2abad;
}

.track-row__actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.85rem;
}

.track-row__buttons {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    flex: 0 0 auto;
    flex-wrap: nowrap;
}

.track-row__duration {
    white-space: nowrap;
    color: var(--text-soft);
}

@media (max-width: 720px) {
    .track-row {
        grid-template-columns: 48px minmax(0, 1fr) auto;
        align-items: center;
        gap: 0.55rem;
    }

    .track-row__cover {
        width: 48px;
        height: 48px;
    }

    .track-row--numbered {
        grid-template-columns: 1.5rem 48px minmax(0, 1fr) auto;
    }

    .track-row__actions {
        grid-column: auto;
        gap: 0.35rem;
        width: auto;
        margin-top: 0;
    }

    .track-row__buttons {
        min-width: 0;
        flex-wrap: nowrap;
        gap: 0.2rem;
    }

    .track-row__buttons :deep(.icon-button),
    .track-row__buttons :deep(.track-actions-menu__trigger) {
        width: 38px;
        min-width: 38px;
        height: 38px;
        min-height: 38px;
        padding: 0;
    }

    .track-row__duration {
        min-width: 34px;
        text-align: right;
        font-size: 0.82rem;
    }
}

@media (max-width: 420px) {
    .track-row__actions {
        gap: 0;
    }

    .track-row__buttons {
        gap: 0.1rem;
    }

    .track-row__buttons :deep(.icon-button),
    .track-row__buttons :deep(.track-actions-menu__trigger) {
        width: 36px;
        min-width: 36px;
        height: 36px;
        min-height: 36px;
    }

    .track-row__duration { display: none; }
}
</style>
