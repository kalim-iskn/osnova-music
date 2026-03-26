<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PaginationBar from '../../Components/PaginationBar.vue';
import TrackRow from '../../Components/TrackRow.vue';
import { formatCount, formatNumberedCount } from '../../utils/pluralize';

defineOptions({ layout: AppLayout });

const props = defineProps({
    artist: Object,
    albums: Array,
    tracks: [Array, Object],
});

const trackItems = computed(() => Array.isArray(props.tracks) ? props.tracks : props.tracks?.data ?? []);
const pagination = computed(() => Array.isArray(props.tracks) ? null : props.tracks);
const totalTracks = computed(() => pagination.value?.meta?.total ?? trackItems.value.length);
</script>

<template>
    <Head :title="artist.name" />

    <section class="hero-card hero-card--artist">
        <img :src="artist.image_url" :alt="artist.name" class="hero-card__avatar">

        <div>
            <span class="eyebrow">Исполнитель</span>
            <h1>{{ artist.name }}</h1>
            <p class="hero-card__description hero-card__description--stacked">
                <span>{{ formatCount(artist.tracks_count, ['трек', 'трека', 'треков']) }} в каталоге.</span>
                <span>{{ formatNumberedCount(artist.plays_count, ['прослушивание', 'прослушивания', 'прослушиваний']) }}</span>
            </p>

            <p v-if="artist.description_preview" class="hero-card__description hero-card__description--text">
                {{ artist.description_preview }}
            </p>
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
