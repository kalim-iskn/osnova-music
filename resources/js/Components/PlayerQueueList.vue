<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { usePlayerStore } from '../stores/player';
import TrackArtists from './TrackArtists.vue';

const player = usePlayerStore();
const listRef = ref(null);
const dragIndex = ref(null);
const dropIndex = ref(null);
const pointerStartY = ref(0);
const pointerCurrentY = ref(0);
const isPointerDragging = ref(false);
const itemRefs = new Map();

const queue = computed(() => player.queue);

const setItemRef = (element, index) => {
    if (element) {
        itemRefs.set(index, element);
        return;
    }

    itemRefs.delete(index);
};

const resetDragState = () => {
    dragIndex.value = null;
    dropIndex.value = null;
    pointerStartY.value = 0;
    pointerCurrentY.value = 0;
    isPointerDragging.value = false;
    document.body.style.userSelect = '';
};

const onPointerMove = (event) => {
    if (!isPointerDragging.value || dragIndex.value === null) {
        return;
    }

    event.preventDefault();
    pointerCurrentY.value = event.clientY;
    dropIndex.value = resolveDropIndex(event.clientY);
};

const onPointerUp = () => {
    if (dragIndex.value !== null && dropIndex.value !== null && dragIndex.value !== dropIndex.value) {
        player.moveQueueItem(dragIndex.value, dropIndex.value);
    }

    detachPointerListeners();
    resetDragState();
};

const detachPointerListeners = () => {
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);
    window.removeEventListener('pointercancel', onPointerUp);
};

const resolveDropIndex = (clientY) => {
    const entries = Array.from(itemRefs.entries()).sort(([leftIndex], [rightIndex]) => leftIndex - rightIndex);

    if (entries.length === 0) {
        return dragIndex.value;
    }

    let fallbackIndex = entries[entries.length - 1][0];

    for (const [index, element] of entries) {
        const rect = element.getBoundingClientRect();
        const midpoint = rect.top + rect.height / 2;

        if (clientY < midpoint) {
            return index;
        }

        fallbackIndex = index;
    }

    return fallbackIndex;
};

const startPointerDrag = (index, event) => {
    if (queue.value.length < 2) {
        return;
    }

    if (event.pointerType === 'mouse' && event.button !== 0) {
        return;
    }

    event.preventDefault();

    dragIndex.value = index;
    dropIndex.value = index;
    pointerStartY.value = event.clientY;
    pointerCurrentY.value = event.clientY;
    isPointerDragging.value = true;
    document.body.style.userSelect = 'none';

    window.addEventListener('pointermove', onPointerMove, { passive: false });
    window.addEventListener('pointerup', onPointerUp);
    window.addEventListener('pointercancel', onPointerUp);
};

const itemStyle = (index) => {
    if (!isPointerDragging.value || dragIndex.value !== index) {
        return null;
    }

    return {
        transform: `translateY(${pointerCurrentY.value - pointerStartY.value}px) scale(0.985)`,
        zIndex: 3,
    };
};

onBeforeUnmount(() => {
    detachPointerListeners();
    resetDragState();
});
</script>

<template>
    <TransitionGroup ref="listRef" name="queue-reorder" tag="div" class="queue-drawer__list">
        <div
            v-for="(track, index) in queue"
            :key="`${track.id}-${index}`"
            :ref="(element) => setItemRef(element, index)"
            class="queue-item"
            :class="{
                'queue-item--active': player.currentIndex === index,
                'queue-item--dragging': dragIndex === index,
                'queue-item--drop-target': dropIndex === index && dragIndex !== index,
            }"
            :style="itemStyle(index)"
        >
            <button
                type="button"
                class="queue-item__handle"
                aria-label="Переместить"
                title="Переместить"
                @pointerdown="startPointerDrag(index, $event)"
            >
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
    </TransitionGroup>
</template>

<style scoped>
.queue-drawer__list {
    display: grid;
    align-content: start;
    gap: 0.8rem;
}

.queue-reorder-move,
.queue-reorder-enter-active,
.queue-reorder-leave-active {
    transition: transform 0.24s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.2s ease;
}

.queue-reorder-enter-from,
.queue-reorder-leave-to {
    opacity: 0;
    transform: translateY(10px) scale(0.98);
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
    transition: transform 0.18s cubic-bezier(0.2, 0.8, 0.2, 1), box-shadow 0.18s ease, border-color 0.18s ease, background-color 0.18s ease;
    will-change: transform;
}

.queue-item--active {
    border-color: rgba(255, 255, 255, 0.14);
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.06);
}

.queue-item--dragging {
    box-shadow: 0 18px 36px rgba(0, 0, 0, 0.28);
    cursor: grabbing;
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
    touch-action: none;
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
    cursor: pointer;
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

.queue-item__remove {
    position: relative;
    z-index: 1;
    cursor: pointer;
}

@media (max-width: 720px) {
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
