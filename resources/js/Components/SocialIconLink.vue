<script setup>
import { computed } from 'vue';

const props = defineProps({
    network: {
        type: String,
        required: true,
    },
    value: {
        type: String,
        required: true,
    },
    size: {
        type: Number,
        default: 18,
    },
});

const normalizedNetwork = computed(() => String(props.network || '').trim().toLowerCase());
const trimmedValue = computed(() => String(props.value || '').trim());

const href = computed(() => {
    const value = trimmedValue.value;
    const network = normalizedNetwork.value;

    if (!value) {
        return '#';
    }

    if (/^https?:\/\//i.test(value)) {
        return value;
    }

    const encoded = encodeURIComponent(value.replace(/^@/, ''));

    const map = {
        instagram: `https://instagram.com/${encoded}`,
        x: `https://x.com/${encoded}`,
        twitter: `https://x.com/${encoded}`,
        tiktok: `https://www.tiktok.com/@${encoded}`,
        youtube: `https://youtube.com/${encoded}`,
        facebook: `https://facebook.com/${encoded}`,
        vk: `https://vk.com/${encoded}`,
        vkontakte: `https://vk.com/${encoded}`,
        telegram: `https://t.me/${encoded}`,
        soundcloud: `https://soundcloud.com/${encoded}`,
        spotify: `https://open.spotify.com/${encoded}`,
        applemusic: `https://music.apple.com/${encoded}`,
        apple_music: `https://music.apple.com/${encoded}`,
        genius: `https://genius.com/${encoded}`,
    };

    return map[network] ?? `https://${network}.com/${encoded}`;
});

const title = computed(() => {
    const labels = {
        instagram: 'Instagram',
        x: 'X',
        twitter: 'X',
        tiktok: 'TikTok',
        youtube: 'YouTube',
        facebook: 'Facebook',
        vk: 'VK',
        vkontakte: 'VK',
        telegram: 'Telegram',
        soundcloud: 'SoundCloud',
        spotify: 'Spotify',
        applemusic: 'Apple Music',
        apple_music: 'Apple Music',
        genius: 'Genius',
    };

    return labels[normalizedNetwork.value] ?? normalizedNetwork.value;
});
</script>

<template>
    <a
        class="social-icon-link"
        :href="href"
        target="_blank"
        rel="noopener noreferrer"
        :title="title"
        :aria-label="title"
    >
        <svg v-if="normalizedNetwork === 'instagram'" :width="size" :height="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <rect x="3.5" y="3.5" width="17" height="17" rx="5" stroke="currentColor" stroke-width="1.8" />
            <circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="1.8" />
            <circle cx="17.2" cy="6.8" r="1.1" fill="currentColor" />
        </svg>

        <svg v-else-if="normalizedNetwork === 'x' || normalizedNetwork === 'twitter'" :width="size" :height="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M4 4L20 20M20 4L4 20" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
        </svg>

        <svg v-else-if="normalizedNetwork === 'youtube'" :width="size" :height="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M21 12.2C21 9.4 20.7 7.8 20.4 6.9C20.1 6 19.4 5.3 18.5 5C17 4.5 12 4.5 12 4.5C12 4.5 7 4.5 5.5 5C4.6 5.3 3.9 6 3.6 6.9C3.3 7.8 3 9.4 3 12.2C3 15 3.3 16.6 3.6 17.5C3.9 18.4 4.6 19.1 5.5 19.4C7 19.9 12 19.9 12 19.9C12 19.9 17 19.9 18.5 19.4C19.4 19.1 20.1 18.4 20.4 17.5C20.7 16.6 21 15 21 12.2Z" stroke="currentColor" stroke-width="1.7" />
            <path d="M10 9L15 12L10 15V9Z" fill="currentColor" />
        </svg>

        <svg v-else-if="normalizedNetwork === 'tiktok'" :width="size" :height="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M14 4V13.2C14 15.6 12.1 17.5 9.7 17.5C7.6 17.5 6 15.9 6 13.8C6 11.7 7.6 10 9.7 10C10.2 10 10.6 10.1 11 10.2" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
            <path d="M14 4C14.6 5.9 16.2 7.5 18.1 8.1" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
        </svg>

        <svg v-else-if="normalizedNetwork === 'facebook'" :width="size" :height="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M13.2 20V12.8H15.9L16.3 10H13.2V8.2C13.2 7.4 13.5 6.8 14.6 6.8H16.4V4.2C16.1 4.2 15.2 4 14.1 4C11.7 4 10.2 5.4 10.2 8V10H8V12.8H10.2V20H13.2Z" fill="currentColor" />
        </svg>

        <svg v-else-if="normalizedNetwork === 'vk' || normalizedNetwork === 'vkontakte'" :width="size" :height="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M5 8.5C5.2 13 7.7 15.8 12 16V13.4C13.5 13.5 14.5 14.5 14.9 16H17C16.5 14.2 15.2 13.2 14.4 12.8C15.2 12.4 16.3 11.4 16.6 9.8H14.7C14.3 11.1 13.4 12.1 12 12.2V8.5H10.1V14.9C8.7 14.6 7 13.5 6.1 8.5H5Z" fill="currentColor" />
        </svg>

        <svg v-else-if="normalizedNetwork === 'telegram'" :width="size" :height="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M21 4L3.8 10.8L9.6 12.9L12.1 19.5L21 4Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
            <path d="M9.6 12.9L21 4" stroke="currentColor" stroke-width="1.7" />
        </svg>

        <svg v-else-if="normalizedNetwork === 'soundcloud'" :width="size" :height="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M8 16H18.2C19.7 16 21 14.8 21 13.3C21 11.9 19.9 10.7 18.5 10.6C18.1 8.5 16.2 7 14 7C12.8 7 11.7 7.5 10.9 8.4C10.5 8.1 10 8 9.5 8C8.1 8 7 9.1 7 10.5V16H8Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
            <path d="M4 10.5V16M5.5 9.5V16M2.5 11.6V16" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
        </svg>

        <svg v-else-if="normalizedNetwork === 'spotify'" :width="size" :height="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.7" />
            <path d="M8 10.2C10.5 9.4 13.5 9.6 16 11" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
            <path d="M8.8 12.8C10.6 12.2 12.8 12.3 14.8 13.3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
            <path d="M9.7 15C11 14.7 12.4 14.8 13.6 15.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
        </svg>

        <svg v-else-if="normalizedNetwork === 'applemusic' || normalizedNetwork === 'apple_music'" :width="size" :height="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M14 5V15.5C14 16.9 12.9 18 11.5 18C10.1 18 9 16.9 9 15.5C9 14.1 10.1 13 11.5 13C12 13 12.5 13.1 13 13.4V7.4L19 6V13.5C19 14.9 17.9 16 16.5 16C15.1 16 14 14.9 14 13.5C14 12.1 15.1 11 16.5 11C17 11 17.5 11.1 18 11.4V4.5L14 5Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
        </svg>

        <svg v-else-if="normalizedNetwork === 'genius'" :width="size" :height="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M6 17.5V6.5H18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
            <path d="M10 11.2H17.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
            <path d="M10 15H16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
            <path d="M8 9H13.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
        </svg>

        <svg v-else :width="size" :height="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.7" />
            <path d="M3.8 12H20.2M12 3.8C14.2 6.1 15.4 9 15.4 12C15.4 15 14.2 17.9 12 20.2C9.8 17.9 8.6 15 8.6 12C8.6 9 9.8 6.1 12 3.8Z" stroke="currentColor" stroke-width="1.5" />
        </svg>
    </a>
</template>

<style scoped>
.social-icon-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 999px;
    color: rgba(255, 255, 255, 0.82);
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.08);
    text-decoration: none;
    transition: transform 0.18s ease, border-color 0.18s ease, background-color 0.18s ease, color 0.18s ease;
}

.social-icon-link:hover {
    color: #fff;
    background: rgba(255, 255, 255, 0.1);
    border-color: rgba(255, 255, 255, 0.18);
    transform: translateY(-1px);
}
</style>
