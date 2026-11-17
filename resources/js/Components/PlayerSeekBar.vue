<script setup>
import { computed, ref } from 'vue';
import { formatSeconds } from '../utils/time';

const props = defineProps({
    value: {
        type: Number,
        default: 0,
    },
    max: {
        type: Number,
        default: 0,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    edge: {
        type: Boolean,
        default: false,
    },
    showTimes: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['seek']);
const isScrubbing = ref(false);
const previewValue = ref(0);

const safeMax = computed(() => Math.max(0, Number(props.max) || 0));
const safeValue = computed(() => Math.min(Math.max(0, Number(props.value) || 0), safeMax.value));
const displayedValue = computed(() => isScrubbing.value ? previewValue.value : safeValue.value);
const progress = computed(() => safeMax.value > 0 ? (displayedValue.value / safeMax.value) * 100 : 0);
const style = computed(() => ({ '--seek-progress': `${progress.value}%` }));

const update = (event) => {
    const value = Number(event.target.value);
    previewValue.value = value;
    emit('seek', value);
};

const startScrubbing = () => {
    previewValue.value = safeValue.value;
    isScrubbing.value = true;
};

const stopScrubbing = () => {
    isScrubbing.value = false;
};
</script>

<template>
    <div
        class="seek-bar"
        :class="{
            'seek-bar--edge': edge,
            'seek-bar--with-times': showTimes,
            'seek-bar--scrubbing': isScrubbing,
            'seek-bar--disabled': disabled,
        }"
        :style="style"
    >
        <span v-if="showTimes" class="seek-bar__time">{{ formatSeconds(safeValue) }}</span>

        <div class="seek-bar__control">
            <span class="seek-bar__rail" aria-hidden="true">
                <span class="seek-bar__fill"></span>
            </span>

            <span class="seek-bar__tooltip" aria-hidden="true">
                {{ formatSeconds(displayedValue) }}
            </span>

            <input
                :value="safeValue"
                type="range"
                min="0"
                :max="safeMax"
                step="0.1"
                :disabled="disabled || safeMax <= 0"
                aria-label="Позиция воспроизведения"
                @input="update"
                @pointerdown="startScrubbing"
                @pointerup="stopScrubbing"
                @pointercancel="stopScrubbing"
                @blur="stopScrubbing"
            >
        </div>

        <span v-if="showTimes" class="seek-bar__time seek-bar__time--end">{{ formatSeconds(safeMax) }}</span>
    </div>
</template>

<style scoped>
.seek-bar {
    --seek-track: #d9dfdc;
    --seek-fill: #3c4749;
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    align-items: center;
    width: 100%;
    min-width: 0;
}

.seek-bar--with-times {
    grid-template-columns: auto minmax(0, 1fr) auto;
    gap: 12px;
}

.seek-bar__control {
    position: relative;
    height: 28px;
    min-width: 0;
    display: flex;
    align-items: center;
}

.seek-bar__rail {
    position: absolute;
    inset-inline: 0;
    top: 50%;
    height: 4px;
    overflow: hidden;
    border-radius: 999px;
    background: var(--seek-track);
    transform: translateY(-50%);
    pointer-events: none;
}

.seek-bar__fill {
    display: block;
    width: var(--seek-progress);
    height: 100%;
    border-radius: inherit;
    background: var(--seek-fill);
}

.seek-bar input {
    position: relative;
    z-index: 2;
    width: 100%;
    height: 28px;
    margin: 0;
    appearance: none;
    -webkit-appearance: none;
    cursor: pointer;
    background: transparent;
    touch-action: none;
}

.seek-bar input::-webkit-slider-runnable-track {
    height: 4px;
    background: transparent;
}

.seek-bar input::-webkit-slider-thumb {
    width: 14px;
    height: 14px;
    margin-top: -5px;
    appearance: none;
    -webkit-appearance: none;
    border: 3px solid #fff;
    border-radius: 50%;
    background: var(--seek-fill);
    box-shadow: 0 2px 7px rgba(20, 29, 29, 0.2);
    opacity: 0;
    transition: opacity 140ms ease, transform 140ms ease;
}

.seek-bar input::-moz-range-track {
    height: 4px;
    background: transparent;
}

.seek-bar input::-moz-range-progress {
    background: transparent;
}

.seek-bar input::-moz-range-thumb {
    width: 10px;
    height: 10px;
    border: 3px solid #fff;
    border-radius: 50%;
    background: var(--seek-fill);
    box-shadow: 0 2px 7px rgba(20, 29, 29, 0.2);
    opacity: 0;
    transition: opacity 140ms ease, transform 140ms ease;
}

.seek-bar:hover input::-webkit-slider-thumb,
.seek-bar:focus-within input::-webkit-slider-thumb,
.seek-bar--scrubbing input::-webkit-slider-thumb,
.seek-bar:hover input::-moz-range-thumb,
.seek-bar:focus-within input::-moz-range-thumb,
.seek-bar--scrubbing input::-moz-range-thumb {
    opacity: 1;
}

.seek-bar--scrubbing input::-webkit-slider-thumb,
.seek-bar--scrubbing input::-moz-range-thumb {
    transform: scale(1.12);
}

.seek-bar__tooltip {
    position: absolute;
    z-index: 3;
    left: clamp(24px, var(--seek-progress), calc(100% - 24px));
    bottom: 26px;
    padding: 5px 7px;
    border-radius: 7px;
    color: #fff;
    background: #293235;
    font-size: 0.72rem;
    font-variant-numeric: tabular-nums;
    line-height: 1;
    opacity: 0;
    pointer-events: none;
    transform: translate(-50%, 4px);
    transition: opacity 120ms ease, transform 120ms ease;
}

.seek-bar:hover .seek-bar__tooltip,
.seek-bar:focus-within .seek-bar__tooltip,
.seek-bar--scrubbing .seek-bar__tooltip {
    opacity: 1;
    transform: translate(-50%, 0);
}

.seek-bar__time {
    min-width: 3.2em;
    color: #748083;
    font-size: 0.78rem;
    font-variant-numeric: tabular-nums;
    text-align: left;
}

.seek-bar__time--end {
    text-align: right;
}

.seek-bar--edge {
    position: absolute;
    z-index: 4;
    top: -13px;
    left: 0;
    right: 0;
}

.seek-bar--edge .seek-bar__control {
    height: 26px;
}

.seek-bar--edge .seek-bar__rail {
    height: 3px;
    border-radius: 0;
}

.seek-bar--edge .seek-bar__tooltip {
    bottom: 23px;
}

.seek-bar--disabled {
    opacity: 0.45;
}

@media (pointer: coarse) {
    .seek-bar__control,
    .seek-bar input {
        height: 36px;
    }

    .seek-bar input::-webkit-slider-thumb {
        width: 18px;
        height: 18px;
        margin-top: -7px;
        opacity: 1;
    }

    .seek-bar input::-moz-range-thumb {
        width: 14px;
        height: 14px;
        opacity: 1;
    }

    .seek-bar--edge {
        top: -18px;
    }
}
</style>
