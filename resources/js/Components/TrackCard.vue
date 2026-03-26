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
    <article class="track-card" :class="{ 'track-card--active': active }" @click="toggleTrack">
        <button type="button" class="track-card__media track-card__media-button" @click.stop="toggleTrack">
            <img :src="track.cover_image_url" :alt="track.title" class="track-card__cover">
            <span class="track-card__play" :aria-label="isPlaying ? 'Пауза' : 'Воспроизвести'">
                {{ isPlaying ? '❚❚' : '▶' }}
            </span>
        </button>

        <div class="track-card__body">
            <div class="track-card__meta">
                <h3 class="track-card__title">{{ track.title }}</h3>

                <div class="track-card__artist">
                    <TrackArtists :track="track" />
                </div>

                <p v-if="track.album" class="track-card__album">
                    <Link :href="`/albums/${track.album.slug}`" @click.stop>
                        {{ track.album.title }}
                    </Link>
                </p>
            </div>

            <div class="track-card__footer" @click.stop>
                <span class="track-card__duration">{{ track.duration_human }}</span>

                <div class="track-card__action-group">
                    <button
                        class="ghost-button ghost-button--small track-card__queue-button"
                        type="button"
                        aria-label="Добавить в очередь"
                        title="Добавить в очередь"
                        @click="addToQueue"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" class="track-card__queue-icon">
                            <path d="M4.5 7.25h10.75v1.5H4.5v-1.5Zm0 4h10.75v1.5H4.5v-1.5Zm0 4h7.25v1.5H4.5v-1.5Zm13.25-2.75v-2.75h1.5v2.75H22v1.5h-2.75V18.75h-1.5V14H15v-1.5h2.75Z" fill="currentColor"/>
                        </svg>
                        <span>В очередь</span>
                    </button>

                    <TrackInfoMenu :track="track" compact />
                    <LikeButton :track-id="track.id" icon-only />
                </div>
            </div>
        </div>
    </article>
</template>

<style scoped>
.track-card,
.track-card__footer {
    overflow: visible;
}

.track-card__action-group {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    overflow: visible;
}

.track-card__queue-button {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
}

.track-card__queue-icon {
    width: 1rem;
    height: 1rem;
}
</style>
