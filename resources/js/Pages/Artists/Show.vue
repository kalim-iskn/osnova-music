<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import MusicHero from '../../Components/MusicHero.vue';
import MusicDescription from '../../Components/MusicDescription.vue';
import AppIcon from '../../Components/AppIcon.vue';
import PaginationBar from '../../Components/PaginationBar.vue';
import ArtistSocialLinks from '../../Components/ArtistSocialLinks.vue';
import GeniusSource from '../../Components/GeniusSource.vue';
import ReleaseCard from '../../Components/ReleaseCard.vue';
import TrackRow from '../../Components/TrackRow.vue';
import { usePlayerStore } from '../../stores/player';
import { formatCount, formatNumberedCount } from '../../utils/pluralize';

defineOptions({ layout: AppLayout });

const props = defineProps({
    artist: Object,
    albums: Array,
    tracks: [Array, Object],
});

const runtime = ref(null);
const trackItems = computed(() => Array.isArray(props.tracks) ? props.tracks : props.tracks?.data ?? []);
const pagination = computed(() => Array.isArray(props.tracks) ? null : props.tracks);
const totalTracks = computed(() => pagination.value?.meta?.total ?? trackItems.value.length);
const artistDescription = computed(() => runtime.value?.artist?.description_preview ?? props.artist.description_preview ?? null);
const artistSocialLinks = computed(() => runtime.value?.artist?.social_links ?? {});
const player = usePlayerStore();
const firstTrack = computed(() => trackItems.value.find((track) => track.is_playable !== false) ?? null);
const isArtistCurrent = computed(() => player.currentTrack?.id === firstTrack.value?.id);
const isArtistPlaying = computed(() => isArtistCurrent.value && player.isPlaying);

const toggleArtistPlayback = async () => {
    if (!firstTrack.value) {
        return;
    }

    if (isArtistCurrent.value) {
        await player.togglePlayback();
        return;
    }

    await player.playTrack(firstTrack.value, trackItems.value);
};

const fetchRuntime = async () => {
    if (!props.artist?.genius_id || !window.axios) {
        return;
    }

    try {
        const { data } = await window.axios.get(`/artists/${props.artist.slug}/genius`);
        runtime.value = data;
    } catch {
        runtime.value = null;
    }
};

onMounted(fetchRuntime);
</script>

<template>
    <Head :title="artist.name" />

    <MusicHero :title="artist.name" label="Исполнитель" :cover="artist.image_url" portrait :playing="isArtistPlaying" :disabled="!firstTrack" @play="toggleArtistPlayback">
        <template #meta><span>{{ formatCount(artist.tracks_count, ['трек', 'трека', 'треков']) }} в каталоге</span><span>·</span><span>{{ formatNumberedCount(artist.plays_count, ['прослушивание', 'прослушивания', 'прослушиваний']) }}</span></template>
        <template #actions><button class="primary-button" type="button" :disabled="!firstTrack" @click="toggleArtistPlayback"><AppIcon :name="isArtistPlaying ? 'pause' : 'play'" :size="18" />{{ isArtistPlaying ? 'Пауза' : 'Слушать исполнителя' }}</button></template>
    </MusicHero>
    <div class="music-divider"><span>Музыка исполнителя</span><a href="#artist-about">Об исполнителе ↘</a></div>

    <section class="artist-music">
        <div class="artist-page__section">
            <div class="section-heading section-heading--tight">
                <h2>Треки исполнителя</h2>
                <span class="badge">{{ totalTracks }}</span>
            </div>

            <div class="track-list">
                <TrackRow
                    v-for="track in trackItems"
                    :key="track.id"
                    :track="track"
                    :queue="trackItems"
                    :show-artist="false"
                />
            </div>

            <p v-if="!trackItems.length" class="empty-state artist-page__empty">
                В каталоге пока нет треков исполнителя.
            </p>

            <PaginationBar :pagination="pagination" />
        </div>

        <div class="artist-page__section">
            <div class="section-heading section-heading--tight">
                <h2>Релизы</h2>
                <span class="badge">{{ albums.length }}</span>
            </div>

            <div class="album-grid album-grid--compact">
                <ReleaseCard v-for="album in albums" :key="album.id" :album="album" show-date />
            </div>
        </div>
    </section>
    <section id="artist-about" class="music-info-grid"><div><div class="section-heading"><h2>Об исполнителе</h2></div><MusicDescription v-if="artistDescription" :text="artistDescription" /><p v-else class="section-description">Описание исполнителя пока не добавлено.</p><GeniusSource :href="runtime?.artist?.url" /></div><div><div class="section-heading"><h2>На других площадках</h2></div><ArtistSocialLinks :links="artistSocialLinks" /><p v-if="!Object.keys(artistSocialLinks).length" class="section-description">Ссылки пока не добавлены.</p></div></section>
</template>

<style scoped>
.artist-page__hero-card {
    display: grid;
    grid-template-columns: minmax(180px, 240px) minmax(0, 1fr);
    align-items: flex-start;
    gap: 1.5rem;
}

.artist-page__hero-cover {
    align-self: flex-start;
    cursor: pointer;
}

.artist-page__hero-body {
    display: grid;
    gap: 1rem;
    align-content: start;
    justify-items: start;
    text-align: left;
}

.artist-page__play-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 240px;
    min-height: 48px;
    padding: 0.85rem 1.4rem;
    border: 0;
    border-radius: 999px;
    background: #293235;
    color: #fff;
    font-weight: 700;
    font-size: 0.95rem;
    cursor: pointer;
    box-shadow: none;
    transition: transform 0.18s ease, background-color 0.18s ease, opacity 0.18s ease;
}

.artist-page__play-button:hover:not(:disabled),
.artist-page__play-button:focus-visible:not(:disabled) {
    transform: translateY(-1px);
    background: #171e20;
}

.artist-page__play-button:disabled {
    opacity: 0.55;
    cursor: not-allowed;
    box-shadow: none;
}

.artist-page__socials {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

@media (max-width: 900px) {
    .artist-page__hero-card {
        grid-template-columns: 1fr;
    }
}
</style>
