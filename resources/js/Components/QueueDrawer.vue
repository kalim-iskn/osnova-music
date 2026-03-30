<script setup>
import { formatCount } from '../utils/pluralize';
import { usePlayerStore } from '../stores/player';
import PlayerQueueList from './PlayerQueueList.vue';

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

                <PlayerQueueList v-if="player.queue.length" />

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

@media (max-width: 900px) {
    .queue-drawer {
        display: none;
    }
}
</style>
