import { defineStore } from 'pinia';
import { formatSeconds } from '../utils/time';

const STORAGE_KEY = 'waveflow-player';
const PLAY_REPORT_THRESHOLD = 8;
const REPEAT_MODES = ['off', 'track', 'queue'];

const resolvePlaybackUrl = (track) => track.playback_url ?? (track.id ? `/tracks/${track.id}/stream` : track.audio_url);

const sanitizeTrack = (track) => ({
    id: track.id,
    title: track.title,
    audio_url: track.audio_url,
    playback_url: resolvePlaybackUrl(track),
    cover_image_url: track.cover_image_url,
    duration_seconds: Number(track.duration_seconds || 0),
    duration_human: track.duration_human ?? formatSeconds(track.duration_seconds || 0),
    plays_count: Number(track.plays_count || 0),
    artist: track.artist,
    album: track.album,
});

export const usePlayerStore = defineStore('player', {
    state: () => ({
        queue: [],
        currentIndex: -1,
        currentTime: 0,
        duration: 0,
        volume: 0.8,
        previousVolume: 0.8,
        isPlaying: false,
        isQueueOpen: false,
        repeatMode: 'off',
        audioElement: null,
        initialized: false,
        listeners: null,
        playReportedForCurrent: false,
    }),

    getters: {
        currentTrack: (state) => state.queue[state.currentIndex] ?? null,
        hasNext: (state) => state.currentIndex >= 0 && state.currentIndex < state.queue.length - 1,
        hasPrevious: (state) => state.currentIndex > 0,
        isMuted: (state) => Number(state.volume) <= 0,
    },

    actions: {
        init() {
            if (this.initialized) {
                return;
            }

            this.restore();
            this.initialized = true;
        },

        attach(audioElement) {
            this.init();

            if (!audioElement || this.audioElement === audioElement) {
                return;
            }

            if (this.audioElement && this.listeners) {
                Object.entries(this.listeners).forEach(([event, handler]) => {
                    this.audioElement.removeEventListener(event, handler);
                });
            }

            this.audioElement = audioElement;
            this.audioElement.volume = this.volume;

            this.listeners = {
                loadedmetadata: () => {
                    this.updateDurationFromMedia();

                    if (this.currentTime > 0 && this.audioElement) {
                        this.audioElement.currentTime = this.currentTime;
                    }

                    this.persist();
                },
                durationchange: () => {
                    this.updateDurationFromMedia();
                },
                timeupdate: () => {
                    this.currentTime = Math.floor(this.audioElement?.currentTime || 0);
                    this.updateDurationFromMedia();

                    if (!this.playReportedForCurrent && this.currentTrack && this.currentTime >= PLAY_REPORT_THRESHOLD) {
                        this.playReportedForCurrent = true;
                        this.reportPlay(this.currentTrack.id);
                    }

                    if (this.currentTime % 3 === 0) {
                        this.persist();
                    }
                },
                play: () => {
                    this.isPlaying = true;
                    this.persist();
                },
                pause: () => {
                    this.isPlaying = false;
                    this.persist();
                },
                ended: async () => {
                    await this.handleTrackEnded();
                },
                error: async () => {
                    await this.fallbackToDirectSource();
                },
            };

            Object.entries(this.listeners).forEach(([event, handler]) => {
                this.audioElement.addEventListener(event, handler);
            });

            if (this.currentTrack) {
                this.audioElement.src = this.currentTrack.playback_url ?? this.currentTrack.audio_url;
                this.audioElement.currentTime = this.currentTime;
            }
        },

        persist() {
            const payload = {
                queue: this.queue,
                currentIndex: this.currentIndex,
                currentTime: this.currentTime,
                duration: this.duration,
                volume: this.volume,
                previousVolume: this.previousVolume,
                isPlaying: this.isPlaying,
                repeatMode: this.repeatMode,
            };

            localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
        },

        restore() {
            const raw = localStorage.getItem(STORAGE_KEY);

            if (!raw) {
                return;
            }

            try {
                const parsed = JSON.parse(raw);
                this.queue = Array.isArray(parsed.queue) ? parsed.queue.map(sanitizeTrack) : [];
                this.currentIndex = Number.isInteger(parsed.currentIndex) ? parsed.currentIndex : -1;
                this.currentTime = Number(parsed.currentTime || 0);
                this.duration = Number(parsed.duration || 0);
                this.volume = Number(parsed.volume || 0.8);
                this.previousVolume = Number(parsed.previousVolume || this.volume || 0.8);
                this.repeatMode = REPEAT_MODES.includes(parsed.repeatMode) ? parsed.repeatMode : 'off';
                this.isPlaying = false;
            } catch {
                localStorage.removeItem(STORAGE_KEY);
            }
        },

        updateDurationFromMedia() {
            const mediaDuration = Math.floor(this.audioElement?.duration || 0);
            const resolvedDuration = mediaDuration || this.currentTrack?.duration_seconds || 0;

            this.duration = resolvedDuration;

            if (this.currentTrack && resolvedDuration > 0) {
                this.queue[this.currentIndex] = {
                    ...this.currentTrack,
                    duration_seconds: resolvedDuration,
                    duration_human: formatSeconds(resolvedDuration),
                };
            }
        },

        async playTrack(track, queue = null) {
            const normalizedTrack = sanitizeTrack(track);

            if (Array.isArray(queue) && queue.length > 0) {
                this.queue = queue.map(sanitizeTrack);
                this.currentIndex = this.queue.findIndex((item) => item.id === normalizedTrack.id);

                if (this.currentIndex === -1) {
                    this.queue.unshift(normalizedTrack);
                    this.currentIndex = 0;
                }
            } else {
                const existingIndex = this.queue.findIndex((item) => item.id === normalizedTrack.id);

                if (existingIndex === -1) {
                    this.queue.push(normalizedTrack);
                    this.currentIndex = this.queue.length - 1;
                } else {
                    this.currentIndex = existingIndex;
                }
            }

            await this.loadCurrentTrack(true);
        },

        addToQueue(track) {
            const normalizedTrack = sanitizeTrack(track);
            const exists = this.queue.some((item) => item.id === normalizedTrack.id);

            if (!exists) {
                this.queue.push(normalizedTrack);
                this.persist();
            }

            if (this.currentIndex === -1) {
                this.currentIndex = 0;
            }
        },

        async loadCurrentTrack(autoplay = false) {
            const track = this.currentTrack;

            if (!track || !this.audioElement) {
                this.persist();
                return;
            }

            this.audioElement.src = track.playback_url ?? track.audio_url;
            this.audioElement.currentTime = 0;
            this.currentTime = 0;
            this.duration = track.duration_seconds || 0;
            this.audioElement.volume = this.volume;
            this.playReportedForCurrent = false;
            this.persist();

            if (autoplay) {
                try {
                    await this.audioElement.play();
                    this.isPlaying = true;
                } catch {
                    this.isPlaying = false;
                }
            }
        },

        async fallbackToDirectSource() {
            const track = this.currentTrack;

            if (!track || !this.audioElement || !track.audio_url) {
                return;
            }

            const currentSource = this.audioElement.currentSrc || this.audioElement.src || '';

            if (currentSource.includes(track.audio_url)) {
                this.isPlaying = false;
                return;
            }

            this.audioElement.src = track.audio_url;
            this.audioElement.currentTime = 0;
            this.currentTime = 0;
            this.playReportedForCurrent = false;

            try {
                await this.audioElement.play();
                this.isPlaying = true;
            } catch {
                this.isPlaying = false;
            }
        },

        async togglePlayback() {
            if (!this.currentTrack || !this.audioElement) {
                return;
            }

            if (this.audioElement.paused) {
                try {
                    await this.audioElement.play();
                    this.isPlaying = true;
                } catch {
                    this.isPlaying = false;
                }
            } else {
                this.audioElement.pause();
                this.isPlaying = false;
            }

            this.persist();
        },

        async handleTrackEnded() {
            if (this.repeatMode === 'track') {
                this.seekTo(0);
                await this.togglePlayback();
                return;
            }

            if (this.hasNext) {
                this.currentIndex += 1;
                await this.loadCurrentTrack(true);
                return;
            }

            if (this.repeatMode === 'queue' && this.queue.length > 0) {
                this.currentIndex = 0;
                await this.loadCurrentTrack(true);
                return;
            }

            this.isPlaying = false;
            this.currentTime = 0;

            if (this.audioElement) {
                this.audioElement.pause();
                this.audioElement.currentTime = 0;
            }

            this.persist();
        },

        async playNext() {
            if (this.hasNext) {
                this.currentIndex += 1;
                await this.loadCurrentTrack(true);
                return;
            }

            if (this.repeatMode === 'queue' && this.queue.length > 0) {
                this.currentIndex = 0;
                await this.loadCurrentTrack(true);
                return;
            }

            this.isPlaying = false;
            this.currentTime = 0;

            if (this.audioElement) {
                this.audioElement.pause();
                this.audioElement.currentTime = 0;
            }

            this.persist();
        },

        async playPrevious() {
            if (this.audioElement && this.audioElement.currentTime > 5) {
                this.seekTo(0);
                return;
            }

            if (!this.hasPrevious) {
                if (this.repeatMode === 'queue' && this.queue.length > 0) {
                    this.currentIndex = this.queue.length - 1;
                    await this.loadCurrentTrack(true);
                    return;
                }

                this.seekTo(0);
                return;
            }

            this.currentIndex -= 1;
            await this.loadCurrentTrack(true);
        },

        seekTo(value) {
            const seconds = Number(value || 0);
            this.currentTime = seconds;

            if (this.audioElement) {
                this.audioElement.currentTime = seconds;
            }

            this.persist();
        },

        setVolume(value) {
            const normalizedVolume = Math.max(0, Math.min(1, Number(value || 0)));

            if (normalizedVolume > 0) {
                this.previousVolume = normalizedVolume;
            }

            this.volume = normalizedVolume;

            if (this.audioElement) {
                this.audioElement.volume = normalizedVolume;
            }

            this.persist();
        },

        toggleMute() {
            if (this.isMuted) {
                this.setVolume(this.previousVolume > 0 ? this.previousVolume : 0.8);
                return;
            }

            this.previousVolume = this.volume > 0 ? this.volume : this.previousVolume;
            this.setVolume(0);
        },

        cycleRepeatMode() {
            const currentIndex = REPEAT_MODES.indexOf(this.repeatMode);
            const nextIndex = (currentIndex + 1) % REPEAT_MODES.length;
            this.repeatMode = REPEAT_MODES[nextIndex];
            this.persist();
        },

        async playQueueItem(index) {
            if (index < 0 || index >= this.queue.length) {
                return;
            }

            this.currentIndex = index;
            await this.loadCurrentTrack(true);
        },

        async removeFromQueue(index) {
            if (index < 0 || index >= this.queue.length) {
                return;
            }

            const wasCurrent = index === this.currentIndex;
            this.queue.splice(index, 1);

            if (this.queue.length === 0) {
                this.currentIndex = -1;
                this.currentTime = 0;
                this.duration = 0;
                this.isPlaying = false;
                this.playReportedForCurrent = false;
                if (this.audioElement) {
                    this.audioElement.pause();
                    this.audioElement.removeAttribute('src');
                    this.audioElement.load();
                }
                this.persist();
                return;
            }

            if (index < this.currentIndex) {
                this.currentIndex -= 1;
            }

            if (wasCurrent) {
                if (this.currentIndex >= this.queue.length) {
                    this.currentIndex = this.queue.length - 1;
                }
                await this.loadCurrentTrack(this.isPlaying);
            }

            this.persist();
        },

        clearQueue() {
            this.queue = [];
            this.currentIndex = -1;
            this.currentTime = 0;
            this.duration = 0;
            this.isPlaying = false;
            this.playReportedForCurrent = false;
            if (this.audioElement) {
                this.audioElement.pause();
                this.audioElement.removeAttribute('src');
                this.audioElement.load();
            }
            this.persist();
        },

        toggleQueue() {
            this.isQueueOpen = !this.isQueueOpen;
        },

        async reportPlay(trackId) {
            if (!trackId || !window.axios) {
                return;
            }

            try {
                await window.axios.post(`/tracks/${trackId}/play`);
            } catch {
                // noop
            }
        },
    },
});
