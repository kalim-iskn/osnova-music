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
                <TrackInfoMenu :track="track" />
                <LikeButton :track-id="track.id" icon-only />
            </div>
        </div>
    </article>
</template>
