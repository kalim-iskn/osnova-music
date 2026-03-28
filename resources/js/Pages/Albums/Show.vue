<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PaginationBar from '../../Components/PaginationBar.vue';
import TrackRow from '../../Components/TrackRow.vue';
import { usePlayerStore } from '../../stores/player';
import { formatCount } from '../../utils/pluralize';

defineOptions({ layout: AppLayout });

const props = defineProps({
    album: Object,
    tracks: [Array, Object],
});

const runtime = ref(null);
const trackItems = computed(() => Array.isArray(props.tracks) ? props.tracks : props.tracks?.data ?? []);
const pagination = computed(() => Array.isArray(props.tracks) ? null : props.tracks);
const albumDescription = computed(() => runtime.value?.album?.description_preview ?? null);
const displayReleaseDate = computed(() => runtime.value?.album?.release_date ?? props.album.release_date ?? null);
const albumArtists = computed(() => Array.isArray(props.album?.artists) && props.album.artists.length ? props.album.artists : (props.album?.artist ? [props.album.artist] : []));
const player = usePlayerStore();
const firstTrack = computed(() => trackItems.value[0] ?? null);
const isAlbumCurrent = computed(() => player.currentTrack?.id === firstTrack.value?.id);
const isAlbumPlaying = computed(() => isAlbumCurrent.value && player.isPlaying);

const toggleAlbumPlayback = async () => {
    if (!firstTrack.value) {
        return;
    }

    if (isAlbumCurrent.value) {
        await player.togglePlayback();
        return;
    }

    await player.playTrack(firstTrack.value, trackItems.value);
};

const fetchRuntime = async () => {
    if (!props.album?.genius_id || !window.axios) {
        return;
    }

    try {
        const { data } = await window.axios.get(`/albums/${props.album.slug}/genius`);
        runtime.value = data;
    } catch {
        runtime.value = null;
    }
};

onMounted(fetchRuntime);
</script>

<template>
    <Head :title="album.title" />

    <section class="hero-card hero-card--album album-page__hero-card">
        <img :src="runtime?.album?.cover_image_url ?? album.cover_image_url" :alt="album.title" class="hero-card__album-cover album-page__hero-cover" role="button" tabindex="0" @click="toggleAlbumPlayback" @keydown.enter.prevent="toggleAlbumPlayback" @keydown.space.prevent="toggleAlbumPlayback">

        <div class="album-page__hero-body">
            <span class="eyebrow">Альбом</span>
            <h1>{{ album.title }}</h1>

            <p class="hero-card__description album-page__artists-row">
                <template v-for="(artist, index) in albumArtists" :key="artist.id ?? artist.slug ?? artist.name ?? index">
                    <span v-if="index" class="hero-card__separator">•</span>
                    <Link v-if="artist.slug" :href="`/artists/${artist.slug}`">{{ artist.name }}</Link>
                    <span v-else>{{ artist.name }}</span>
                </template>
                <span class="hero-card__separator">•</span>
                <span>{{ formatCount(album.tracks_count, ['трек', 'трека', 'треков']) }}</span>
                <template v-if="displayReleaseDate">
                    <span class="hero-card__separator">•</span>
                    <span>{{ displayReleaseDate }}</span>
                </template>
            </p>

            <p v-if="albumDescription" class="hero-card__description hero-card__description--text">
                {{ albumDescription }}
            </p>

            <button
                type="button"
                class="album-page__play-button"
                :disabled="!firstTrack"
                @click="toggleAlbumPlayback"
            >
                {{ isAlbumPlaying ? 'Стоп' : 'Слушать альбом' }}
            </button>
        </div>
    </section>

    <section class="section-block">
        <div class="panel-card">
            <div class="section-heading section-heading--tight">
                <h2>Треклист</h2>
                <span class="badge">{{ pagination?.meta?.total ?? trackItems.length }}</span>
            </div>

            <div class="track-list">
                <TrackRow
                    v-for="track in trackItems"
                    :key="track.id"
                    :track="track"
                    :queue="trackItems"
                    :show-album="false"
                />
            </div>

            <PaginationBar :pagination="pagination" />
        </div>
    </section>
</template>

<style scoped>
.album-page__hero-card {
    display: grid;
    grid-template-columns: minmax(180px, 240px) minmax(0, 1fr);
    align-items: flex-start;
    gap: 1.5rem;
}

.album-page__hero-cover {
    align-self: flex-start;
    cursor: pointer;
}

.album-page__hero-body {
    display: grid;
    gap: 1rem;
    align-content: start;
    justify-items: start;
    text-align: left;
}

.album-page__artists-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.album-page__play-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 220px;
    min-height: 48px;
    padding: 0.85rem 1.4rem;
    border: 0;
    border-radius: 999px;
    background: linear-gradient(135deg, #7c3aed, #ec4899);
    color: #fff;
    font-weight: 700;
    font-size: 0.95rem;
    cursor: pointer;
    box-shadow: 0 16px 32px rgba(124, 58, 237, 0.24);
    transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease;
}

.album-page__play-button:hover:not(:disabled),
.album-page__play-button:focus-visible:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 20px 40px rgba(124, 58, 237, 0.3);
}

.album-page__play-button:disabled {
    opacity: 0.55;
    cursor: not-allowed;
    box-shadow: none;
}

@media (max-width: 900px) {
    .album-page__hero-card {
        grid-template-columns: 1fr;
    }
}
</style>
