<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PaginationBar from '../../Components/PaginationBar.vue';
import TrackRow from '../../Components/TrackRow.vue';
import { formatCount } from '../../utils/pluralize';

defineOptions({ layout: AppLayout });

const props = defineProps({
    album: Object,
    tracks: [Array, Object],
});

const trackItems = computed(() => Array.isArray(props.tracks) ? props.tracks : props.tracks?.data ?? []);
const pagination = computed(() => Array.isArray(props.tracks) ? null : props.tracks);
</script>

<template>
    <Head :title="album.title" />

    <section class="hero-card hero-card--album">
        <img :src="album.cover_image_url" :alt="album.title" class="hero-card__album-cover">

        <div>
            <span class="eyebrow">Альбом</span>
            <h1>{{ album.title }}</h1>

            <p class="hero-card__description">
                <Link :href="`/artists/${album.artist.slug}`">{{ album.artist.name }}</Link>
                <span class="hero-card__separator">•</span>
                <span>{{ formatCount(album.tracks_count, ['трек', 'трека', 'треков']) }}</span>
                <template v-if="album.release_date">
                    <span class="hero-card__separator">•</span>
                    <span>{{ album.release_date }}</span>
                </template>
            </p>
        </div>
    </section>

    <section class="section-block">
        <div class="panel-card">
            <div class="section-heading section-heading--tight">
                <h2>Треклист</h2>
                <span class="badge">{{ pagination?.meta?.total ?? trackItems.length }}</span>
            </div>

            <div class="track-list">
                <TrackRow v-for="track in trackItems" :key="track.id" :track="track" :queue="trackItems" :show-album="false" />
            </div>

            <PaginationBar :pagination="pagination" />
        </div>
    </section>
</template>
