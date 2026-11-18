<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, onUpdated, ref } from 'vue';

const viewport = ref(null);
const content = ref(null);
const overflowDistance = ref(0);
let resizeObserver = null;

const measure = () => {
    if (!viewport.value || !content.value) {
        return;
    }

    overflowDistance.value = Math.max(0, Math.ceil(content.value.scrollWidth - viewport.value.clientWidth));
};

const animationStyle = computed(() => {
    if (!overflowDistance.value) {
        return undefined;
    }

    const duration = Math.min(24, Math.max(6, overflowDistance.value / 32 + 3));

    return {
        '--marquee-distance': `${overflowDistance.value}px`,
        '--marquee-duration': `${duration}s`,
    };
});

onMounted(() => {
    measure();

    if (typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver(measure);
        if (viewport.value) resizeObserver.observe(viewport.value);
        if (content.value) resizeObserver.observe(content.value);
    }

    window.addEventListener('resize', measure);
});

onUpdated(() => {
    nextTick(measure);
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    window.removeEventListener('resize', measure);
});
</script>

<template>
    <span
        ref="viewport"
        class="marquee-text"
        :class="{ 'marquee-text--overflowing': overflowDistance > 0 }"
    >
        <span ref="content" class="marquee-text__content" :style="animationStyle">
            <slot />
        </span>
    </span>
</template>

<style scoped>
.marquee-text {
    display: block;
    width: 100%;
    min-width: 0;
    overflow: hidden;
    white-space: nowrap;
}

.marquee-text__content {
    display: inline-flex;
    align-items: center;
    width: max-content;
    min-width: max-content;
    white-space: nowrap;
}

.marquee-text--overflowing .marquee-text__content {
    animation: marquee-text-scroll var(--marquee-duration) ease-in-out 0.5s infinite alternate;
}

.marquee-text__content :deep(.track-artists) {
    flex-wrap: nowrap;
    width: max-content;
    max-width: none;
}

@keyframes marquee-text-scroll {
    to {
        transform: translate3d(calc(var(--marquee-distance) * -1), 0, 0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .marquee-text--overflowing .marquee-text__content {
        animation: none;
    }
}
</style>
