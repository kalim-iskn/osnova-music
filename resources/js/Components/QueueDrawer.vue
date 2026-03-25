<script setup>
import { formatCount } from '../utils/pluralize';
import { usePlayerStore } from '../stores/player';

const player = usePlayerStore();
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
                    >
                        <button type="button" class="queue-item__select" @click="player.playQueueItem(index)">
                            <img :src="track.cover_image_url" :alt="track.title" class="queue-item__cover">

                            <span class="queue-item__meta">
                                <strong>{{ track.title }}</strong>
                                <small>{{ track.artist.name }}</small>
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
