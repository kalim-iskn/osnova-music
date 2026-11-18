<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { usePlayerStore } from '../stores/player';
import TrackArtists from './TrackArtists.vue';
import AppIcon from './AppIcon.vue';

const player = usePlayerStore();
const listRef = ref(null);
const dragIndex = ref(null);
const dropIndex = ref(null);
const pointerStartY = ref(0);
const pointerCurrentY = ref(0);
const isPointerDragging = ref(false);
const draggedItemHeight = ref(0);
const draggedRect = ref(null);
const itemRefs = new Map();
let pointerFrame = 0;
let pendingClientY = 0;
let initialRects = [];
let initialScrollTop = 0;
let dragScrollElement = null;
let dragDirection = 0;
const dropHysteresis = 10;

const queue = computed(() => player.queue);
const draggedTrack = computed(() => dragIndex.value === null ? null : queue.value[dragIndex.value] ?? null);
const dropDirection = computed(() => {
    if (dragIndex.value === null || dropIndex.value === null || dragIndex.value === dropIndex.value) {
        return null;
    }

    return dropIndex.value < dragIndex.value ? 'before' : 'after';
});

const getListElement = () => listRef.value?.$el ?? listRef.value;

const getScrollableParent = (element) => {
    let candidate = element;

    while (candidate && candidate !== document.body) {
        const overflowY = window.getComputedStyle(candidate).overflowY;
        const canScroll = /(auto|scroll)/.test(overflowY) && candidate.scrollHeight > candidate.clientHeight + 1;

        if (canScroll) {
            return candidate;
        }

        candidate = candidate.parentElement;
    }

    return document.scrollingElement;
};

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
    draggedItemHeight.value = 0;
    draggedRect.value = null;
    initialRects = [];
    dragScrollElement = null;
    dragDirection = 0;
    document.body.style.userSelect = '';
};

const scrollListNearEdge = (clientY) => {
    const scrollElement = dragScrollElement;

    if (!scrollElement) {
        return false;
    }

    const rect = scrollElement.getBoundingClientRect();
    const edgeSize = Math.min(220, Math.max(84, rect.height * 0.3));
    let delta = 0;

    if (dragDirection <= 0 && clientY < rect.top + edgeSize) {
        const proximity = Math.min(1, (rect.top + edgeSize - clientY) / edgeSize);
        delta = -Math.ceil(4 + proximity * 48);
    } else if (dragDirection >= 0 && clientY > rect.bottom - edgeSize) {
        const proximity = Math.min(1, (clientY - (rect.bottom - edgeSize)) / edgeSize);
        delta = Math.ceil(4 + proximity * 48);
    }

    if (delta === 0) {
        return false;
    }

    const previousScrollTop = scrollElement.scrollTop;
    scrollElement.scrollTop += delta;

    return scrollElement.scrollTop !== previousScrollTop;
};

const processPointerFrame = () => {
    pointerFrame = 0;

    if (!isPointerDragging.value || dragIndex.value === null) {
        return;
    }

    pointerCurrentY.value = pendingClientY;
    const didScroll = scrollListNearEdge(pendingClientY);
    dropIndex.value = resolveDropIndex(pendingClientY);

    // Keep scrolling while the pointer stays at an edge. Pointermove events
    // stop when the finger is held still, so a one-shot update cannot move a
    // track through a long queue.
    if (didScroll) {
        pointerFrame = requestAnimationFrame(processPointerFrame);
    }
};

const onPointerMove = (event) => {
    if (!isPointerDragging.value || dragIndex.value === null) {
        return;
    }

    event.preventDefault();
    const movement = event.clientY - pendingClientY;

    if (Math.abs(movement) >= 1) {
        dragDirection = Math.sign(movement);
    }

    pendingClientY = event.clientY;

    if (pointerFrame) {
        return;
    }

    pointerFrame = requestAnimationFrame(processPointerFrame);
};

const onPointerUp = (event) => {
    if (pointerFrame) {
        cancelAnimationFrame(pointerFrame);
        pointerFrame = 0;
    }

    if (dragIndex.value !== null && Number.isFinite(event?.clientY)) {
        pointerCurrentY.value = event.clientY;
        const scrollRect = dragScrollElement?.getBoundingClientRect();
        const atTopEdge = scrollRect && event.clientY <= scrollRect.top + 36;
        const atBottomEdge = scrollRect && event.clientY >= scrollRect.bottom - 36;
        let finalIndex = resolveDropIndex(event.clientY);

        // A deliberate release at the extreme edge is a quick "move to end"
        // gesture. It avoids making the user wait through the whole scroll for
        // a long queue while regular near-edge dragging still autoscrolls.
        if (atTopEdge && (dragScrollElement?.scrollTop ?? 0) > 0) {
            finalIndex = 0;
        } else if (atBottomEdge && dragScrollElement
            && dragScrollElement.scrollTop < dragScrollElement.scrollHeight - dragScrollElement.clientHeight - 1) {
            finalIndex = queue.value.length - 1;
        }

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

const resolveDropIndex = (clientY, currentIndex = dropIndex.value) => {
    if (initialRects.length === 0 || dragIndex.value === null) {
        return dragIndex.value;
    }

    const scrollDelta = (dragScrollElement?.scrollTop ?? 0) - initialScrollTop;
    const draggedRect = initialRects.find((entry) => entry.index === dragIndex.value)?.rect;

    if (!draggedRect) {
        return dragIndex.value;
    }

    // The floating preview remains under the pointer while the content scrolls,
    // therefore only the queue rows move by scrollDelta.
    const draggedCenter = draggedRect.top + draggedRect.height / 2 + (clientY - pointerStartY.value);
    const remainingRects = initialRects.filter((entry) => entry.index !== dragIndex.value);
    let resolvedIndex = Math.max(0, Math.min(currentIndex ?? dragIndex.value, remainingRects.length));

    // Keep the current slot until the dragged card has clearly crossed the
    // neighbouring card's centre. The small dead zone prevents two adjacent
    // rows from repeatedly swapping when the pointer sits on the boundary.
    while (resolvedIndex < remainingRects.length) {
        const rect = remainingRects[resolvedIndex].rect;
        const boundary = rect.top + rect.height / 2 - scrollDelta;

        if (draggedCenter <= boundary + dropHysteresis) {
            break;
        }

        resolvedIndex += 1;
    }

    while (resolvedIndex > 0) {
        const rect = remainingRects[resolvedIndex - 1].rect;
        const boundary = rect.top + rect.height / 2 - scrollDelta;

        if (draggedCenter >= boundary - dropHysteresis) {
            break;
        }

        resolvedIndex -= 1;
    }

    return resolvedIndex;
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
    initialRects = Array.from(itemRefs.entries())
        .sort(([leftIndex], [rightIndex]) => leftIndex - rightIndex)
        .map(([itemIndex, element]) => ({ index: itemIndex, rect: element.getBoundingClientRect() }));
    draggedItemHeight.value = initialRects.find((entry) => entry.index === index)?.rect.height ?? 0;
    const initialDraggedRect = initialRects.find((entry) => entry.index === index)?.rect;
    draggedRect.value = initialDraggedRect ? {
        top: initialDraggedRect.top,
        left: initialDraggedRect.left,
        width: initialDraggedRect.width,
        height: initialDraggedRect.height,
    } : null;
    dragScrollElement = getScrollableParent(getListElement());
    initialScrollTop = dragScrollElement?.scrollTop ?? 0;
    pendingClientY = event.clientY;
    document.body.style.userSelect = 'none';

    event.currentTarget?.setPointerCapture?.(event.pointerId);

    window.addEventListener('pointermove', onPointerMove, { passive: false });
    window.addEventListener('pointerup', onPointerUp);
    window.addEventListener('pointercancel', onPointerCancel);
};

const itemStyle = (index) => {
    if (!isPointerDragging.value || dragIndex.value === null || dropIndex.value === null) {
        return null;
    }

    if (dragIndex.value === index) {
        return { opacity: 0 };
    }

    const gap = 13;
    const slot = draggedItemHeight.value + gap;

    if (dropIndex.value > dragIndex.value && index > dragIndex.value && index <= dropIndex.value) {
        return { transform: `translate3d(0, ${-slot}px, 0)` };
    }

    if (dropIndex.value < dragIndex.value && index >= dropIndex.value && index < dragIndex.value) {
        return { transform: `translate3d(0, ${slot}px, 0)` };
    }

    return null;
};

const previewStyle = computed(() => {
    if (!isPointerDragging.value || !draggedRect.value) {
        return null;
    }

    return {
        top: `${draggedRect.value.top}px`,
        left: `${draggedRect.value.left}px`,
        width: `${draggedRect.value.width}px`,
        height: `${draggedRect.value.height}px`,
        transform: `translate3d(0, ${pointerCurrentY.value - pointerStartY.value}px, 0) scale(1.018)`,
    };
});

const markerClass = (index) => ({
    'queue-item--drop-before': isPointerDragging.value && dropDirection.value === 'before' && dropIndex.value === index,
    'queue-item--drop-after': isPointerDragging.value && dropDirection.value === 'after' && dropIndex.value === index,
});

const moveByKeyboard = (index, direction) => {
    const nextIndex = index + direction;

    if (nextIndex < 0 || nextIndex >= queue.value.length) {
        return;
    }

    player.moveQueueItem(index, nextIndex);
};

onBeforeUnmount(() => {
    detachPointerListeners();
    resetDragState();
});
</script>

<template>
    <TransitionGroup
        ref="listRef"
        name="queue-reorder"
        tag="div"
        class="queue-drawer__list"
        :class="{ 'queue-drawer__list--dragging': isPointerDragging }"
    >
        <div
            v-for="(track, index) in queue"
            :key="track.id"
            :ref="(element) => setItemRef(element, index)"
            class="queue-item"
            :class="[
                {
                    'queue-item--active': player.currentIndex === index,
                    'queue-item--dragging': dragIndex === index,
                },
                markerClass(index),
            ]"
            :style="itemStyle(index)"
        >
            <button
                type="button"
                class="queue-item__handle"
                aria-label="Переместить"
                title="Переместить"
                @pointerdown="startPointerDrag(index, $event)"
                @keydown.up.prevent="moveByKeyboard(index, -1)"
                @keydown.down.prevent="moveByKeyboard(index, 1)"
            >
                <AppIcon name="grip" :size="21" :stroke-width="3" />
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
                <AppIcon name="close" :size="18" />
            </button>
        </div>
    </TransitionGroup>

    <Teleport to="body">
        <div
            v-if="isPointerDragging && draggedTrack && previewStyle"
            class="queue-item queue-item--drag-preview"
            :class="{ 'queue-item--active': player.currentTrack?.id === draggedTrack.id }"
            :style="previewStyle"
        >
            <span class="queue-item__handle queue-item__handle--preview">
                <AppIcon name="grip" :size="21" :stroke-width="3" />
            </span>

            <span class="queue-item__select queue-item__select--preview">
                <img :src="draggedTrack.cover_image_url" :alt="draggedTrack.title" class="queue-item__cover">

                <span class="queue-item__meta">
                    <strong>{{ draggedTrack.title }}</strong>
                    <small><TrackArtists :track="draggedTrack" :links="false" /></small>
                </span>

                <span class="queue-item__duration">{{ draggedTrack.duration_human }}</span>
            </span>

            <span class="queue-item__remove queue-item__remove--preview">
                <AppIcon name="close" :size="18" />
            </span>
        </div>
    </Teleport>
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
    padding: 4px 2px 10px;
    overscroll-behavior: contain;
    scroll-padding-block: 72px;
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

.queue-drawer__list--dragging .queue-item:not(.queue-item--dragging) {
    transition: transform 0.15s cubic-bezier(0.2, 0.75, 0.25, 1), box-shadow 0.16s ease, border-color 0.16s ease, background-color 0.16s ease;
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
    cursor: grabbing;
    transition: opacity 90ms ease;
}

.queue-item--drag-preview {
    position: fixed;
    z-index: 120;
    margin: 0;
    box-shadow: 0 16px 34px rgba(28, 39, 36, 0.22);
    pointer-events: none;
    transform-origin: center;
    will-change: transform;
}

.queue-item__handle--preview,
.queue-item__remove--preview {
    pointer-events: none;
}

.queue-item__select--preview {
    cursor: grabbing;
}

.queue-item--drop-before::before,
.queue-item--drop-after::after {
    content: '';
    position: absolute;
    z-index: 2;
    inset-inline: 10px;
    height: 3px;
    border-radius: 999px;
    background: #657270;
    box-shadow: 0 0 0 3px rgba(101, 114, 112, 0.1);
    pointer-events: none;
}

.queue-item--drop-before::before {
    top: -8px;
}

.queue-item--drop-after::after {
    bottom: -8px;
}

.queue-item__handle {
    display: inline-flex;
    width: 42px;
    min-width: 42px;
    height: 52px;
    justify-content: center;
    align-items: center;
    cursor: grab;
    padding: 0;
    background: transparent;
    border: 0;
    border-radius: 12px;
    color: rgba(41, 50, 53, 0.42);
    touch-action: none;
}

.queue-item__handle:hover,
.queue-item__handle:focus-visible {
    color: #303b3d;
    background: #edf0ef;
    outline: none;
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
    display: inline-grid;
    place-items: center;
}

@media (max-width: 720px) {
    .queue-item {
        gap: 0.45rem;
        padding: 0.6rem;
    }

    .queue-item__handle {
        width: 44px;
        min-width: 44px;
        height: 56px;
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
