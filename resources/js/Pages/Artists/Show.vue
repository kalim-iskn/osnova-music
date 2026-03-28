<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PaginationBar from '../../Components/PaginationBar.vue';
import SocialIconLink from '../../Components/SocialIconLink.vue';
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
const firstTrack = computed(() => trackItems.value[0] ?? null);
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

    <section class="hero-card hero-card--artist artist-page__hero-card">
        <img :src="artist.image_url" :alt="artist.name" class="hero-card__avatar artist-page__hero-cover" role="button" tabindex="0" @click="toggleArtistPlayback" @keydown.enter.prevent="toggleArtistPlayback" @keydown.space.prevent="toggleArtistPlayback">

        <div class="artist-page__hero-body">
            <span class="eyebrow">Исполнитель</span>
            <h1>{{ artist.name }}</h1>
            <p class="hero-card__description hero-card__description--stacked">
                <span>{{ formatCount(artist.tracks_count, ['трек', 'трека', 'треков']) }} в каталоге.</span>
                <span>{{ formatNumberedCount(artist.plays_count, ['прослушивание', 'прослушивания', 'прослушиваний']) }}</span>
            </p>

            <p v-if="artistDescription" class="hero-card__description hero-card__description--text">
                {{ artistDescription }}
            </p>

            <button
                type="button"
                class="artist-page__play-button"
                :disabled="!firstTrack"
                @click="toggleArtistPlayback"
            >
                {{ isArtistPlaying ? 'Стоп' : 'Слушать исполнителя' }}
            </button>

            <div v-if="Object.keys(artistSocialLinks).length" class="artist-page__socials">
                <SocialIconLink
                    v-for="(value, key) in artistSocialLinks"
                    :key="key"
                    :network="key"
                    :value="value"
                />
            </div>
        </div>
    </section>

    <section class="section-grid">
        <div class="panel-card">
            <div class="section-heading section-heading--tight">
                <div>
                    <span class="eyebrow">Треки</span>
                    <h2>Треки исполнителя</h2>
                </div>
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

            <PaginationBar :pagination="pagination" />
        </div>

        <div class="panel-card">
            <div class="section-heading section-heading--tight">
                <div>
                    <span class="eyebrow">Альбомы</span>
                    <h2>Релизы</h2>
                </div>
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
    background: linear-gradient(135deg, #7c3aed, #ec4899);
    color: #fff;
    font-weight: 700;
    font-size: 0.95rem;
    cursor: pointer;
    box-shadow: 0 16px 32px rgba(124, 58, 237, 0.24);
    transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease;
}

.artist-page__play-button:hover:not(:disabled),
.artist-page__play-button:focus-visible:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 20px 40px rgba(124, 58, 237, 0.3);
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
