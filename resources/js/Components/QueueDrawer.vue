<script setup>
import { ref } from 'vue';
import { formatCount } from '../utils/pluralize';
import { usePlayerStore } from '../stores/player';
import TrackArtists from './TrackArtists.vue';

const player = usePlayerStore();
const dragIndex = ref(null);
const dragOverIndex = ref(null);

const onDragStart = (index) => {
    dragIndex.value = index;
};

const onDragEnter = (index) => {
    if (dragIndex.value === null || dragIndex.value === index) {
        return;
    }

    dragOverIndex.value = index;
};

const onDrop = (index) => {
    if (dragIndex.value === null) {
        return;
    }

    player.moveQueueItem(dragIndex.value, index);
    dragIndex.value = null;
    dragOverIndex.value = null;
};

const onDragEnd = () => {
    dragIndex.value = null;
    dragOverIndex.value = null;
};
</script>

<template>
    <transition name="fade">
        <aside v-if="player.isQueueOpen" class="queue-drawer">
            <div class="queue-drawer__overlay" @click="player.toggleQueue()"></div>

            <div class="queue-drawer__panel">
                <div class="queue-drawer__header">
                    <div class="queue-drawer__heading">
                        <h3>Очередь воспроизведения</h3>
                        <p>{{ formatCount(player.queue.length, ['трек', 'трека', 'треков']) }}</p>
                    </div>

                    <div class="queue-drawer__header-actions">
                        <button class="ghost-button ghost-button--small" type="button" @click="player.clearQueue()">
                            Очистить
                        </button>
                        <button class="ghost-button ghost-button--small" type="button" @click="player.toggleQueue()">
                            Закрыть
                        </button>
                    </div>
                </div>

                <div v-if="player.queue.length" class="queue-drawer__list">
                    <div
                        v-for="(track, index) in player.queue"
                        :key="`${track.id}-${index}`"
                        class="queue-item"
                        :class="{
                            'queue-item--active': player.currentIndex === index,
                            'queue-item--dragging': dragIndex === index,
                            'queue-item--drop-target': dragOverIndex === index,
                        }"
                        draggable="true"
                        @dragstart="onDragStart(index)"
                        @dragenter.prevent="onDragEnter(index)"
                        @dragover.prevent
                        @dragend="onDragEnd"
                        @drop="onDrop(index)"
                    >
                        <button type="button" class="queue-item__handle" aria-label="Переместить" title="Переместить">
                            <span></span><span></span><span></span><span></span><span></span><span></span>
                        </button>

                        <button type="button" class="queue-item__select" @click="player.playQueueItem(index)">
                            <img :src="track.cover_image_url" :alt="track.title" class="queue-item__cover">

                            <span class="queue-item__meta">
                                <strong>{{ track.title }}</strong>
                                <small><TrackArtists :track="track" /></small>
                            </span>

                            <span class="queue-item__duration">{{ track.duration_human }}</span>
                        </button>

                        <button
                            type="button"
                            class="queue-item__remove"
                            aria-label="Убрать из очереди"
                            @click="player.removeFromQueue(index)"
                        >
                            ✕
                        </button>
                    </div>
                </div>

                <div v-else class="empty-state empty-state--large">
                    <p>Очередь пока пуста. Добавьте треки и продолжайте слушать в удобном порядке.</p>
                </div>
            </div>
        </aside>
    </transition>
</template>

<style scoped>
.queue-drawer__panel {
    display: flex;
    flex-direction: column;
}

.queue-drawer__list {
    display: grid;
    align-content: start;
    gap: 0.8rem;
}

.queue-item {
    position: relative;
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: center;
    gap: 0.8rem;
    padding: 0.75rem;
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.05);
    transition: transform 0.18s cubic-bezier(.2,.8,.2,1), box-shadow 0.18s ease, border-color 0.18s ease, background-color 0.18s ease;
}

.queue-item--active {
    border-color: rgba(255, 255, 255, 0.14);
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.06);
}

.queue-item--dragging {
    opacity: 0.84;
    transform: scale(0.985);
    box-shadow: 0 18px 36px rgba(0, 0, 0, 0.28);
}

.queue-item--drop-target::before {
    content: '';
    position: absolute;
    inset: -4px;
    border-radius: 22px;
    border: 1px dashed rgba(255, 255, 255, 0.2);
    pointer-events: none;
}

.queue-item__handle {
    display: grid;
    grid-template-columns: repeat(2, 4px);
    gap: 4px;
    width: 1.5rem;
    justify-content: center;
    align-content: center;
    cursor: grab;
    padding: 0;
    background: transparent;
    border: 0;
}

.queue-item__handle span {
    width: 4px;
    height: 4px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.45);
}

.queue-item__select {
    display: grid;
    grid-template-columns: 56px minmax(0, 1fr) auto;
    align-items: center;
    gap: 0.8rem;
    min-width: 0;
    padding: 0;
    background: transparent;
    border: 0;
    text-align: left;
}

.queue-item__cover {
    width: 56px;
    height: 56px;
    object-fit: cover;
    border-radius: 14px;
}

.queue-item__meta,
.queue-item__meta strong,
.queue-item__meta small {
    min-width: 0;
}

.queue-item__meta strong,
.queue-item__meta small {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.queue-item__meta small {
    margin-top: 0.35rem;
}

.queue-item__duration {
    color: rgba(255, 255, 255, 0.62);
    white-space: nowrap;
}

@media (max-width: 720px) {
    .queue-item {
        grid-template-columns: auto minmax(0, 1fr);
    }

    .queue-item__select {
        grid-template-columns: 48px minmax(0, 1fr);
    }

    .queue-item__cover {
        width: 48px;
        height: 48px;
    }

    .queue-item__duration {
        display: none;
    }
}
</style>
