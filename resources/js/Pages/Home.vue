<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import TrackArtists from '../Components/TrackArtists.vue';
import TrackRow from '../Components/TrackRow.vue';
import { usePlayerStore } from '../stores/player';

defineOptions({ layout: AppLayout });

const props = defineProps({
    featuredTracks: Array,
    spotlightArtists: Array,
    freshAlbums: Array,
});

const player = usePlayerStore();
const leadTrack = computed(() => props.featuredTracks?.[0] ?? null);
const leadTrackIsPlaying = computed(() => player.currentTrack?.id === leadTrack.value?.id && player.isPlaying);

const playLeadTrack = async () => {
    if (!leadTrack.value) {
        return;
    }

    if (player.currentTrack?.id === leadTrack.value.id) {
        await player.togglePlayback();
        return;
    }

    await player.playTrack(leadTrack.value, props.featuredTracks ?? [leadTrack.value]);
};
</script>

<template>
    <Head title="Главная" />

    <section class="home-intro">
        <span class="eyebrow">WAVEFLOW · ГЛАВНАЯ</span>
        <h1>Твоя музыка</h1>
        <p>Знакомое, любимое и то, что захочется включить дальше.</p>
    </section>

    <article v-if="leadTrack" class="home-featured-track">
        <div class="home-featured-track__meta">
            <span class="eyebrow">Популярное сейчас</span>
            <h2>{{ leadTrack.title }}</h2>
            <p><TrackArtists :track="leadTrack" /></p>
            <div class="home-featured-track__controls">
                <button class="player-button player-button--primary" type="button" @click="playLeadTrack">
                    {{ leadTrackIsPlaying ? '❚❚' : '▶' }}
                </button>
                <span>{{ leadTrack.duration_human }}</span>
            </div>
        </div>
        <img :src="leadTrack.cover_image_url" :alt="leadTrack.title" class="home-featured-track__cover">
    </article>

    <section class="section-block home-section">
        <div class="section-heading">
            <div>
                <h2>Популярные треки</h2>
            </div>
            <Link href="/search" class="section-heading__link">Весь каталог <span aria-hidden="true">→</span></Link>
        </div>

        <div class="track-list home-track-list">
            <TrackRow v-for="track in featuredTracks.slice(1)" :key="track.id" :track="track" :queue="featuredTracks" />
        </div>
    </section>

    <section class="section-block home-section">
        <div class="section-heading">
            <div>
                <h2>Свежие альбомы</h2>
            </div>
            <Link href="/search" class="section-heading__link">Открыть поиск <span aria-hidden="true">→</span></Link>
        </div>

        <div class="album-grid album-grid--home">
            <Link v-for="album in freshAlbums" :key="album.id" :href="`/albums/${album.slug}`" class="album-card">
                <img :src="album.cover_image_url" :alt="album.title" class="album-card__cover">
                <div class="album-card__body">
                    <strong>{{ album.title }}</strong>
                    <small>{{ album.artist.name }}</small>
                </div>
            </Link>
        </div>
    </section>

    <section class="section-block home-section home-artists-section">
        <div class="section-heading">
            <div>
                <h2>Исполнители</h2>
            </div>
        </div>

        <div class="home-artist-list">
            <Link v-for="artist in spotlightArtists" :key="artist.id" :href="`/artists/${artist.slug}`" class="home-artist">
                <img :src="artist.image_url" :alt="artist.name">
                <strong>{{ artist.name }}</strong>
            </Link>
        </div>
    </section>
</template>
