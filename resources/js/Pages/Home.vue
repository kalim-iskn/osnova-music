<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import TrackCard from '../Components/TrackCard.vue';
import { formatCount } from '../utils/pluralize';

defineOptions({ layout: AppLayout });

const props = defineProps({
    featuredTracks: Array,
    spotlightArtists: Array,
    freshAlbums: Array,
});

const page = usePage();
const appName = computed(() => page.props.appName ?? 'Музыка');
</script>

<template>
    <Head title="Главная" />

    <section class="hero-card">
        <div>
            <span class="eyebrow">Музыкальная коллекция</span>
            <h1>{{ appName }} — музыка для любого настроения.</h1>
            <p>
                Включайте любимые релизы, собирайте очередь и сохраняйте треки, к которым хочется возвращаться снова.
            </p>

            <div class="hero-card__actions">
                <Link href="/search" class="primary-button">Открыть каталог</Link>
                <Link href="/library/tracks" class="ghost-button">Мои треки</Link>
            </div>
        </div>

        <div class="hero-card__stats">
            <div class="metric-card">
                <strong>{{ featuredTracks.length }}</strong>
                <span>{{ formatCount(featuredTracks.length, ['трек', 'трека', 'треков']) }}</span>
            </div>

            <div class="metric-card">
                <strong>{{ spotlightArtists.length }}</strong>
                <span>{{ formatCount(spotlightArtists.length, ['исполнитель', 'исполнителя', 'исполнителей']) }}</span>
            </div>

            <div class="metric-card">
                <strong>{{ freshAlbums.length }}</strong>
                <span>{{ formatCount(freshAlbums.length, ['альбом', 'альбома', 'альбомов']) }}</span>
            </div>
        </div>
    </section>

    <section class="section-block">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Подборка</span>
                <h2>Популярные треки</h2>
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
                    <span class="eyebrow">Исполнители</span>
                    <h2>На виду сейчас</h2>
                </div>
            </div>

            <div class="entity-list">
                <Link
                    v-for="artist in spotlightArtists"
                    :key="artist.id"
                    :href="`/artists/${artist.slug}`"
                    class="entity-list__item"
                >
                    <img :src="artist.image_url" :alt="artist.name">

                    <span class="entity-list__meta">
                        <strong>{{ artist.name }}</strong>
                        <small>{{ formatCount(artist.tracks_count, ['трек', 'трека', 'треков']) }}</small>
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

                    <div class="album-card__body">
                        <strong>{{ album.title }}</strong>
                        <small>{{ album.artist.name }}</small>
                    </div>
                </Link>
            </div>
        </div>
    </section>
</template>
