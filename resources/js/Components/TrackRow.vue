<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { usePlayerStore } from '../stores/player';
import LikeButton from './LikeButton.vue';
import TrackArtists from './TrackArtists.vue';

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
    <article class="track-row" :class="{ 'track-row--active': active }">
        <button type="button" class="track-row__cover-button" @click="toggleTrack" :aria-label="isPlaying ? 'Пауза' : 'Воспроизвести'">
            <img :src="track.cover_image_url" :alt="track.title" class="track-row__cover">
        </button>

        <div class="track-row__meta">
            <div class="track-row__title-row">
                <Link :href="track.show_url ?? `/tracks/${track.id}`" class="track-row__title-link">
                    {{ track.title }}
                </Link>

                <button type="button" class="track-row__title-play" @click="toggleTrack">
                    {{ isPlaying ? '❚❚' : '▶' }}
                </button>
            </div>

            <div class="track-row__details">
                <TrackArtists v-if="showArtist" :track="track" />

                <template v-if="showArtist && showAlbum && track.album">
                    <span class="track-row__separator">•</span>
                </template>

                <Link v-if="showAlbum && track.album" :href="`/albums/${track.album.slug}`">
                    {{ track.album.title }}
                </Link>

                <span v-else-if="showAlbum && !track.album">Сингл</span>
            </div>
        </div>

        <div class="track-row__actions">
            <div class="track-row__lead-actions">
                <button class="icon-button track-row__play-toggle" type="button" @click="toggleTrack" :aria-label="isPlaying ? 'Пауза' : 'Воспроизвести'">
                    {{ isPlaying ? '❚❚' : '▶' }}
                </button>
                <LikeButton :track-id="track.id" icon-only @changed="(value) => emit('like-changed', value)" />
            </div>

            <span class="track-row__duration">{{ active ? player.currentTrack?.duration_human ?? track.duration_human : track.duration_human }}</span>

            <button class="ghost-button ghost-button--small track-row__queue-button" type="button" @click="addToQueue">
                В очередь
            </button>
        </div>
    </article>
</template>
