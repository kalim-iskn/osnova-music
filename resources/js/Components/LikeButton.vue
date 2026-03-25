<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

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
        @click="toggle"
    >
        <span aria-hidden="true">{{ liked ? '♥' : '♡' }}</span>
        <span v-if="!iconOnly">{{ liked ? 'В моих треках' : 'Лайк' }}</span>
    </button>
</template>
