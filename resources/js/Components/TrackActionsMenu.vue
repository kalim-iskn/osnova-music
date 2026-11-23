<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { usePlayerStore } from '../stores/player';
import AppIcon from './AppIcon.vue';
import MarqueeText from './MarqueeText.vue';
import TrackArtists from './TrackArtists.vue';

const props = defineProps({
    track: {
        type: Object,
        required: true,
    },
});

const player = usePlayerStore();
const trigger = ref(null);
const panel = ref(null);
const isOpen = ref(false);
const isChoosingPosition = ref(false);
const panelPosition = ref({ top: 8, left: 8 });

const queueTargets = computed(() => {
    return player.queue
        .map((track, index) => ({ track, index }))
});

const panelStyle = computed(() => ({
    top: `${panelPosition.value.top}px`,
    left: `${panelPosition.value.left}px`,
}));

const updatePosition = async () => {
    await nextTick();

    const anchorRect = trigger.value?.getBoundingClientRect();
    const panelRect = panel.value?.getBoundingClientRect();

    if (!anchorRect || !panelRect) {
        return;
    }

    const padding = 10;
    const viewportWidth = document.documentElement.clientWidth;
    const viewportHeight = window.innerHeight;
    const left = Math.max(padding, Math.min(anchorRect.right - panelRect.width, viewportWidth - panelRect.width - padding));
    let top = anchorRect.bottom + 8;

    if (top + panelRect.height > viewportHeight - padding) {
        top = anchorRect.top - panelRect.height - 8;
    }

    panelPosition.value = {
        left,
        top: Math.max(padding, Math.min(top, viewportHeight - panelRect.height - padding)),
    };
};

const closeMenu = (restoreFocus = false) => {
    if (!isOpen.value) {
        return;
    }

    isOpen.value = false;
    isChoosingPosition.value = false;
    document.removeEventListener('pointerdown', handleOutsidePointerDown, true);
    document.removeEventListener('keydown', handleDocumentKeydown);
    window.removeEventListener('resize', handleViewportChange);
    window.removeEventListener('scroll', handleViewportChange, true);

    if (restoreFocus) {
        nextTick(() => trigger.value?.focus());
    }
};

const handleOutsidePointerDown = (event) => {
    if (trigger.value?.contains(event.target) || panel.value?.contains(event.target)) {
        return;
    }

    closeMenu();
};

const handleDocumentKeydown = (event) => {
    if (event.key === 'Escape') {
        event.preventDefault();
        closeMenu(true);
    }
};

const handleViewportChange = (event) => {
    // Captured scroll events include the picker itself; keep it open while choosing.
    if (event.type === 'scroll' && event.target !== window && event.target !== document && panel.value?.contains(event.target)) return;
    closeMenu();
};

const openMenu = async () => {
    isOpen.value = true;
    isChoosingPosition.value = false;
    await updatePosition();
    document.addEventListener('pointerdown', handleOutsidePointerDown, true);
    document.addEventListener('keydown', handleDocumentKeydown);
    window.addEventListener('resize', handleViewportChange);
    window.addEventListener('scroll', handleViewportChange, true);
};

const toggleMenu = () => {
    if (isOpen.value) {
        closeMenu();
        return;
    }

    openMenu();
};

const toggleQueuePosition = async () => {
    isChoosingPosition.value = !isChoosingPosition.value;
    await updatePosition();
};

const addNext = () => {
    player.queueTrackNext(props.track);
    closeMenu();
};

const addToEnd = () => {
    player.queueTrackEnd(props.track);
    closeMenu();
};

const addAfter = (index) => {
    player.queueTrackAfter(props.track, index);
    closeMenu();
};

onBeforeUnmount(() => closeMenu());
</script>

<template>
    <button
        ref="trigger"
        class="icon-button track-actions-menu__trigger"
        type="button"
        :disabled="track.is_playable === false"
        :aria-label="`Действия с треком: ${track.title}`"
        aria-haspopup="menu"
        :aria-expanded="isOpen"
        title="Действия с треком"
        @click.stop="toggleMenu"
    >
        <AppIcon name="moreVertical" :size="20" />
    </button>

    <Teleport to="body">
        <Transition name="track-actions-menu">
            <div
                v-if="isOpen"
                ref="panel"
                class="track-actions-menu__panel"
                :style="panelStyle"
                role="menu"
                :aria-label="`Действия с треком ${track.title}`"
                @pointerdown.stop
            >
                <button class="track-actions-menu__item" type="button" role="menuitem" @click="addNext">
                    <AppIcon name="next" :size="18" />
                    <span>Играть следующим</span>
                </button>
                <button class="track-actions-menu__item" type="button" role="menuitem" @click="addToEnd">
                    <AppIcon name="queue" :size="18" />
                    <span>В конец очереди</span>
                </button>

                <button
                    class="track-actions-menu__item"
                    type="button"
                    role="menuitem"
                    :aria-expanded="isChoosingPosition"
                    @click="toggleQueuePosition"
                >
                    <AppIcon name="chevronRight" :size="18" />
                    <span>Добавить после трека…</span>
                </button>

                <div v-if="isChoosingPosition" class="track-actions-menu__queue-picker" role="group" aria-label="Выберите трек в очереди">
                    <div class="track-actions-menu__queue-heading">
                        <span>Треки в очереди</span>
                        <span>{{ player.queue.length }}</span>
                    </div>
                    <p v-if="queueTargets.length === 0" class="track-actions-menu__empty">Очередь пока пуста</p>
                    <button
                        v-for="item in queueTargets"
                        :key="`${item.track.id}-${item.index}`"
                        class="track-actions-menu__queue-item"
                        type="button"
                        :disabled="item.track.id === track.id"
                        :aria-label="item.track.id === track.id ? `${item.track.title}: нельзя добавить трек после самого себя` : `Добавить ${track.title} после ${item.track.title}`"
                        @click="addAfter(item.index)"
                    >
                        <span class="track-actions-menu__queue-meta">
                            <MarqueeText class="track-actions-menu__queue-title">{{ item.track.title }}</MarqueeText>
                            <MarqueeText class="track-actions-menu__queue-artist"><TrackArtists :track="item.track" :links="false" /></MarqueeText>
                        </span>
                        <AppIcon v-if="item.index === player.currentIndex" name="play" :size="14" class="track-actions-menu__current" />
                    </button>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.track-actions-menu__trigger {
    width: 40px;
    min-width: 40px;
    height: 40px;
    min-height: 40px;
    padding: 0;
    flex: 0 0 40px;
}

.track-actions-menu__trigger:focus-visible,
.track-actions-menu__item:focus-visible,
.track-actions-menu__queue-item:focus-visible {
    outline: 2px solid var(--primary, #293235);
    outline-offset: 2px;
}

.track-actions-menu__panel {
    position: fixed;
    z-index: 150;
    display: grid;
    width: min(280px, calc(100vw - 20px));
    max-height: calc(100vh - 20px);
    max-height: calc(100dvh - 20px);
    gap: 3px;
    padding: 8px;
    overflow: auto;
    border: 1px solid var(--border, #e0e5e3);
    border-radius: 16px;
    color: var(--text, #293235);
    background: var(--surface, #fff);
    box-shadow: 0 14px 40px rgb(20 30 32 / 20%);
    overscroll-behavior: contain;
}

.track-actions-menu__item,
.track-actions-menu__queue-item {
    display: flex;
    width: 100%;
    min-width: 0;
    align-items: center;
    gap: 10px;
    padding: 10px 11px;
    border: 0;
    border-radius: 10px;
    color: inherit;
    background: transparent;
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.track-actions-menu__item:hover,
.track-actions-menu__queue-item:hover {
    background: #f0f3f2;
}

.track-actions-menu__item > span {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.track-actions-menu__item:disabled {
    opacity: 0.48;
    cursor: not-allowed;
}

.track-actions-menu__queue-picker {
    display: grid;
    gap: 2px;
    margin-top: 2px;
    padding: 5px 0 0 8px;
    border-top: 1px solid var(--border, #e0e5e3);
}

.track-actions-menu__queue-heading {
    display: flex;
    justify-content: space-between;
    padding: 7px 8px 5px;
    color: var(--text-soft, #687476);
    background: var(--card, #fff);
    font-size: 0.78rem;
    font-weight: 650;
}

.track-actions-menu__queue-item {
    gap: 8px;
    padding: 8px;
}

.track-actions-menu__queue-item:disabled {
    opacity: 0.52;
    cursor: not-allowed;
}

.track-actions-menu__queue-meta {
    display: grid;
    min-width: 0;
    flex: 1 1 auto;
    gap: 2px;
}

.track-actions-menu__queue-title {
    font-size: 0.88rem;
    font-weight: 650;
}

.track-actions-menu__queue-artist {
    color: var(--text-soft, #687476);
    font-size: 0.78rem;
}

.track-actions-menu__current {
    color: var(--primary, #293235);
}

.track-actions-menu__empty {
    margin: 4px 8px 8px;
    color: var(--text-soft, #687476);
    font-size: 0.84rem;
}

.track-actions-menu-enter-active,
.track-actions-menu-leave-active {
    transition: opacity 0.14s ease, transform 0.14s ease;
}

.track-actions-menu-enter-from,
.track-actions-menu-leave-to {
    opacity: 0;
    transform: translateY(4px) scale(0.98);
}
</style>
