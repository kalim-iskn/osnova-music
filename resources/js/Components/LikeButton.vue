<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppIcon from './AppIcon.vue';

const props = defineProps({
    trackId: {
        type: Number,
        default: null,
    },
    album: { type: Object, default: null },
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
const liked = computed(() => props.album
    ? (user.value?.liked_album_ids ?? []).includes(props.album.id)
    : likedTrackIds.value.includes(props.trackId));

const toggle = async () => {
    if (processing.value) return;
    if (!user.value) {
        router.get('/login');
        return;
    }

    processing.value = true;

    try {
        const url = props.album ? `/albums/${props.album.slug}/like` : `/tracks/${props.trackId}/like`;
        const response = liked.value
            ? await window.axios.delete(url)
            : await window.axios.post(url);

        const key = props.album ? 'liked_album_ids' : 'liked_track_ids';
        page.props.auth.user[key] = response.data[key];
        emit('changed', response.data.liked);
    } catch {
        window.alert('Не удалось сохранить изменения. Попробуйте ещё раз.');
    } finally {
        processing.value = false;
    }
};
</script>

<template>
    <button
        type="button"
        class="icon-button like-button"
        :class="{ 'icon-button--liked': liked }"
        :disabled="processing"
        :aria-pressed="liked"
        :aria-label="liked ? 'Убрать из любимых' : 'Добавить в любимые'"
        :title="liked ? 'Убрать из любимых' : 'Добавить в любимые'"
        @click.stop="toggle"
    >
        <AppIcon :name="liked ? 'heartFilled' : 'heart'" :size="20" :stroke-width="1.7" />
        <span v-if="!iconOnly">{{ liked ? 'В моих треках' : 'Лайк' }}</span>
    </button>
</template>
