<script setup>
import { ref } from 'vue';
import { formatCount } from '../utils/pluralize';
import { usePlayerStore } from '../stores/player';
import TrackArtists from './TrackArtists.vue';

const player = usePlayerStore();
const dragIndex = ref(null);

const onDragStart = (index) => {
    dragIndex.value = index;
};

const onDrop = (index) => {
    if (dragIndex.value === null) {
        return;
    }

    player.moveQueueItem(dragIndex.value, index);
    dragIndex.value = null;
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
                        :class="{ 'queue-item--active': player.currentIndex === index }"
                        draggable="true"
                        @dragstart="onDragStart(index)"
                        @dragover.prevent
                        @drop="onDrop(index)"
                    >
                        <button type="button" class="queue-item__handle" aria-label="Переместить">⋮⋮</button>

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
.queue-item {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: center;
    gap: 10px;
    overflow: visible;
    border-radius: 16px;
}

.queue-item--active {
    outline: 1px solid rgba(255, 255, 255, 0.14);
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.06);
}

.queue-item__handle {
    cursor: grab;
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

@media (max-width: 720px) {
    .queue-item {
        grid-template-columns: minmax(0, 1fr) auto;
    }

    .queue-item__handle {
        display: none;
    }
}
</style>
