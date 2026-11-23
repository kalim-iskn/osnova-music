<script setup>
import { computed, ref, watch } from 'vue';
import SocialIconLink from './SocialIconLink.vue';

const props = defineProps({ links: { type: Object, default: () => ({}) } });
const expanded = ref(false);
const entries = computed(() => Object.entries(props.links ?? {}).filter(([, value]) => typeof value === 'string' && value.trim()));
const visible = computed(() => expanded.value ? entries.value : entries.value.slice(0, 5));
watch(() => props.links, () => { expanded.value = false; });
</script>

<template>
    <div class="artist-social-links">
        <SocialIconLink v-for="[network, value] in visible" :key="network" :network="network" :value="value" show-label />
        <button v-if="entries.length > 5" type="button" class="artist-social-links__more" :aria-expanded="expanded" @click="expanded = !expanded">{{ expanded ? 'Скрыть' : `Показать ещё · ${entries.length - 5}` }}</button>
    </div>
</template>

<style scoped>
.artist-social-links { display:flex; flex-wrap:wrap; align-items:center; gap:4px 8px; min-width:0; }
.artist-social-links__more { border:0; background:none; padding:8px 6px; min-height:40px; color:#686075; font:inherit; font-size:12px; cursor:pointer; }
</style>
