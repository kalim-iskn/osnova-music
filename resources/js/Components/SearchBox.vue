<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    initialQuery: {
        type: String,
        default: '',
    },
    compact: {
        type: Boolean,
        default: false,
    },
});

const query = ref(props.initialQuery);

watch(
    () => props.initialQuery,
    (value) => {
        query.value = value;
    },
);

const submit = () => {
    const payload = query.value.trim() ? { q: query.value.trim() } : {};

    router.get('/search', payload, {
        preserveState: true,
        preserveScroll: true,
    });
};
</script>

<template>
    <form class="search-box" :class="{ 'search-box--compact': compact }" @submit.prevent="submit">
        <span class="search-box__icon">⌕</span>
        <input
            v-model="query"
            type="search"
            name="q"
            class="search-box__input"
            placeholder="Искать треки, артистов, альбомы"
            autocomplete="off"
        >
        <button class="search-box__button" type="submit">Найти</button>
    </form>
</template>
