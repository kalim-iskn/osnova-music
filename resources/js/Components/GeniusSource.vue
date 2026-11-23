<script setup>
import AppIcon from './AppIcon.vue';
defineProps({ href: String });
</script>
<template>
    <a v-if="href" :href="href" target="_blank" rel="noopener noreferrer" class="music-source-link">Источник: genius <AppIcon name="externalLink" :size="12" /></a>
</template>
