<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const artistForm = reactive({ artist: '', page_limit: 0 });
const listForm = reactive({ url: 'https://muzofond.fm/popular/russian-rap', artist_limit: 100, page_limit: 0 });
const status = ref({ queue: { pending: 0, reserved: 0, worker_state: 'idle' }, runs: [] });
const loadingStatus = ref(false);
const submitting = ref(false);
const cancellingTaskId = ref(null);
const formError = ref('');
const notice = ref('');
let pollTimer;

const activeTasks = computed(() => status.value.runs.reduce((sum, run) => sum + run.tasks.filter((task) => ['queued', 'running'].includes(task.status)).length, 0));

const statusLabel = (value) => ({
    queued: 'В очереди',
    running: 'В работе',
    completed: 'Завершено',
    failed: 'Ошибка',
    cancelled: 'Удалено из очереди',
    retry_wait: 'Повтор через паузу',
    waiting_for_worker: 'Ожидает обработчика',
    processing: 'Обрабатывается',
    idle: 'Очередь пуста',
}[value] ?? value);

const stageLabel = (value) => ({
    queued: 'Ожидает запуска',
    discovering_artists: 'Читаю список артистов Muzofond',
    queueing_artists: 'Добавляю артистов в очередь',
    processing_artists: 'Обрабатываю артистов',
    fetching_muzofond: 'Загружаю страницы артиста с Muzofond',
    matching_genius: 'Сопоставляю треки с Genius и сохраняю каталог',
    retry_wait: 'Задание будет повторено после паузы',
    finished: 'Все задания обработаны',
    completed: 'Артист обработан',
    failed: 'Задание завершилось ошибкой',
    cancelled: 'Задание удалено из очереди',
}[value] ?? value);

const formatTime = (value) => value ? new Date(value).toLocaleString('ru-RU') : '—';
const durationLabel = (seconds) => {
    if (seconds < 60) return `${seconds} сек.`;
    const minutes = Math.floor(seconds / 60);
    const rest = seconds % 60;
    return rest ? `${minutes} мин. ${rest} сек.` : `${minutes} мин.`;
};
const durationMsLabel = (milliseconds) => durationLabel(Math.max(0, Math.round((Number(milliseconds) || 0) / 1000)));
const metricGroupLabel = (group) => ({
    artist_search: 'поиск артиста',
    song_search: 'поиск треков',
    song_detail: 'данные треков',
    album: 'альбомы',
    artist_catalog: 'каталог артиста',
    other: 'прочие',
}[group] ?? group);
const metricStatusesLabel = (statuses = {}) => Object.entries(statuses)
    .filter(([code]) => !/^2\d\d$/.test(code))
    .map(([code, count]) => `${code} × ${count}`)
    .join(', ');
const metricGroups = (metrics) => Object.entries(metrics?.groups ?? {}).map(([group, metric]) => ({ group, metric }));

const refreshStatus = async () => {
    if (!window.axios || loadingStatus.value) return;
    loadingStatus.value = true;

    try {
        const { data } = await window.axios.get('/tools/parsing/status');
        status.value = data;
    } catch {
        formError.value = 'Не удалось обновить статус. Проверьте подключение к сайту.';
    } finally {
        loadingStatus.value = false;
    }
};

const submit = async (url, payload) => {
    submitting.value = true;
    formError.value = '';
    notice.value = '';

    try {
        const { data } = await window.axios.post(url, payload);
        notice.value = `${data.message} Номер запуска: #${data.run_id}.`;
        await refreshStatus();
    } catch (error) {
        const errors = error?.response?.data?.errors ?? {};
        formError.value = Object.values(errors).flat().join(' ') || error?.response?.data?.message || 'Не удалось поставить задание в очередь.';
    } finally {
        submitting.value = false;
    }
};

const enqueueArtist = () => submit('/tools/parsing/artist', { ...artistForm });
const enqueueList = () => submit('/tools/parsing/artist-list', { ...listForm });

const cancelTask = async (task) => {
    cancellingTaskId.value = task.id;
    formError.value = '';
    notice.value = '';

    try {
        const { data } = await window.axios.delete(`/tools/parsing/tasks/${task.id}`);
        notice.value = data.message;
        await refreshStatus();
    } catch (error) {
        formError.value = error?.response?.data?.message || 'Не удалось удалить задание из очереди.';
        await refreshStatus();
    } finally {
        cancellingTaskId.value = null;
    }
};

onMounted(() => {
    refreshStatus();
    pollTimer = window.setInterval(refreshStatus, 3000);
});

onUnmounted(() => window.clearInterval(pollTimer));
</script>

<template>
    <Head title="Статус парсинга" />

    <section class="section-block parsing-page">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Инструменты</span>
                <h1>Парсинг Muzofond</h1>
                <p class="section-description">Запускайте артистов и списки в фоне. Здесь видно очередь, текущий этап, прогресс и ошибки.</p>
            </div>
            <button class="ghost-button" type="button" :disabled="loadingStatus" @click="refreshStatus">
                {{ loadingStatus ? 'Обновляю…' : 'Обновить' }}
            </button>
        </div>

        <div v-if="notice" class="parsing-message parsing-message--success">{{ notice }}</div>
        <div v-if="formError" class="parsing-message parsing-message--error">{{ formError }}</div>

        <div class="parsing-forms">
            <form class="panel-card parsing-form" @submit.prevent="enqueueArtist">
                <div>
                    <span class="eyebrow">Один артист</span>
                    <h2>Добавить в очередь</h2>
                    <p class="parsing-muted">Вставьте ссылку Muzofond или slug, например <code>saluki</code>.</p>
                </div>

                <label class="form-field">
                    <span>Ссылка или slug артиста</span>
                    <input v-model="artistForm.artist" type="text" placeholder="https://muzofond.fm/collections/artists/saluki" required>
                </label>
                <label class="form-field">
                    <span>Страниц артиста</span>
                    <input v-model.number="artistForm.page_limit" type="number" min="0" max="100">
                    <small>0 — без ограничения</small>
                </label>
                <button class="primary-button" type="submit" :disabled="submitting">Поставить артиста в очередь</button>
            </form>

            <form class="panel-card parsing-form" @submit.prevent="enqueueList">
                <div>
                    <span class="eyebrow">Список артистов</span>
                    <h2>Разобрать список</h2>
                    <p class="parsing-muted">Подойдут список артистов или жанровая страница. Найденные артисты получат отдельные задания.</p>
                </div>

                <label class="form-field">
                    <span>Ссылка на список артистов</span>
                    <input v-model="listForm.url" type="url" placeholder="https://muzofond.fm/popular/russian-rap" required>
                </label>
                <div class="parsing-form__row">
                    <label class="form-field">
                        <span>Артистов</span>
                        <input v-model.number="listForm.artist_limit" type="number" min="1" max="1000" required>
                    </label>
                    <label class="form-field">
                        <span>Страниц на артиста</span>
                        <input v-model.number="listForm.page_limit" type="number" min="0" max="100">
                        <small>0 — без ограничения</small>
                    </label>
                </div>
                <button class="primary-button" type="submit" :disabled="submitting">Поставить список в очередь</button>
            </form>
        </div>

        <div class="panel-card parsing-queue-summary">
            <div>
                <span class="eyebrow">Очередь tracks-parsing</span>
                <h2>{{ statusLabel(status.queue.worker_state) }}</h2>
            </div>
            <div class="parsing-queue-summary__stats">
                <span><strong>{{ status.queue.pending }}</strong> ожидают</span>
                <span><strong>{{ status.queue.reserved }}</strong> выполняются</span>
                <span><strong>{{ activeTasks }}</strong> активных артистов</span>
                <span v-if="status.queue.oldest_wait_seconds"><strong>{{ durationLabel(status.queue.oldest_wait_seconds) }}</strong> ждёт старшее задание</span>
            </div>
            <p v-if="status.queue.worker_state === 'waiting_for_worker'" class="parsing-warning">
                Есть задания без запущенного обработчика. Проверьте контейнер app и процесс tracks-queue-worker.
            </p>
            <p v-if="status.queue.untracked_pending || status.queue.untracked_reserved" class="parsing-warning">
                В очереди есть {{ status.queue.untracked_pending + status.queue.untracked_reserved }} заданий без карточек прогресса. Они были запущены старым способом и появятся в списке только при новом запуске через эту страницу.
            </p>
        </div>

        <div class="section-heading section-heading--tight parsing-history-heading">
            <div>
                <h2>Запуски и задания</h2>
                <p class="section-description">Статус обновляется автоматически каждые 3 секунды.</p>
            </div>
            <span class="badge">{{ status.runs.length }}</span>
        </div>

        <div v-if="!status.runs.length" class="panel-card empty-state">Запусков пока нет.</div>

        <article v-for="run in status.runs" :key="run.id" class="panel-card parsing-run">
            <header class="parsing-run__header">
                <div>
                    <div class="parsing-run__title">
                        <span class="badge">#{{ run.id }}</span>
                        <h3>{{ run.kind === 'artist_list' ? 'Список артистов' : 'Артист' }}</h3>
                        <span class="parsing-status" :class="`parsing-status--${run.status}`">{{ statusLabel(run.status) }}</span>
                    </div>
                    <a :href="run.source_url" target="_blank" rel="noopener noreferrer">{{ run.source_url }}</a>
                    <p class="parsing-muted">{{ stageLabel(run.stage) }} · создан {{ formatTime(run.created_at) }}</p>
                    <p class="parsing-muted parsing-run__duration">
                        Общее время парсинга: {{ run.duration_seconds === null ? 'ещё не начинался' : durationLabel(run.duration_seconds) }}
                    </p>
                </div>
                <div class="parsing-run__counts" v-if="run.total_tasks">
                    <strong>{{ run.completed_tasks + run.failed_tasks + run.cancelled_tasks }}/{{ run.total_tasks }}</strong>
                    <small>артистов завершено</small>
                </div>
            </header>

            <p v-if="run.error" class="parsing-message parsing-message--error">{{ run.error }}</p>

            <div v-if="run.tasks.length" class="parsing-tasks">
                <div v-for="task in run.tasks" :key="task.id" class="parsing-task">
                    <div class="parsing-task__main">
                        <strong>{{ task.artist_name || task.artist_slug || task.artist_url }}</strong>
                        <span class="parsing-status" :class="`parsing-status--${task.status}`">{{ statusLabel(task.status) }}</span>
                        <small>Время артиста: {{ task.duration_seconds === null ? 'ожидает запуска' : durationLabel(task.duration_seconds) }}</small>
                        <small>{{ stageLabel(task.stage) }}</small>
                        <small v-if="task.current_track">Сейчас: {{ task.current_track }}</small>
                        <button
                            v-if="task.can_cancel"
                            class="parsing-task__cancel"
                            type="button"
                            :disabled="cancellingTaskId === task.id"
                            @click="cancelTask(task)"
                        >
                            {{ cancellingTaskId === task.id ? 'Удаляю…' : 'Убрать из очереди' }}
                        </button>
                    </div>
                    <div v-if="task.tracks_total" class="parsing-task__progress">
                        <div class="parsing-progress"><span :style="{ width: `${Math.min(100, Math.round(task.tracks_processed / task.tracks_total * 100))}%` }"></span></div>
                        <small>{{ task.tracks_processed }}/{{ task.tracks_total }} источников обработано · Genius: {{ task.matched_tracks }} совпадений, {{ task.unmatched_tracks }} без матча</small>
                    </div>
                    <div v-if="task.muzofond_duration_ms !== null || task.genius_metrics?.requests || task.genius_metrics?.fallback_sampling" class="parsing-task__metrics">
                        <small v-if="task.muzofond_duration_ms !== null">Muzofond: {{ durationMsLabel(task.muzofond_duration_ms) }}</small>
                        <small v-if="task.genius_metrics?.requests">Genius: {{ task.genius_metrics.requests }} сетевых запросов<span v-if="task.genius_metrics.retries"> · {{ task.genius_metrics.retries }} повторов</span> · {{ durationMsLabel(task.genius_metrics.duration_ms) }}</small>
                        <small v-if="task.genius_metrics?.fallback_sampling">
                            Доп. поиск без кандидатов в каталоге: {{ task.genius_metrics.fallback_sampling.tracks_attempted }} попыток · {{ task.genius_metrics.fallback_sampling.tracks_matched }} найдено · {{ task.genius_metrics.fallback_sampling.tracks_skipped }} пропущено · {{ task.genius_metrics.fallback_sampling.consecutive_misses }}/{{ task.genius_metrics.fallback_sampling.miss_limit }} промахов подряд · {{ task.genius_metrics.fallback_sampling.network_failures }} сетевых ошибок (не считаются промахами)<span v-if="task.genius_metrics.fallback_sampling.stopped"> · остановлен по лимиту</span>
                        </small>
                        <small v-if="task.genius_metrics?.fallback_sampling?.third_query_skipped || task.genius_metrics?.fallback_sampling?.third_query_preserved_waiting">
                            Доп. поиск при неуверенном совпадении: третий запрос пропущен {{ task.genius_metrics.fallback_sampling.third_query_skipped }} раз<span v-if="task.genius_metrics.fallback_sampling.third_query_skip_reason"> (пул кандидатов не изменился после второго запроса)</span><span v-if="task.genius_metrics.fallback_sampling.third_query_preserved_waiting"> · ожидание подтверждения сохранено {{ task.genius_metrics.fallback_sampling.third_query_preserved_waiting }} раз</span>
                        </small>
                        <small v-for="entry in metricGroups(task.genius_metrics)" :key="entry.group">
                            {{ metricGroupLabel(entry.group) }}: {{ entry.metric.requests }} запросов<span v-if="entry.metric.retries"> · {{ entry.metric.retries }} повторов</span> · {{ durationMsLabel(entry.metric.duration_ms) }}<template v-if="entry.metric.errors"> · ошибки ({{ metricStatusesLabel(entry.metric.statuses) }})</template>
                        </small>
                    </div>
                    <p v-if="task.error" class="parsing-message parsing-message--error">{{ task.error }}</p>
                </div>
                <p v-if="run.total_tasks > run.tasks.length" class="parsing-muted">Показано {{ run.tasks.length }} заданий из {{ run.total_tasks }}.</p>
            </div>
        </article>
    </section>
</template>

<style scoped>
.parsing-page { display: grid; gap: 1rem; }
.parsing-forms { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
.parsing-form { display: grid; align-content: start; gap: 1rem; }
.parsing-form h2, .parsing-queue-summary h2 { margin: 0.3rem 0; }
.parsing-muted, .parsing-task small { color: var(--text-soft); }
.parsing-muted { margin: 0.4rem 0 0; }
.parsing-form__row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.75rem; }
.parsing-form .form-field { display: grid; gap: 0.4rem; }
.parsing-form .form-field input { width: 100%; }
.parsing-form .form-field small { color: var(--text-soft); }
.parsing-message { padding: 0.8rem 1rem; border-radius: 0.8rem; overflow-wrap: anywhere; }
.parsing-message--success { background: #e8f3ef; color: #175b45; }
.parsing-message--error, .parsing-warning { background: #fff0ed; color: #8f3528; }
.parsing-queue-summary { display: grid; grid-template-columns: minmax(180px, auto) 1fr; align-items: center; gap: 1rem; }
.parsing-queue-summary__stats { display: flex; flex-wrap: wrap; gap: 0.6rem 1rem; }
.parsing-queue-summary__stats strong { color: var(--text); }
.parsing-warning { grid-column: 1 / -1; margin: 0; padding: 0.7rem 0.9rem; border-radius: 0.7rem; }
.parsing-history-heading { margin-top: 0.8rem; }
.parsing-run { display: grid; gap: 1rem; }
.parsing-run__header { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; }
.parsing-run__header a { display: inline-block; margin-top: 0.5rem; overflow-wrap: anywhere; color: var(--text-soft); }
.parsing-run__title { display: flex; flex-wrap: wrap; align-items: center; gap: 0.6rem; }
.parsing-run__title h3 { margin: 0; }
.parsing-run__counts { display: grid; text-align: right; white-space: nowrap; }
.parsing-run__counts strong { font-size: 1.2rem; }
.parsing-run__counts small { color: var(--text-soft); }
.parsing-run__duration { font-weight: 600; }
.parsing-status { display: inline-flex; padding: 0.25rem 0.55rem; border-radius: 999px; background: #eef0ef; font-size: 0.78rem; font-weight: 700; }
.parsing-status--running { background: #e5f0ed; color: #17644a; }
.parsing-status--queued, .parsing-status--retry_wait { background: #fff3d9; color: #785600; }
.parsing-status--failed { background: #ffebe8; color: #a33628; }
.parsing-status--cancelled { background: #eef0ef; color: var(--text-soft); }
.parsing-status--completed { background: #e7f3ea; color: #23623b; }
.parsing-tasks { display: grid; gap: 0.7rem; }
.parsing-task { display: grid; gap: 0.5rem; padding: 0.85rem; border-radius: 0.8rem; background: var(--surface-soft, #f4f6f5); }
.parsing-task__main { display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem 0.7rem; }
.parsing-task__main strong { overflow-wrap: anywhere; }
.parsing-task__cancel { border: 1px solid var(--border, #dfe4e2); border-radius: 999px; padding: 0.35rem 0.7rem; background: transparent; color: var(--text-soft); cursor: pointer; }
.parsing-task__cancel:hover:not(:disabled) { border-color: #c94e42; color: #a33628; }
.parsing-task__cancel:disabled { opacity: 0.6; cursor: wait; }
.parsing-task__progress { display: grid; gap: 0.35rem; }
.parsing-task__progress small { color: var(--text-soft); }
.parsing-task__metrics { display: grid; gap: 0.2rem; padding-top: 0.35rem; border-top: 1px solid var(--border, #dfe4e2); }
.parsing-task__metrics small { color: var(--text-soft); overflow-wrap: anywhere; }
.parsing-progress { height: 0.45rem; overflow: hidden; border-radius: 999px; background: #e0e5e3; }
.parsing-progress span { display: block; height: 100%; border-radius: inherit; background: #378264; transition: width 0.25s ease; }
@media (max-width: 760px) {
    .parsing-forms, .parsing-queue-summary { grid-template-columns: minmax(0, 1fr); }
    .parsing-run__header { flex-direction: column; }
    .parsing-run__counts { text-align: left; }
    .parsing-form__row { grid-template-columns: minmax(0, 1fr); }
}
</style>
