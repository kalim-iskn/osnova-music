<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import TrackRow from '../../Components/TrackRow.vue';
import { formatCount, formatNumberedCount } from '../../utils/pluralize';

defineOptions({ layout: AppLayout });

defineProps({
    term: String,
    tracks: Array,
    artists: Array,
    albums: Array,
});
</script>

<template>
    <Head title="Поиск" />

    <section class="section-block">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Поиск</span>
                <h1>{{ term ? `Результаты по запросу «${term}»` : 'Откройте для себя новые треки' }}</h1>
                <p class="section-description">
                    Найдите музыку по названию трека, имени исполнителя или альбому.
                </p>
            </div>
        </div>

        <div class="section-grid">
            <div class="panel-card">
                <div class="section-heading section-heading--tight">
                    <h2>Треки</h2>
                    <span class="badge">{{ tracks.length }}</span>
                </div>

                <div class="track-list">
                    <TrackRow v-for="track in tracks" :key="track.id" :track="track" :queue="tracks" />
                    <p v-if="!tracks.length" class="empty-state">Подходящих треков пока нет.</p>
                </div>
            </div>

            <div class="search-side-stack">
                <div class="panel-card">
                    <div class="section-heading section-heading--tight">
                        <h2>Исполнители</h2>
                        <span class="badge">{{ artists.length }}</span>
                    </div>

                    <div class="entity-list">
                        <Link
                            v-for="artist in artists"
                            :key="artist.id"
                            :href="`/artists/${artist.slug}`"
                            class="entity-list__item"
                        >
                            <img :src="artist.image_url" :alt="artist.name">

                            <span class="entity-list__meta">
                                <strong>{{ artist.name }}</strong>
                                <small>{{ formatCount(artist.tracks_count, ['трек', 'трека', 'треков']) }}</small>
                        <small>{{ formatNumberedCount(artist.plays_count, ['прослушивание', 'прослушивания', 'прослушиваний']) }}</small>
                            </span>
                        </Link>

                        <p v-if="!artists.length" class="empty-state">Исполнителей не найдено.</p>
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
                                <small>{{ album.artist.name }}</small>
                            </div>
                        </Link>

                        <p v-if="!albums.length" class="empty-state">Альбомов не найдено.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
