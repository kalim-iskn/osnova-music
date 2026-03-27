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
    grid-template-columns: 88px minmax(0, 1fr);
    gap: 1rem;
    align-items: center;
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
    width: 88px;
    height: 88px;
    object-fit: cover;
    border-radius: 18px;
}

.track-card__play {
    position: absolute;
    right: 0.55rem;
    bottom: 0.55rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 2rem;
    height: 2rem;
    padding: 0 0.55rem;
    border-radius: 999px;
    background: rgba(12, 14, 18, 0.8);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.track-card__body,
.track-card__meta {
    min-width: 0;
}

.track-card__title {
    margin: 0;
    font-size: 1rem;
    line-height: 1.35;
}

.track-card__title a,
.track-card__album a {
    color: inherit;
    text-decoration: none;
}

.track-card__artist,
.track-card__album {
    margin-top: 0.32rem;
    min-width: 0;
    color: rgba(255, 255, 255, 0.74);
}

.track-card__footer {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: 0.75rem;
    margin-top: 0.9rem;
    min-width: 0;
    overflow: visible;
}

.track-card__controls {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    min-width: 0;
    overflow: visible;
    flex-wrap: wrap;
}

.track-card__duration {
    flex: 0 0 auto;
    color: rgba(255, 255, 255, 0.62);
    white-space: nowrap;
}

@media (max-width: 640px) {
    .track-card {
        grid-template-columns: 72px minmax(0, 1fr);
    }

    .track-card__cover {
        width: 72px;
        height: 72px;
        border-radius: 16px;
    }

    .track-card__footer {
        grid-template-columns: 1fr;
        align-items: start;
    }

    .track-card__duration {
        justify-self: end;
    }
}
</style>
