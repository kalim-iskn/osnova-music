import { defineStore } from 'pinia';

const STORAGE_KEY = 'waveflow-player';

const sanitizeTrack = (track) => ({
    id: track.id,
    title: track.title,
    audio_url: track.audio_url,
    cover_image_url: track.cover_image_url,
    duration_seconds: track.duration_seconds,
    duration_human: track.duration_human,
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
        isPlaying: false,
        isQueueOpen: false,
        audioElement: null,
        initialized: false,
        listeners: null,
    }),

    getters: {
        currentTrack: (state) => state.queue[state.currentIndex] ?? null,
        hasNext: (state) => state.currentIndex >= 0 && state.currentIndex < state.queue.length - 1,
        hasPrevious: (state) => state.currentIndex > 0,
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

            if (this.audioElement === audioElement) {
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
                    this.duration = Math.floor(this.audioElement?.duration || this.currentTrack?.duration_seconds || 0);

                    if (this.currentTime > 0 && this.audioElement) {
                        this.audioElement.currentTime = this.currentTime;
                    }

                    this.persist();
                },
                timeupdate: () => {
                    this.currentTime = Math.floor(this.audioElement?.currentTime || 0);
                    this.duration = Math.floor(this.audioElement?.duration || this.duration || 0);

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
                ended: () => {
                    this.playNext();
                },
            };

            Object.entries(this.listeners).forEach(([event, handler]) => {
                this.audioElement.addEventListener(event, handler);
            });

            if (this.currentTrack) {
                this.audioElement.src = this.currentTrack.audio_url;
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
                isPlaying: this.isPlaying,
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
                this.queue = Array.isArray(parsed.queue) ? parsed.queue : [];
                this.currentIndex = Number.isInteger(parsed.currentIndex) ? parsed.currentIndex : -1;
                this.currentTime = Number(parsed.currentTime || 0);
                this.duration = Number(parsed.duration || 0);
                this.volume = Number(parsed.volume || 0.8);
                this.isPlaying = false;
            } catch {
                localStorage.removeItem(STORAGE_KEY);
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

            this.audioElement.src = track.audio_url;
            this.audioElement.currentTime = 0;
            this.currentTime = 0;
            this.duration = track.duration_seconds || 0;
            this.audioElement.volume = this.volume;
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

        async togglePlayback() {
            if (!this.currentTrack) {
                return;
            }

            if (!this.audioElement) {
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

        async playNext() {
            if (!this.hasNext) {
                this.isPlaying = false;
                this.currentTime = 0;
                if (this.audioElement) {
                    this.audioElement.pause();
                    this.audioElement.currentTime = 0;
                }
                this.persist();
                return;
            }

            this.currentIndex += 1;
            await this.loadCurrentTrack(true);
        },

        async playPrevious() {
            if (this.audioElement && this.audioElement.currentTime > 5) {
                this.seekTo(0);
                return;
            }

            if (!this.hasPrevious) {
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
            this.volume = Number(value || 0);

            if (this.audioElement) {
                this.audioElement.volume = this.volume;
            }

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
    },
});
