<script setup>
import { ref, watch } from 'vue';
const props = defineProps({ text: String });
const expanded = ref(false);
watch(() => props.text, () => { expanded.value = false; });
</script>
<template>
    <div class="music-description">
        <p :class="{ 'music-description__text--collapsed': !expanded && text?.length > 320 }" class="music-description__text">{{ text }}</p>
        <button v-if="text?.length > 320" class="music-description__toggle" type="button" :aria-expanded="expanded" @click="expanded = !expanded">{{ expanded ? 'Свернуть' : 'Читать полностью' }}</button>
    </div>
</template>
