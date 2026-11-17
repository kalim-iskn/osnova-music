<script setup>
import { computed } from 'vue';

const props = defineProps({
    name: {
        type: String,
        required: true,
    },
    size: {
        type: [Number, String],
        default: 20,
    },
    strokeWidth: {
        type: [Number, String],
        default: 1.9,
    },
});

const icons = {
    search: ['M21 21l-4.35-4.35', 'M19 11a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z'],
    play: ['m8 5 11 7-11 7V5Z'],
    pause: ['M9 5v14', 'M15 5v14'],
    previous: ['M6 5v14', 'm18 6-8.5 6 8.5 6V6Z'],
    next: ['M18 5v14', 'M6 6l8.5 6L6 18V6Z'],
    repeat: ['M17 2l4 4-4 4', 'M3 11V9a3 3 0 0 1 3-3h15', 'M7 22l-4-4 4-4', 'M21 13v2a3 3 0 0 1-3 3H3'],
    volume: ['M11 5 6 9H2v6h4l5 4V5Z', 'M15.5 8.5a5 5 0 0 1 0 7', 'M18.5 5.5a9 9 0 0 1 0 13'],
    mute: ['M11 5 6 9H2v6h4l5 4V5Z', 'm17 9 5 5', 'm22 9-5 5'],
    heart: ['M12 20.5C10.9 19.4 4 14.2 4 8.9 4 6.2 6.1 4 8.8 4c1.5 0 2.7.7 3.2 1.8C12.5 4.7 13.7 4 15.2 4 17.9 4 20 6.2 20 8.9c0 5.3-6.9 10.5-8 11.6Z'],
    queue: ['M9 6h11', 'M9 12h11', 'M9 18h11', 'M4 6h.01', 'M4 12h.01', 'M4 18h.01'],
    close: ['M18 6 6 18', 'M6 6l12 12'],
    menu: ['M4 7h16', 'M4 12h16', 'M4 17h16'],
    chevronDown: ['m6 9 6 6 6-6'],
    chevronRight: ['m9 18 6-6-6-6'],
    music: ['M9 18V5l10-2v13', 'M9 9l10-2', 'M6 21a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z', 'M16 19a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z'],
    grip: ['M9 5h.01', 'M15 5h.01', 'M9 12h.01', 'M15 12h.01', 'M9 19h.01', 'M15 19h.01'],
};

const paths = computed(() => icons[props.name] ?? icons.music);
const isFilled = computed(() => ['play', 'heartFilled'].includes(props.name));
const resolvedPaths = computed(() => props.name === 'heartFilled' ? icons.heart : paths.value);
</script>

<template>
    <svg
        class="app-icon"
        :width="size"
        :height="size"
        viewBox="0 0 24 24"
        :fill="isFilled ? 'currentColor' : 'none'"
        :stroke="isFilled && name === 'play' ? 'none' : 'currentColor'"
        :stroke-width="strokeWidth"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
    >
        <path v-for="path in resolvedPaths" :key="path" :d="path" />
    </svg>
</template>

<style scoped>
.app-icon {
    display: block;
    flex: 0 0 auto;
}
</style>
