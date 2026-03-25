<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import TrackCard from '../Components/TrackCard.vue';

defineOptions({ layout: AppLayout });

defineProps({
    featuredTracks: Array,
    spotlightArtists: Array,
    freshAlbums: Array,
});
</script>

<template>
        <Head title="Главная" />

        <section class="hero-card">
            <div>
                <span class="eyebrow">Современный music streaming UI</span>
                <h1>Музыкальный сервис на Laravel, где плеер не перезагружается между страницами.</h1>
                <p>
                    Очередь воспроизведения, лайки, поиск по каталогу и плавная SPA-навигация через Inertia.
                </p>
                <div class="hero-card__actions">
                    <Link href="/search" class="primary-button">Открыть поиск</Link>
                    <Link href="/library/tracks" class="ghost-button">Мои треки</Link>
                </div>
            </div>
            <div class="hero-card__stats">
                <div class="metric-card">
                    <strong>{{ featuredTracks.length }}</strong>
                    <span>готовых демо-треков</span>
                </div>
                <div class="metric-card">
                    <strong>{{ spotlightArtists.length }}</strong>
                    <span>исполнителей в сидере</span>
                </div>
                <div class="metric-card">
                    <strong>SPA</strong>
                    <span>без полной перезагрузки страницы</span>
                </div>
            </div>
        </section>

        <section class="section-block">
            <div class="section-heading">
                <div>
                    <span class="eyebrow">Подборка</span>
                    <h2>Рекомендуемые треки</h2>
                </div>
            </div>

            <div class="track-card-grid">
                <TrackCard v-for="track in featuredTracks" :key="track.id" :track="track" :queue="featuredTracks" />
            </div>
        </section>

        <section class="section-grid">
            <div class="panel-card">
                <div class="section-heading section-heading--tight">
                    <div>
                        <span class="eyebrow">Артисты</span>
                        <h2>Spotlight</h2>
                    </div>
                </div>

                <div class="entity-list">
                    <Link v-for="artist in spotlightArtists" :key="artist.id" :href="`/artists/${artist.slug}`" class="entity-list__item">
                        <img :src="artist.image_url" :alt="artist.name">
                        <span>
                            <strong>{{ artist.name }}</strong>
                            <small>{{ artist.tracks_count }} треков</small>
                        </span>
                    </Link>
                </div>
            </div>

            <div class="panel-card">
                <div class="section-heading section-heading--tight">
                    <div>
                        <span class="eyebrow">Альбомы</span>
                        <h2>Свежие релизы</h2>
                    </div>
                </div>

                <div class="album-grid album-grid--compact">
                    <Link v-for="album in freshAlbums" :key="album.id" :href="`/albums/${album.slug}`" class="album-card">
                        <img :src="album.cover_image_url" :alt="album.title" class="album-card__cover">
                        <div>
                            <strong>{{ album.title }}</strong>
                            <small>{{ album.artist.name }}</small>
                        </div>
                    </Link>
                </div>
            </div>
        </section>
</template>
