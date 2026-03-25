<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import TrackRow from '../../Components/TrackRow.vue';
import { formatCount } from '../../utils/pluralize';

defineOptions({ layout: AppLayout });

defineProps({
    artist: Object,
    albums: Array,
    tracks: Array,
});
</script>

<template>
    <Head :title="artist.name" />

    <section class="hero-card hero-card--artist">
        <img :src="artist.image_url" :alt="artist.name" class="hero-card__avatar">

        <div>
            <span class="eyebrow">Исполнитель</span>
            <h1>{{ artist.name }}</h1>
            <p>
                В каталоге {{ formatCount(artist.tracks_count, ['трек', 'трека', 'треков']) }} —
                запускайте любимые композиции и собирайте свою очередь.
            </p>
        </div>
    </section>

    <section class="section-grid">
        <div class="panel-card">
            <div class="section-heading section-heading--tight">
                <h2>Треки исполнителя</h2>
                <span class="badge">{{ tracks.length }}</span>
            </div>

            <div class="track-list">
                <TrackRow v-for="track in tracks" :key="track.id" :track="track" :queue="tracks" :show-artist="false" />
            </div>
        </div>

        <div class="panel-card">
            <div class="section-heading section-heading--tight">
                <h2>Альбомы</h2>
                <span class="badge">{{ albums.length }}</span>
            </div>

            <div class="album-grid album-grid--compact">
                <Link v-for="album in albums" :key="album.id" :href="`/albums/${album.slug}`" class="album-card">
                    <img :src="album.cover_image_url" :alt="album.title" class="album-card__cover">

                    <div class="album-card__body">
                        <strong>{{ album.title }}</strong>
                        <small>{{ album.release_date ?? 'Дата релиза уточняется' }}</small>
                    </div>
                </Link>
            </div>
        </div>
    </section>
</template>
