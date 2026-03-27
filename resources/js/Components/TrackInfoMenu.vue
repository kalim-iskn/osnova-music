<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    track: {
        type: Object,
        required: true,
    },
    align: {
        type: String,
        default: 'right',
    },
});
</script>

<template>
    <details class="track-info-menu" @click.stop>
        <summary class="track-info-menu__button" aria-label="Информация о треке">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8" />
                <path d="M12 10.1V16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                <circle cx="12" cy="7.5" r="1.1" fill="currentColor" />
            </svg>
        </summary>

        <div class="track-info-menu__dropdown" :class="`track-info-menu__dropdown--${align}`" @click.stop>
            <Link class="track-info-menu__item" :href="track.show_url ?? `/tracks/${track.id}`">
                О треке
            </Link>

            <Link
                v-if="track.album?.slug"
                class="track-info-menu__item"
                :href="`/albums/${track.album.slug}`"
            >
                К альбому · {{ track.album.title }}
            </Link>
        </div>
    </details>
</template>

<style scoped>
.track-info-menu {
    position: relative;
    flex: 0 0 auto;
}

.track-info-menu__button {
    list-style: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    background: rgba(255, 255, 255, 0.04);
    color: rgba(255, 255, 255, 0.86);
    cursor: pointer;
    transition: transform 0.18s ease, background-color 0.18s ease, border-color 0.18s ease;
}

.track-info-menu__button:hover {
    background: rgba(255, 255, 255, 0.08);
    border-color: rgba(255, 255, 255, 0.16);
    transform: translateY(-1px);
}

.track-info-menu__button::-webkit-details-marker {
    display: none;
}

.track-info-menu__button svg {
    width: 1.1rem;
    height: 1.1rem;
}

.track-info-menu__dropdown {
    position: absolute;
    top: calc(100% + 10px);
    z-index: 60;
    min-width: 230px;
    padding: 0.45rem;
    border-radius: 14px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    background: rgba(17, 18, 22, 0.98);
    box-shadow: 0 20px 44px rgba(0, 0, 0, 0.34);
    backdrop-filter: blur(14px);
}

.track-info-menu__dropdown--right {
    right: 0;
}

.track-info-menu__dropdown--left {
    left: 0;
}

.track-info-menu__item {
    display: block;
    padding: 0.75rem 0.85rem;
    border-radius: 10px;
    color: inherit;
    text-decoration: none;
    white-space: nowrap;
}

.track-info-menu__item:hover {
    background: rgba(255, 255, 255, 0.06);
}
</style>
