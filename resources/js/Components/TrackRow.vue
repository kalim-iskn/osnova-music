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
                    <Link :href="`/albums/${track.album.slug}`" @click.stop>
                        {{ track.album.title }}
                    </Link>
                </template>
            </div>
        </div>

        <div class="track-row__actions" @click.stop>
            <span class="track-row__duration">{{ track.duration_human }}</span>

            <button class="icon-button" type="button" aria-label="Добавить в очередь" @click="addToQueue">
                ＋
            </button>

            <TrackInfoMenu :track="track" />

            <LikeButton :track-id="track.id" icon-only @changed="(value) => emit('like-changed', value)" />
        </div>
    </article>
</template>
