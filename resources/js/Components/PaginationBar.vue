<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    pagination: {
        type: Object,
        default: null,
    },
});

const meta = computed(() => props.pagination?.meta ?? null);
const links = computed(() => props.pagination?.links ?? null);
const hasPagination = computed(() => Boolean(meta.value && (meta.value.last_page > 1 || links.value?.prev || links.value?.next)));
</script>

<template>
    <div v-if="hasPagination" class="pagination-bar">
        <div class="pagination-bar__meta">
            <span>Страница {{ meta.current_page }} из {{ meta.last_page }}</span>
            <span>{{ meta.total }} треков</span>
        </div>

        <div class="pagination-bar__actions">
            <Link v-if="links.prev" :href="links.prev" class="ghost-button ghost-button--small">Назад</Link>
            <button v-else type="button" class="ghost-button ghost-button--small" disabled>Назад</button>

            <Link v-if="links.next" :href="links.next" class="ghost-button ghost-button--small">Вперёд</Link>
            <button v-else type="button" class="ghost-button ghost-button--small" disabled>Вперёд</button>
        </div>
    </div>
</template>
