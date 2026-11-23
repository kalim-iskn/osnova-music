<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PaginationBar from '../../Components/PaginationBar.vue';
import SearchBox from '../../Components/SearchBox.vue';
import ReleaseCard from '../../Components/ReleaseCard.vue';
import TrackRow from '../../Components/TrackRow.vue';
import { formatCount } from '../../utils/pluralize';

defineOptions({ layout: AppLayout });

const props = defineProps({
    term: String,
    tracks: Object,
    artists: Array,
    albums: Array,
});

const selectedTab = ref('all');
const trackItems = computed(() => props.tracks?.data ?? []);
const trackTotal = computed(() => props.tracks?.meta?.total ?? trackItems.value.length);
</script>

<template>
    <Head title="Поиск" />

    <section class="section-block search-page">
        <div class="section-heading search-page__intro">
            <div>
                <span class="eyebrow">Поиск</span>
                <h1>{{ term ? `Результаты по запросу «${term}»` : 'Откройте для себя новые треки' }}</h1>
                <p class="section-description">
                    Найдите музыку по названию трека, имени исполнителя или альбому.
                </p>
            </div>
        </div>

        <div class="search-page__box">
            <SearchBox :initial-query="term ?? ''" />
        </div>

        <div class="search-tabs" role="tablist" aria-label="Категории поиска"><button v-for="tab in [{key:'all',label:'Всё'},{key:'tracks',label:'Треки'},{key:'artists',label:'Артисты'},{key:'albums',label:'Релизы'}]" :id="'search-tab-' + tab.key" :key="tab.key" type="button" role="tab" :aria-selected="selectedTab === tab.key" aria-controls="search-results" @click="selectedTab = tab.key">{{ tab.label }}</button></div><div id="search-results" class="search-layout" role="tabpanel" :aria-labelledby="'search-tab-' + selectedTab">
            <div v-show="selectedTab === 'all' || selectedTab === 'tracks'" class="panel-card">
                <div class="section-heading section-heading--tight">
                    <h2>Треки</h2>
                    <span class="badge">{{ trackTotal }}</span>
                </div>

                <div class="track-list">
                    <TrackRow v-for="track in trackItems" :key="track.id" :track="track" :queue="trackItems" />
                    <p v-if="!trackItems.length" class="empty-state">Подходящих треков пока нет.</p>
                </div>

                <PaginationBar :pagination="tracks" />
            </div>

            <div class="search-side-stack" style="display:contents">
                <div v-show="selectedTab === 'all' || selectedTab === 'artists'" class="panel-card">
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
                            </span>
                        </Link>

                        <p v-if="!artists.length" class="empty-state">Исполнителей не найдено.</p>
                    </div>
                </div>

                <div v-show="selectedTab === 'all' || selectedTab === 'albums'" class="panel-card">
                    <div class="section-heading section-heading--tight">
                        <h2>Релизы</h2>
                        <span class="badge">{{ albums.length }}</span>
                    </div>

                    <div class="album-grid album-grid--compact">
                        <ReleaseCard v-for="album in albums" :key="album.id" :album="album" />

                        <p v-if="!albums.length" class="empty-state">Релизов не найдено.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
