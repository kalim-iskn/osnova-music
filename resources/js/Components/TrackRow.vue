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

const play = () => {
    player.playTrack(props.track, props.queue.length ? props.queue : [props.track]);
};

const addToQueue = () => {
    player.addToQueue(props.track);
};
</script>

<template>
    <article class="track-row" :class="{ 'track-row--active': active }">
        <img :src="track.cover_image_url" :alt="track.title" class="track-row__cover">

        <div class="track-row__meta">
            <button type="button" class="track-row__title" @click="play">
                {{ track.title }}
            </button>

            <div class="track-row__details">
                <Link v-if="showArtist" :href="`/artists/${track.artist.slug}`">
                    {{ track.artist.name }}
                </Link>

                <template v-if="showArtist && showAlbum && track.album">
                    <span class="track-row__separator">•</span>
                </template>

                <Link v-if="showAlbum && track.album" :href="`/albums/${track.album.slug}`">
                    {{ track.album.title }}
                </Link>

                <span v-if="!track.album">Сингл</span>
            </div>
        </div>

        <div class="track-row__actions">
            <span class="track-row__duration">{{ track.duration_human }}</span>
            <button class="ghost-button ghost-button--small" type="button" @click="play">Слушать</button>
            <button class="ghost-button ghost-button--small" type="button" @click="addToQueue">В очередь</button>
            <LikeButton :track-id="track.id" icon-only @changed="(value) => emit('like-changed', value)" />
        </div>
    </article>
</template>
