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
            <span v-if="index" class="track-artists__separator" aria-hidden="true">•</span>
            <Link
                v-if="links && artist.slug"
                :href="`/artists/${artist.slug}`"
                class="track-artists__link"
                @click.stop
            >
                {{ artist.name }}
            </Link>
            <span v-else class="track-artists__text">{{ artist.name }}</span>
        </template>
    </span>

    <span v-else class="track-artists__fallback">Неизвестный исполнитель</span>
</template>

<style scoped>
.track-artists {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.35rem;
    min-width: 0;
}

.track-artists__separator {
    opacity: 0.45;
    pointer-events: none;
}

.track-artists__link,
.track-artists__text,
.track-artists__fallback {
    min-width: 0;
}

.track-artists__link {
    color: inherit;
    text-decoration: none;
    transition: color 0.18s ease, opacity 0.18s ease;
}

.track-artists__link:hover {
    color: #fff;
    opacity: 1;
}
</style>
