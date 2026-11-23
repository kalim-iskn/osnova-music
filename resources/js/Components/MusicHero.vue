<script setup>
import { computed, ref, useId, watch } from 'vue';
import { artworkPalette } from '../utils/artworkPalette';
const props = defineProps({
    title: String,
    label: String,
    cover: String,
    portrait: Boolean,
    disabled: Boolean,
    playing: Boolean,
});
defineEmits(['play']);
const palette = ref(null);
const recordPathId = `record-title-${useId().replace(/[^a-zA-Z0-9-]/g, '')}`;
const recordTitle = computed(() => {
    const letters = Array.from(props.title ?? '');
    return letters.length > 44 ? `${letters.slice(0, 43).join('')}…` : props.title;
});
const auraStyle = computed(() => palette.value ? { '--aura-one': palette.value[0], '--aura-two': palette.value[1] } : {});
watch(() => props.cover, async (cover, _, onCleanup) => {
    let current = true;
    onCleanup(() => { current = false; });
    palette.value = null;
    const result = await artworkPalette(cover);
    if (current) palette.value = result;
}, { immediate: true });
</script>

<template>
    <section class="music-hero" :class="{ 'music-hero--portrait': portrait }">
        <div class="music-hero__aura" :class="{ 'music-hero__aura--palette': palette }" :style="auraStyle" aria-hidden="true"><img v-if="cover && !palette" :src="cover" alt=""></div>
        <div class="music-hero__body">
            <span class="eyebrow">{{ label }}</span>
            <h1 :class="{ 'music-hero__title--long': title?.length > 16 }">{{ title }}</h1>
            <div class="music-hero__meta"><slot name="meta" /></div>
            <div v-if="$slots.tags" class="music-hero__tags"><slot name="tags" /></div>
            <div class="music-hero__actions"><slot name="actions" /></div>
        </div>
        <div class="music-hero__art">
            <div v-if="!portrait" class="music-hero__record" aria-hidden="true">
                <svg class="music-hero__record-title" viewBox="0 0 300 300">
                    <defs><path :id="recordPathId" d="M 186 30 A 125 125 0 0 1 186 270" /></defs>
                    <text :font-size="recordTitle?.length > 28 ? 10 : 12"><textPath :href="`#${recordPathId}`" startOffset="50%" text-anchor="middle">{{ recordTitle }}</textPath></text>
                </svg>
            </div>
            <button class="music-hero__cover" type="button" :disabled="disabled" :aria-label="`${playing ? 'Пауза' : 'Слушать'}: ${title}`" @click="$emit('play')">
                <img :src="cover" :alt="title">
            </button>
        </div>
    </section>
</template>
