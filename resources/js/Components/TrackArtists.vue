<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    track: {
        type: Object,
        default: null,
    },
    artists: {
        type: Array,
        default: null,
    },
    links: {
        type: Boolean,
        default: true,
    },
});

const resolvedArtists = computed(() => {
    const source = Array.isArray(props.artists) && props.artists.length
        ? props.artists
        : Array.isArray(props.track?.artists) && props.track.artists.length
            ? props.track.artists
            : props.track?.artist
                ? [props.track.artist]
                : [];

    return source.filter((artist, index, collection) => {
        if (!artist) {
            return false;
        }

        const key = artist.id ?? artist.slug ?? artist.name ?? index;

        return collection.findIndex((item, itemIndex) => {
            const itemKey = item?.id ?? item?.slug ?? item?.name ?? itemIndex;
            return itemKey === key;
        }) === index;
    });
});
</script>

<template>
    <span v-if="resolvedArtists.length" class="track-artists">
        <template v-for="(artist, index) in resolvedArtists" :key="artist.id ?? `${artist.name}-${index}`">
            <span v-if="index" class="track-artists__separator">•</span>
            <Link v-if="links && artist.slug" :href="`/artists/${artist.slug}`" class="track-artists__link">
                {{ artist.name }}
            </Link>
            <span v-else>{{ artist.name }}</span>
        </template>
    </span>

    <span v-else class="track-artists__fallback">Неизвестный исполнитель</span>
</template>
