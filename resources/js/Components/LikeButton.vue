<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppIcon from './AppIcon.vue';

const props = defineProps({
    trackId: {
        type: Number,
        required: true,
    },
    iconOnly: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['changed']);

const page = usePage();
const processing = ref(false);
const user = computed(() => page.props.auth?.user ?? null);
const likedTrackIds = computed(() => user.value?.liked_track_ids ?? []);
const liked = computed(() => likedTrackIds.value.includes(props.trackId));

const toggle = async () => {
    if (!user.value) {
        router.get('/login');
        return;
    }

    processing.value = true;

    try {
        const response = liked.value
            ? await window.axios.delete(`/tracks/${props.trackId}/like`)
            : await window.axios.post(`/tracks/${props.trackId}/like`);

        page.props.auth.user.liked_track_ids = response.data.liked_track_ids;
        emit('changed', response.data.liked);
    } finally {
        processing.value = false;
    }
};
</script>

<template>
    <button
        type="button"
        class="icon-button"
        :class="{ 'icon-button--liked': liked }"
        :disabled="processing"
        :aria-label="liked ? 'Убрать из любимых' : 'Добавить в любимые'"
        :title="liked ? 'Убрать из любимых' : 'Добавить в любимые'"
        @click="toggle"
    >
        <AppIcon :name="liked ? 'heartFilled' : 'heart'" :size="20" :stroke-width="1.7" />
        <span v-if="!iconOnly">{{ liked ? 'В моих треках' : 'Лайк' }}</span>
    </button>
</template>
