<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    track: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <details class="track-info-menu" @click.stop>
        <summary class="icon-button track-info-menu__button" aria-label="Информация">
            ⓘ
        </summary>

        <div class="track-info-menu__dropdown" @click.stop>
            <Link class="track-info-menu__item" :href="track.show_url ?? `/tracks/${track.id}`">
                О треке
            </Link>

            <Link
                v-if="track.album?.slug"
                class="track-info-menu__item"
                :href="`/albums/${track.album.slug}`"
            >
                Альбом: {{ track.album.title }}
            </Link>
        </div>
    </details>
</template>

<style scoped>
.track-info-menu {
    position: relative;
}

.track-info-menu__button {
    list-style: none;
}

.track-info-menu__button::-webkit-details-marker {
    display: none;
}

.track-info-menu__dropdown {
    position: absolute;
    right: 0;
    top: calc(100% + 8px);
    z-index: 50;
    min-width: 220px;
    padding: 8px;
    border-radius: 12px;
    background: rgba(19, 19, 22, 0.98);
    border: 1px solid rgba(255, 255, 255, 0.08);
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.24);
}

.track-info-menu__item {
    display: block;
    padding: 8px 10px;
    border-radius: 8px;
    color: inherit;
    text-decoration: none;
}

.track-info-menu__item:hover {
    background: rgba(255, 255, 255, 0.06);
}
</style>
