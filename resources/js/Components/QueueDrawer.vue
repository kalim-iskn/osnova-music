<script setup>
import { computed, ref } from 'vue';
import { formatCount } from '../utils/pluralize';
import { usePlayerStore } from '../stores/player';
import TrackArtists from './TrackArtists.vue';
import TrackInfoMenu from './TrackInfoMenu.vue';

const player = usePlayerStore();
const dragIndex = ref(null);
const overIndex = ref(null);

const queueCountLabel = computed(() => formatCount(player.queue.length, ['трек', 'трека', 'треков']));

const onDragStart = (index) => {
    dragIndex.value = index;
};

const onDragEnter = (index) => {
    overIndex.value = index;
};

const resetDragState = () => {
    dragIndex.value = null;
    overIndex.value = null;
};

const onDrop = (index) => {
    if (dragIndex.value === null) {
        return;
    }

    player.moveQueueItem(dragIndex.value, index);
    resetDragState();
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
                        <p>{{ queueCountLabel }}</p>
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
                            'queue-item--over': overIndex === index,
                        }"
                        draggable="true"
                        @dragstart="onDragStart(index)"
                        @dragenter.prevent="onDragEnter(index)"
                        @dragover.prevent
                        @dragend="resetDragState"
                        @drop="onDrop(index)"
                    >
                        <button type="button" class="queue-item__handle" aria-label="Переместить" title="Перетащить в очереди">
                            <span class="queue-item__grip" aria-hidden="true"></span>
                        </button>

                        <button type="button" class="queue-item__select" @click="player.playQueueItem(index)">
                            <img :src="track.cover_image_url" :alt="track.title" class="queue-item__cover">

                            <span class="queue-item__meta">
                                <strong>{{ track.title }}</strong>
                                <small><TrackArtists :track="track" /></small>
                            </span>

                            <span class="queue-item__duration">{{ track.duration_human }}</span>
                        </button>

                        <div class="queue-item__actions">
                            <TrackInfoMenu :track="track" compact />

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
                </div>

                <div v-else class="empty-state empty-state--large">
                    <p>Очередь пока пуста. Добавьте треки и продолжайте слушать в удобном порядке.</p>
                </div>
            </div>
        </aside>
    </transition>
</template>

<style scoped>
.queue-drawer__list {
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    align-items: stretch;
    align-content: flex-start;
    gap: 0.75rem;
}

.queue-item {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: center;
    gap: 0.85rem;
    overflow: visible;
    border-radius: 16px;
    transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
}

.queue-item--active {
    outline: 1px solid rgba(255, 255, 255, 0.14);
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.06);
}

.queue-item--dragging {
    opacity: 0.78;
    transform: scale(0.985);
}

.queue-item--over {
    background: rgba(255, 255, 255, 0.035);
}

.queue-item__handle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.25rem;
    height: 2.25rem;
    border: none;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.04);
    cursor: grab;
}

.queue-item__grip {
    width: 0.95rem;
    height: 0.95rem;
    background-image: radial-gradient(circle, currentColor 1.2px, transparent 1.3px);
    background-size: 6px 6px;
    background-position: 0 0;
    opacity: 0.72;
}

.queue-item__select {
    min-width: 0;
}

.queue-item__meta,
.queue-item__meta strong,
.queue-item__meta small {
    min-width: 0;
}

.queue-item__meta small {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.queue-item__actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

@media (max-width: 720px) {
    .queue-item {
        grid-template-columns: auto minmax(0, 1fr);
    }

    .queue-item__actions {
        grid-column: 2;
        justify-content: flex-end;
    }
}
</style>
