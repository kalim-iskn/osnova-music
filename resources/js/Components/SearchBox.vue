<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    initialQuery: {
        type: String,
        default: '',
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
    <form class="search-box" @submit.prevent="submit">
        <span class="search-box__icon" aria-hidden="true">⌕</span>

        <input
            v-model="query"
            type="search"
            name="q"
            class="search-box__input"
            placeholder="Искать треки, исполнителей и альбомы"
            autocomplete="off"
            aria-label="Поиск по каталогу"
        >

        <button class="search-box__button" type="submit">Найти</button>
    </form>
</template>
