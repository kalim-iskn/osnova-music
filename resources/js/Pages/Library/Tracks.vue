<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PaginationBar from '../../Components/PaginationBar.vue';
import TrackRow from '../../Components/TrackRow.vue';
import AppIcon from '../../Components/AppIcon.vue';
import ReleaseCard from '../../Components/ReleaseCard.vue';
import { usePlayerStore } from '../../stores/player';
import { formatCount } from '../../utils/pluralize';
import { deleteOfflineTrack, downloadOfflineTrack, getOfflineTracks, notifyOfflineTracksChanged, offlineTrackForLibrary } from '../../utils/offlineTracks';

defineOptions({ layout: AppLayout });

const props = defineProps({ tracks: [Array, Object], releases: Object, artists: Array, artistId: Number, likedTracksTotal: Number, likedReleasesTotal: Number });
const page = usePage();
const ownerId = computed(() => page.props.auth?.user?.id);
const initialTracks = Array.isArray(props.tracks) ? props.tracks : props.tracks?.data ?? [];
const likedTracks = ref([...initialTracks]);
const totalTracks = ref(Array.isArray(props.tracks) ? initialTracks.length : props.tracks?.meta?.total ?? initialTracks.length);
const pagination = computed(() => Array.isArray(props.tracks) ? null : props.tracks);
const savedTracks = ref([]);
const busyIds = reactive({});
const offlineError = ref('');
const offlineSupported = ref(false);

const savedIds = computed(() => new Set(savedTracks.value.map((track) => track.id)));
const collectionTab = ref('liked');
const selectedArtist = ref(props.artistId ?? '');
const releases = ref(props.releases?.data ?? []);
const player = usePlayerStore();
const loadingQueue = ref(false);
const allLikedCount = computed(() => page.props.auth?.user?.liked_track_ids?.length ?? props.likedTracksTotal);
const allReleasesCount = computed(() => page.props.auth?.user?.liked_album_ids?.length ?? props.likedReleasesTotal);
const filteredReleasesCount = computed(() => Math.max(0, (props.releases?.meta?.total ?? 0) - ((props.releases?.data?.length ?? 0) - releases.value.length)));
const selectedArtistName = computed(() => artistOptions.value.find((artist) => artist.id === Number(selectedArtist.value))?.name);
const matchesArtist = (track) => !selectedArtist.value || track.artist?.id === Number(selectedArtist.value) || track.artists?.some((artist) => artist.id === Number(selectedArtist.value));
const visibleTracks = computed(() => collectionTab.value === 'saved' ? savedTracks.value.filter(matchesArtist) : likedTracks.value);
const artistOptions = computed(() => {
    const artists = new Map((props.artists ?? []).filter(artist => artist?.id != null && typeof artist.name === 'string').map((artist) => [artist.id, artist]));
    for (const track of savedTracks.value) {
        for (const artist of [...(track.artists ?? []), track.artist]) {
            if (artist?.id != null && typeof artist.name === 'string') artists.set(artist.id, artist);
        }
    }
    return [...artists.values()].sort((a, b) => a.name.localeCompare(b.name, 'ru'));
});
const syncTab = () => {
    const tab = new URLSearchParams(page.url.split('?')[1] ?? '').get('tab');
    collectionTab.value = ['liked', 'releases', 'saved'].includes(tab) ? tab : 'liked';
};
syncTab();
watch(() => props.tracks, (tracks) => {
    likedTracks.value = [...(Array.isArray(tracks) ? tracks : tracks?.data ?? [])];
    totalTracks.value = Array.isArray(tracks) ? tracks.length : tracks?.meta?.total ?? 0;
});
watch(() => props.releases, (value) => { releases.value = value?.data ?? []; });
watch(() => props.artistId, (value) => { selectedArtist.value = value ?? ''; });
watch(() => page.url, syncTab);
const navigateCollection = (tab = collectionTab.value) => {
    collectionTab.value = tab;
    const params = { ...(selectedArtist.value ? { artist: selectedArtist.value } : {}), tab };
    if (tab === 'saved') {
        // The device collection and its filters must remain usable without a server request.
        router.replace({ url: `/library/tracks?${new URLSearchParams(params)}`, preserveState: true, preserveScroll: true });
        return;
    }
    router.get('/library/tracks', params, { preserveState: true, preserveScroll: true });
};
const loadQueue = async () => {
    if (collectionTab.value === 'saved') return visibleTracks.value;
    const { data } = await window.axios.get('/library/tracks/queue', { params: selectedArtist.value ? { artist: selectedArtist.value } : {} });
    return data.tracks;
};
const playCollection = async () => {
    loadingQueue.value = true;
    offlineError.value = '';
    try {
        const tracks = await loadQueue();
        const first = tracks.find((track) => track.is_playable !== false);
        if (first) await player.playTrack(first, tracks);
    } catch {
        offlineError.value = 'Не удалось открыть выбранные треки. Попробуйте ещё раз.';
    } finally { loadingQueue.value = false; }
};

const refreshSavedTracks = async () => {
    try {
        const records = await getOfflineTracks(ownerId.value);
        savedTracks.value = records.map(record => offlineTrackForLibrary(record, ownerId.value, {
            catalogTrack: likedTracks.value.find(track => track.id === record.id),
            artists: props.artists ?? [],
        }));
    } catch {
        offlineError.value = 'Не удалось открыть локальное хранилище браузера.';
    }
};

onMounted(() => {
    offlineSupported.value = Boolean(window.isSecureContext && window.indexedDB && navigator.serviceWorker);
    void refreshSavedTracks();
    window.addEventListener('waveflow-offline-changed', refreshSavedTracks);
});
onBeforeUnmount(() => window.removeEventListener('waveflow-offline-changed', refreshSavedTracks));

const toggleDownload = async (track) => {
    if (busyIds[track.id]) return;
    offlineError.value = '';
    if (!offlineSupported.value) {
        offlineError.value = 'Для офлайн-загрузки нужен HTTPS и браузер с поддержкой Service Worker.';
        return;
    }

    busyIds[track.id] = true;
    try {
        if (savedIds.value.has(track.id)) {
            if (!window.confirm(`Удалить «${track.title}» из памяти этого устройства?`)) return;
            await deleteOfflineTrack(ownerId.value, track.id);
            notifyOfflineTracksChanged();
        } else {
            // Wait until the browser controls this page, so downloaded music is playable immediately.
            await Promise.race([
                navigator.serviceWorker.ready,
                new Promise((_, reject) => setTimeout(() => reject(new Error('Service Worker не запустился. Обновите страницу.')), 10000)),
            ]);
            if (!navigator.serviceWorker.controller) {
                throw new Error('Обновите страницу, чтобы активировать офлайн-режим.');
            }
            await downloadOfflineTrack(ownerId.value, track);
        }
    } catch (error) {
        offlineError.value = `Не удалось сохранить «${track.title}»: ${error?.message || 'ошибка загрузки'}`;
    } finally {
        busyIds[track.id] = false;
    }
};

const handleLikeChanged = (trackId, liked) => {
    if (liked) {
        // A downloaded track may be liked again from the device tab.
        const track = savedTracks.value.find((item) => item.id === trackId);
        if (track && !likedTracks.value.some((item) => item.id === trackId)) {
            likedTracks.value.unshift(track);
        }
        totalTracks.value += 1;
    } else {
        likedTracks.value = likedTracks.value.filter((track) => track.id !== trackId);
        totalTracks.value = Math.max(0, totalTracks.value - 1);
    }
};
</script>

<template>
    <Head title="Мои треки" />
    <section class="section-block library-page">
        <div class="section-heading library-heading">
            <div>
                <span class="eyebrow">Моя коллекция</span>
                <h1>Моя музыка</h1>
            </div>
            <a href="/offline.html" class="library-offline-link"><AppIcon name="downloaded" :size="18" />Офлайн-плеер<AppIcon name="externalLink" :size="13" /></a>
        </div>

        <div class="search-tabs" role="tablist" aria-label="Раздел коллекции">
            <button id="library-liked" role="tab" type="button" :aria-selected="collectionTab === 'liked'" aria-controls="library-list" @click="navigateCollection('liked')">Треки · {{ allLikedCount }}</button>
            <button id="library-releases" role="tab" type="button" :aria-selected="collectionTab === 'releases'" aria-controls="library-list" @click="navigateCollection('releases')">Релизы · {{ allReleasesCount }}</button>
            <button id="library-saved" role="tab" type="button" :aria-selected="collectionTab === 'saved'" aria-controls="library-list" @click="navigateCollection('saved')">На устройстве · {{ savedTracks.length }}</button>
        </div>

        <div class="library-toolbar">
            <button v-if="collectionTab !== 'releases'" class="primary-button" type="button" :disabled="loadingQueue || !visibleTracks.some(track => track.is_playable !== false)" @click="playCollection"><AppIcon name="play" :size="16" />{{ loadingQueue ? 'Загрузка…' : selectedArtistName ? `Слушать ${selectedArtistName}` : 'Слушать всё' }}</button>
            <label>Исполнитель<select v-model="selectedArtist" @change="navigateCollection()"><option value="">Все исполнители</option><option v-for="artist in artistOptions" :key="artist.id" :value="artist.id">{{ artist.name }}</option></select></label>
            <span class="library-toolbar__count">{{ collectionTab === 'releases' ? formatCount(filteredReleasesCount, ['релиз', 'релиза', 'релизов']) : formatCount(collectionTab === 'saved' ? visibleTracks.length : totalTracks, ['трек', 'трека', 'треков']) }}</span>
        </div>

        <p v-if="offlineError" class="library-offline-error" role="alert">{{ offlineError }}</p>
        <p v-else-if="!offlineSupported" class="section-description">Скачивание доступно в браузере с HTTPS и поддержкой офлайн-хранилища.</p>

        <div v-if="collectionTab === 'releases'" id="library-list" role="tabpanel" aria-labelledby="library-releases">
            <div v-if="releases.length" class="album-grid album-grid--home"><ReleaseCard v-for="album in releases" :key="album.id" :album="album" @like-changed="liked => { if (!liked) releases = releases.filter(item => item.id !== album.id); }" /></div>
            <p v-else class="empty-state">{{ selectedArtist ? 'Сохранённых релизов этого исполнителя пока нет.' : 'Сохраняйте понравившиеся релизы — они появятся здесь.' }}</p>
            <PaginationBar :pagination="props.releases" item-label="релизов" />
        </div>
        <div v-else-if="visibleTracks.length" id="library-list" class="track-list library-track-list" role="tabpanel" :aria-labelledby="collectionTab === 'liked' ? 'library-liked' : 'library-saved'">
            <TrackRow
                v-for="track in visibleTracks"
                :key="track.id"
                :track="track"
                :queue="visibleTracks"
                :queue-loader="loadQueue"
                @like-changed="(liked) => handleLikeChanged(track.id, liked)"
            >
                <template #before-actions>
                    <button
                        type="button"
                        class="icon-button library-download-button"
                        :class="{ 'library-download-button--saved': savedIds.has(track.id) }"
                        :disabled="busyIds[track.id] || track.is_playable === false || !offlineSupported"
                        :aria-label="busyIds[track.id] ? 'Загрузка...' : savedIds.has(track.id) ? 'Удалить скачанный трек' : 'Скачать для офлайна'"
                        :title="busyIds[track.id] ? 'Загрузка...' : savedIds.has(track.id) ? 'Скачано — нажмите, чтобы удалить' : 'Скачать для офлайна'"
                        @click="toggleDownload(track)"
                    >
                        <AppIcon :name="savedIds.has(track.id) ? 'downloaded' : 'download'" :size="20" :stroke-width="1.7" :class="{ 'library-download-button__busy': busyIds[track.id] }" />
                    </button>
                </template>
            </TrackRow>
        </div>

        <div v-else id="library-list" class="panel-card empty-state empty-state--large" role="tabpanel" :aria-labelledby="collectionTab === 'liked' ? 'library-liked' : 'library-saved'">
            <p>{{ selectedArtist ? 'Сохранённых треков этого исполнителя пока нет.' : collectionTab === 'saved' ? 'На этом устройстве пока нет скачанной музыки.' : 'Добавляйте понравившиеся треки, чтобы они появились здесь.' }}</p>
            <button v-if="collectionTab === 'saved'" type="button" class="primary-button" @click="navigateCollection('liked')">К любимым трекам</button>
            <Link v-else href="/search" class="primary-button">Перейти к каталогу</Link>
        </div>
        <PaginationBar v-if="collectionTab === 'liked'" :pagination="pagination" />
    </section>
</template>

<style scoped>
.library-offline-error { color: #b84468; margin: 1rem 0; }
.library-download-button--saved { color: #596f9d; }
.library-download-button__busy { opacity:0.4; }
.library-download-button:disabled { opacity: 0.45; cursor: not-allowed; }
</style>
