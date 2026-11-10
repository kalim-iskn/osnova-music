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
let pointerFrame = 0;
let pendingClientY = 0;

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
    pendingClientY = event.clientY;

    if (pointerFrame) {
        return;
    }

    pointerFrame = requestAnimationFrame(() => {
        pointerFrame = 0;
        pointerCurrentY.value = pendingClientY;
        dropIndex.value = resolveDropIndex(pendingClientY);
    });
};

const onPointerUp = (event) => {
    if (pointerFrame) {
        cancelAnimationFrame(pointerFrame);
        pointerFrame = 0;
    }

    if (dragIndex.value !== null && Number.isFinite(event?.clientY)) {
        pointerCurrentY.value = event.clientY;
        const finalIndex = resolveDropIndex(event.clientY);

        if (finalIndex !== dragIndex.value) {
            player.moveQueueItem(dragIndex.value, finalIndex);
        }
    }

    detachPointerListeners();
    resetDragState();
};

const onPointerCancel = () => {
    if (pointerFrame) {
        cancelAnimationFrame(pointerFrame);
        pointerFrame = 0;
    }

    detachPointerListeners();
    resetDragState();
};

const detachPointerListeners = () => {
    if (pointerFrame) {
        cancelAnimationFrame(pointerFrame);
        pointerFrame = 0;
    }

    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);
    window.removeEventListener('pointercancel', onPointerCancel);
};

const resolveDropIndex = (clientY) => {
    const entries = Array.from(itemRefs.entries()).sort(([leftIndex], [rightIndex]) => leftIndex - rightIndex);

    if (entries.length === 0) {
        return dragIndex.value;
    }

    let finalIndex = 0;

    for (const [index, element] of entries) {
        if (index === dragIndex.value) {
            continue;
        }

        const rect = element.getBoundingClientRect();
        const midpoint = rect.top + rect.height / 2;
        const indexWithoutDraggedItem = index > dragIndex.value ? index - 1 : index;

        if (clientY < midpoint) {
            return indexWithoutDraggedItem;
        }

        finalIndex = indexWithoutDraggedItem + 1;
    }

    return Math.min(finalIndex, queue.value.length - 1);
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
    window.addEventListener('pointercancel', onPointerCancel);
};

const itemStyle = (index) => {
    if (!isPointerDragging.value || dragIndex.value !== index) {
        return null;
    }

    return {
        transform: `translate3d(0, ${pointerCurrentY.value - pointerStartY.value}px, 0)`,
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
            :key="track.id"
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
    position: relative;
    display: grid;
    align-content: start;
    gap: 0.8rem;
    flex: 1 1 auto;
    min-height: 0;
    overflow: auto;
}

.queue-reorder-move,
.queue-reorder-enter-active,
.queue-reorder-leave-active {
    transition: transform 0.18s cubic-bezier(0.2, 0.75, 0.25, 1), opacity 0.16s ease;
}

.queue-reorder-enter-from,
.queue-reorder-leave-to {
    opacity: 0;
    transform: translateY(10px) scale(0.98);
}

.queue-reorder-leave-active {
    position: absolute;
    inset-inline: 0;
}

.queue-item {
    position: relative;
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: center;
    gap: 0.8rem;
    padding: 0.75rem;
    border-radius: 18px;
    color: #293235;
    background: #f8f9f8;
    border: 1px solid #e3e8e6;
    transition: box-shadow 0.16s ease, border-color 0.16s ease, background-color 0.16s ease;
}

.queue-item--active {
    border-color: #cbd4d0;
    background: #edf1ef;
    box-shadow: inset 3px 0 0 #394346;
}

.queue-item--active .queue-item__meta strong {
    color: #253033;
}

.queue-item--dragging {
    box-shadow: 0 12px 28px rgba(28, 39, 36, 0.16);
    cursor: grabbing;
    transition: none;
    will-change: transform;
}

.queue-item--drop-target::before {
    content: '';
    position: absolute;
    z-index: 2;
    inset-inline: 10px;
    top: -7px;
    height: 3px;
    border-radius: 999px;
    background: #657270;
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
    background: rgba(41, 50, 53, 0.42);
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
    color: var(--text-soft);
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
