<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { usePlayerStore } from '../stores/player';
import LikeButton from './LikeButton.vue';
import TrackArtists from './TrackArtists.vue';
import TrackInfoMenu from './TrackInfoMenu.vue';

const props = defineProps({
    track: {
        type: Object,
        required: true,
    },
    queue: {
        type: Array,
        default: () => [],
    },
});

const player = usePlayerStore();
const active = computed(() => player.currentTrack?.id === props.track.id);
const isPlaying = computed(() => active.value && player.isPlaying);

const toggleTrack = async () => {
    if (active.value) {
        await player.togglePlayback();
        return;
    }

    await player.playTrack(props.track, props.queue.length ? props.queue : [props.track]);
};

const addToQueue = () => {
    player.addToQueue(props.track);
};
</script>

<template>
    <article class="track-card" :class="{ 'track-card--active': active }">
        <button type="button" class="track-card__media track-card__media-button" @click="toggleTrack">
            <img :src="track.cover_image_url" :alt="track.title" class="track-card__cover">
            <span class="track-card__play" :aria-label="isPlaying ? 'Пауза' : 'Воспроизвести'">
                {{ isPlaying ? '❚❚' : '▶' }}
            </span>
        </button>

        <div class="track-card__body">
            <div class="track-card__meta">
                <h3 class="track-card__title">
                    <Link :href="track.show_url ?? `/tracks/${track.id}`">
                        {{ track.title }}
                    </Link>
                </h3>

                <div class="track-card__artist">
                    <TrackArtists :track="track" />
                </div>

                <p v-if="track.album" class="track-card__album">
                    <Link :href="`/albums/${track.album.slug}`" @click.stop>
                        {{ track.album.title }}
                    </Link>
                </p>
            </div>

            <div class="track-card__footer">
                <div class="track-card__controls">
                    <button class="ghost-button ghost-button--small" type="button" @click.stop="addToQueue">
                        В очередь
                    </button>
                    <TrackInfoMenu :track="track" align="left" />
                    <LikeButton :track-id="track.id" icon-only />
                </div>

                <span class="track-card__duration">{{ track.duration_human }}</span>
            </div>
        </div>
    </article>
</template>

<style scoped>
.track-card {
    display: grid;
    grid-template-columns: 84px minmax(0, 1fr);
    gap: 14px;
    align-items: center;
    height: 100%;
    min-height: 158px;
    padding: 14px;
    border-radius: 18px;
    overflow: visible;
}

.track-card__media-button {
    position: relative;
    padding: 0;
    border: 0;
    background: transparent;
    cursor: pointer;
}

.track-card__cover {
    display: block;
    width: 84px;
    height: 84px;
    object-fit: cover;
    border-radius: 14px;
}

.track-card__play {
    position: absolute;
    right: 6px;
    bottom: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    min-width: 36px;
    height: 36px;
    padding: 0;
    border-radius: 999px;
    color: #fff;
    background: #293235;
    border: 0;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.28);
}

.track-card__body,
.track-card__meta {
    min-width: 0;
}

.track-card__body {
    display: flex;
    flex-direction: column;
    align-self: stretch;
    justify-content: center;
    gap: 8px;
    padding: 0;
}

.track-card__meta {
    display: grid;
    align-content: start;
    gap: 3px;
}

.track-card__title {
    margin: 0;
    font-size: 0.98rem;
    line-height: 1.3;
    letter-spacing: -0.02em;
}

.track-card__title a {
    display: -webkit-box;
    overflow: hidden;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
}

.track-card__title a,
.track-card__album a {
    color: inherit;
    text-decoration: none;
}

.track-card__artist,
.track-card__album {
    margin-top: 2px;
    min-width: 0;
    color: var(--text-soft);
    font-size: 0.84rem;
    line-height: 1.25;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.track-card__footer {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-top: auto;
    min-width: 0;
    overflow: visible;
}

.track-card__controls {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
    overflow: visible;
    flex-wrap: nowrap;
}

.track-card__controls :deep(.ghost-button--small) {
    padding: 8px 11px;
    font-size: 0.82rem;
}

.track-card__duration {
    flex: 0 0 auto;
    margin-left: auto;
    color: var(--text-soft);
    white-space: nowrap;
    font-size: 0.82rem;
}

@media (max-width: 640px) {
    .track-card {
        grid-template-columns: 68px minmax(0, 1fr);
        min-height: 132px;
        gap: 12px;
        padding: 12px;
    }

    .track-card__cover {
        width: 68px;
        height: 68px;
        border-radius: 12px;
    }

    .track-card__footer {
        gap: 6px;
    }

    .track-card__controls {
        gap: 5px;
    }

    .track-card__controls :deep(.ghost-button--small) {
        padding: 7px 9px;
        font-size: 0.78rem;
    }
}
</style>
