<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { usePlayerStore } from '../stores/player';
import LikeButton from './LikeButton.vue';

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
    <article class="track-card" :class="{ 'track-card--active': active }">
        <button type="button" class="track-card__media track-card__media-button" @click="toggleTrack">
            <img :src="track.cover_image_url" :alt="track.title" class="track-card__cover">
            <span class="track-card__play" :aria-label="isPlaying ? 'Пауза' : 'Воспроизвести'">
                {{ isPlaying ? '❚❚' : '▶' }}
            </span>
        </button>

        <div class="track-card__body">
            <div class="track-card__meta">
                <h3 class="track-card__title">{{ track.title }}</h3>

                <Link class="track-card__artist" :href="`/artists/${track.artist.slug}`">
                    {{ track.artist.name }}
                </Link>

                <p v-if="track.album" class="track-card__album">
                    {{ track.album.title }}
                </p>
            </div>

            <div class="track-card__footer">
                <span class="track-card__duration">{{ track.duration_human }}</span>
                <LikeButton :track-id="track.id" icon-only />
            </div>
        </div>
    </article>
</template>
