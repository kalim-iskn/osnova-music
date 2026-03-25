<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import TrackRow from '../../Components/TrackRow.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    tracks: Array,
});

const likedTracks = ref([...props.tracks]);

const handleLikeChanged = (trackId, liked) => {
    if (!liked) {
        likedTracks.value = likedTracks.value.filter((track) => track.id !== trackId);
    }
};
</script>

<template>
        <Head title="Мои треки" />

        <section class="section-block">
            <div class="section-heading">
                <div>
                    <span class="eyebrow">Коллекция</span>
                    <h1>Мои треки</h1>
                    <p>Все лайкнутые треки пользователя доступны в одном месте.</p>
                </div>
                <span class="badge badge--large">{{ likedTracks.length }}</span>
            </div>

            <div class="panel-card">
                <div v-if="likedTracks.length" class="track-list">
                    <TrackRow
                        v-for="track in likedTracks"
                        :key="track.id"
                        :track="track"
                        :queue="likedTracks"
                        @like-changed="(liked) => handleLikeChanged(track.id, liked)"
                    />
                </div>

                <div v-else class="empty-state empty-state--large">
                    <p>Пока что у вас нет лайкнутых треков.</p>
                    <Link href="/search" class="primary-button">Перейти к поиску</Link>
                </div>
            </div>
        </section>
</template>
