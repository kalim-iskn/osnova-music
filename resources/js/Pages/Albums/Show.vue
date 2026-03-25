<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import TrackRow from '../../Components/TrackRow.vue';
import { formatCount } from '../../utils/pluralize';

defineOptions({ layout: AppLayout });

defineProps({
    album: Object,
    tracks: Array,
});
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
            </div>

            <div class="track-list">
                <TrackRow v-for="track in tracks" :key="track.id" :track="track" :queue="tracks" :show-album="false" />
            </div>
        </div>
    </section>
</template>
