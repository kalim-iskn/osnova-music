<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    track: {
        type: Object,
        required: true,
    },
    compact: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <details class="track-info-menu" @click.stop>
        <summary class="track-info-menu__button" :class="{ 'track-info-menu__button--compact': compact }" aria-label="Информация о треке">
            <svg viewBox="0 0 24 24" aria-hidden="true" class="track-info-menu__icon">
                <path d="M12 3.75a8.25 8.25 0 1 0 0 16.5a8.25 8.25 0 0 0 0-16.5Zm0 4a1 1 0 1 1 0 2a1 1 0 0 1 0-2Zm1.25 8.5h-2.5v-1.5h.75v-3h-1v-1.5h2.75v4.5h.75v1.5Z" fill="currentColor"/>
            </svg>
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
    overflow: visible;
}

.track-info-menu[open] {
    z-index: 40;
}

.track-info-menu__button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.5rem;
    height: 2.5rem;
    border: none;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.06);
    color: rgba(255, 255, 255, 0.92);
    cursor: pointer;
    list-style: none;
    transition: background 0.18s ease, transform 0.18s ease;
}

.track-info-menu__button:hover {
    background: rgba(255, 255, 255, 0.1);
    transform: translateY(-1px);
}

.track-info-menu__button--compact {
    width: 2.25rem;
    height: 2.25rem;
}

.track-info-menu__button::-webkit-details-marker {
    display: none;
}

.track-info-menu__icon {
    width: 1.1rem;
    height: 1.1rem;
}

.track-info-menu__dropdown {
    position: absolute;
    right: 0;
    top: calc(100% + 10px);
    z-index: 60;
    min-width: 230px;
    padding: 0.45rem;
    border-radius: 1rem;
    background: rgba(20, 20, 24, 0.98);
    border: 1px solid rgba(255, 255, 255, 0.08);
    box-shadow: 0 18px 42px rgba(0, 0, 0, 0.38);
    backdrop-filter: blur(18px);
}

.track-info-menu__item {
    display: block;
    padding: 0.7rem 0.8rem;
    border-radius: 0.8rem;
    color: inherit;
    text-decoration: none;
    transition: background 0.18s ease, color 0.18s ease;
}

.track-info-menu__item:hover {
    background: rgba(255, 255, 255, 0.07);
    color: #fff;
}
</style>
