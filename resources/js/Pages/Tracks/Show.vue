<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import SocialIconLink from '../../Components/SocialIconLink.vue';
import TrackArtists from '../../Components/TrackArtists.vue';
import TrackRow from '../../Components/TrackRow.vue';
import { usePlayerStore } from '../../stores/player';

defineOptions({ layout: AppLayout });

const props = defineProps({
    track: {
        type: Object,
        required: true,
    },
    relatedTracks: {
        type: Array,
        default: () => [],
    },
});

const runtime = ref(null);
const runtimeLoading = ref(false);

const displayGenres = computed(() => {
    const storedGenres = Array.isArray(props.track.genres) ? props.track.genres : [];
    const runtimeGenres = Array.isArray(runtime.value?.song?.genres) ? runtime.value.song.genres : [];

    return [...new Set([...storedGenres, ...runtimeGenres])].filter(Boolean);
});

const languageLabel = computed(() => {
    const language = runtime.value?.song?.language ?? props.track.language ?? null;

    if (!language) {
        return null;
    }

    const labels = {
        ru: 'Русский',
        en: 'English',
    };

    return labels[language] ?? String(language).toUpperCase();
});

const trackDescription = computed(() => runtime.value?.song?.description_preview ?? props.track.description_preview ?? null);
const currentAlbum = computed(() => props.track.album ?? runtime.value?.song?.album ?? null);
const runtimeArtistsById = computed(() => new Map((runtime.value?.artists ?? []).map((artist) => [Number(artist.id), artist])));
const player = usePlayerStore();
const playbackQueue = computed(() => [props.track, ...props.relatedTracks.filter((relatedTrack) => Number(relatedTrack?.id) !== Number(props.track?.id))]);
const isTrackCurrent = computed(() => player.currentTrack?.id === props.track?.id);
const isTrackPlaying = computed(() => isTrackCurrent.value && player.isPlaying);

const toggleTrackPlayback = async () => {
    if (!props.track) {
        return;
    }

    if (isTrackCurrent.value) {
        await player.togglePlayback();
        return;
    }

    await player.playTrack(props.track, playbackQueue.value);
};

const currentArtists = computed(() => {
    const baseArtists = Array.isArray(props.track.artists) && props.track.artists.length
        ? props.track.artists
        : props.track.artist
            ? [props.track.artist]
            : [];

    return baseArtists.map((artist) => {
        const runtimeArtist = artist.genius_id ? runtimeArtistsById.value.get(Number(artist.genius_id)) : null;

        return {
            ...artist,
            image_url: artist.image_url ?? props.track.cover_image_url,
            social_links: runtimeArtist?.social_links ?? {},
            genius_url: runtimeArtist?.url ?? null,
        };
    });
});

const fetchRuntime = async () => {
    if (!props.track.genius_id || !window.axios) {
        return;
    }

    runtimeLoading.value = true;

    try {
        const { data } = await window.axios.get(`/tracks/${props.track.id}/genius`);
        runtime.value = data;
    } catch {
        runtime.value = null;
    } finally {
        runtimeLoading.value = false;
    }
};

onMounted(fetchRuntime);
</script>

<template>
    <Head :title="track.title" />

    <section class="hero-card hero-card--track track-page__hero-card">
        <img :src="track.cover_image_url" :alt="track.title" class="hero-card__album-cover track-page__hero-cover" role="button" tabindex="0" @click="toggleTrackPlayback" @keydown.enter.prevent="toggleTrackPlayback" @keydown.space.prevent="toggleTrackPlayback">

        <div class="track-page__hero-body">
            <span class="eyebrow">Трек</span>
            <h1>{{ track.title }}</h1>

            <p class="hero-card__description track-page__description-row">
                <TrackArtists :artists="currentArtists" />
                <template v-if="currentAlbum">
                    <span class="hero-card__separator">•</span>
                    <Link v-if="currentAlbum.slug" :href="`/albums/${currentAlbum.slug}`">{{ currentAlbum.title }}</Link>
                    <span v-else>{{ currentAlbum.title }}</span>
                </template>
                <template v-if="track.release_year">
                    <span class="hero-card__separator">•</span>
                    <span>{{ track.release_year }}</span>
                </template>
            </p>

            <div class="track-page__chips">
                <span class="badge">{{ track.duration_human }}</span>
                <span v-if="languageLabel" class="badge">{{ languageLabel }}</span>
                <span v-for="genre in displayGenres" :key="genre" class="badge">{{ genre }}</span>
            </div>

            <button
                type="button"
                class="track-page__play-button"
                @click="toggleTrackPlayback"
            >
                {{ isTrackPlaying ? 'Стоп' : 'Слушать трек' }}
            </button>

            <div v-if="runtimeLoading" class="track-page__status">Загружаю расширенные данные Genius…</div>
        </div>
    </section>

    <section class="section-grid track-page__content-grid">
        <div class="panel-card">
            <div class="section-heading section-heading--tight">
                <div>
                    <span class="eyebrow">Описание</span>
                    <h2>О треке</h2>
                </div>
            </div>

            <p v-if="trackDescription" class="track-page__description">
                {{ trackDescription }}
            </p>
            <p v-else class="track-page__description track-page__description--muted">
                Для этого трека пока нет краткого описания.
            </p>

            <div class="track-page__links">
                <a
                    v-if="runtime?.song?.url || track.genius_url"
                    :href="runtime?.song?.url ?? track.genius_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="ghost-button"
                >
                    Genius
                </a>
            </div>
        </div>

        <div class="panel-card">
            <div class="section-heading section-heading--tight">
                <div>
                    <span class="eyebrow">Исполнители</span>
                    <h2>Участники трека</h2>
                </div>
            </div>

            <div class="track-page__artist-grid">
                <article
                    v-for="artist in currentArtists"
                    :key="artist.id ?? artist.name"
                    class="track-page__artist-card"
                >
                    <Link v-if="artist.slug" :href="`/artists/${artist.slug}`" class="track-page__artist-avatar-link">
                        <img
                            :src="artist.image_url ?? track.cover_image_url"
                            :alt="artist.name"
                            class="track-page__artist-avatar"
                        >
                    </Link>
                    <img
                        v-else
                        :src="artist.image_url ?? track.cover_image_url"
                        :alt="artist.name"
                        class="track-page__artist-avatar"
                    >

                    <div class="track-page__artist-body">
                        <h3 class="track-page__artist-name">
                            <Link v-if="artist.slug" :href="`/artists/${artist.slug}`">
                                {{ artist.name }}
                            </Link>
                            <span v-else>{{ artist.name }}</span>
                        </h3>

                        <div v-if="artist.social_links && Object.keys(artist.social_links).length" class="track-page__artist-socials">
                            <SocialIconLink
                                v-for="(value, key) in artist.social_links"
                                :key="key"
                                :network="key"
                                :value="value"
                            />
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <section class="section-block">
        <div class="panel-card">
            <div class="section-heading section-heading--tight">
                <div>
                    <span class="eyebrow">Похожие</span>
                    <h2>Ещё послушать</h2>
                </div>
                <span class="badge">{{ relatedTracks.length }}</span>
            </div>

            <div class="track-list">
                <TrackRow
                    v-for="relatedTrack in relatedTracks"
                    :key="relatedTrack.id"
                    :track="relatedTrack"
                    :queue="relatedTracks"
                />
            </div>
        </div>
    </section>
</template>

<style scoped>
.track-page__hero-card {
    display: grid;
    grid-template-columns: minmax(180px, 240px) minmax(0, 1fr);
    align-items: flex-start;
    gap: 1.5rem;
}

.track-page__hero-cover {
    align-self: flex-start;
    cursor: pointer;
}

.track-page__hero-body {
    display: grid;
    gap: 1rem;
    align-content: start;
    justify-items: start;
    justify-content: start;
    text-align: left;
    max-width: 100%;
}

.track-page__description-row,
.track-page__chips,
.track-page__links,
.track-page__artist-socials {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.track-page__play-button {
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
    transition: transform 0.18s ease, box-shadow 0.18s ease;
}

.track-page__play-button:hover,
.track-page__play-button:focus-visible {
    transform: translateY(-1px);
    box-shadow: 0 20px 40px rgba(124, 58, 237, 0.3);
}

.track-page__content-grid {
    align-items: start;
}

.track-page__status {
    color: rgba(255, 255, 255, 0.7);
}

.track-page__description {
    line-height: 1.7;
    color: rgba(255, 255, 255, 0.82);
    text-align: left;
}

.track-page__description--muted {
    color: rgba(255, 255, 255, 0.56);
}

.track-page__artist-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1rem;
}

.track-page__artist-card {
    display: grid;
    grid-template-columns: 72px minmax(0, 1fr);
    gap: 1rem;
    align-items: flex-start;
    width: 100%;
    padding: 1rem;
    border-radius: 1.25rem;
    background: rgba(255, 255, 255, 0.04);
}

.track-page__artist-body {
    min-width: 0;
}

.track-page__artist-avatar,
.track-page__artist-avatar-link {
    display: block;
}

.track-page__artist-avatar {
    width: 72px;
    height: 72px;
    border-radius: 1rem;
    object-fit: cover;
}

.track-page__artist-name {
    margin: 0 0 0.65rem;
}

.track-page__artist-name a {
    color: inherit;
    text-decoration: none;
}

@media (max-width: 900px) {
    .track-page__hero-card {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 720px) {
    .track-page__artist-card {
        grid-template-columns: 56px minmax(0, 1fr);
    }

    .track-page__artist-avatar {
        width: 56px;
        height: 56px;
    }
}
</style>
