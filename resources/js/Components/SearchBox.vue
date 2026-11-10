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
    <form class="search-box" @submit.prevent="submit">
        <span class="search-box__icon" aria-hidden="true">⌕</span>

        <input
            v-model="query"
            type="search"
            name="q"
            class="search-box__input"
            placeholder="Треки, артисты, альбомы"
            autocomplete="off"
            aria-label="Поиск по каталогу"
        >

        <button
            class="search-box__button"
            :class="{ 'search-box__button--icon': compact }"
            :aria-label="compact ? 'Найти' : undefined"
            type="submit"
        >
            {{ compact ? '⌕' : 'Найти' }}
        </button>
    </form>
</template>
