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
    showAlbum: {
        type: Boolean,
        default: true,
    },
    showArtist: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['like-changed']);
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
    <article class="track-row" :class="{ 'track-row--active': active }" @click="toggleTrack">
        <button type="button" class="track-row__cover-button" @click.stop="toggleTrack">
            <img :src="track.cover_image_url" :alt="track.title" class="track-row__cover">
            <span class="track-row__cover-state" :aria-label="isPlaying ? 'Пауза' : 'Воспроизвести'">
                {{ isPlaying ? '❚❚' : '▶' }}
            </span>
        </button>

        <div class="track-row__main">
            <strong class="track-row__title">{{ track.title }}</strong>

            <div class="track-row__meta">
                <TrackArtists v-if="showArtist" :track="track" />

                <template v-if="showAlbum && track.album">
                    <span v-if="showArtist" class="track-row__separator">•</span>
                    <Link class="track-row__album-link" :href="`/albums/${track.album.slug}`" @click.stop>
                        {{ track.album.title }}
                    </Link>
                </template>
            </div>
        </div>

        <div class="track-row__actions" @click.stop>
            <span class="track-row__duration">{{ track.duration_human }}</span>

            <button
                class="ghost-button ghost-button--small track-row__queue-button"
                type="button"
                aria-label="Добавить в очередь"
                title="Добавить в очередь"
                @click="addToQueue"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true" class="track-row__queue-icon">
                    <path d="M4.5 7.25h10.75v1.5H4.5v-1.5Zm0 4h10.75v1.5H4.5v-1.5Zm0 4h7.25v1.5H4.5v-1.5Zm13.25-2.75v-2.75h1.5v2.75H22v1.5h-2.75V18.75h-1.5V14H15v-1.5h2.75Z" fill="currentColor"/>
                </svg>
                <span>В очередь</span>
            </button>

            <TrackInfoMenu :track="track" compact />

            <LikeButton :track-id="track.id" icon-only @changed="(value) => emit('like-changed', value)" />
        </div>
    </article>
</template>

<style scoped>
.track-row {
    overflow: visible;
}

.track-row__meta,
.track-row__actions {
    overflow: visible;
}

.track-row__queue-button {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
}

.track-row__queue-icon {
    width: 1rem;
    height: 1rem;
}

.track-row__album-link {
    color: inherit;
    text-decoration: none;
}

.track-row__album-link:hover {
    color: #fff;
}
</style>
