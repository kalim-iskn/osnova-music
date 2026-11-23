<script setup>
import { Link } from '@inertiajs/vue3';
import LikeButton from './LikeButton.vue';
import { formatAlbumReleaseDate } from '../utils/releaseDate';
defineProps({ album: Object, showDate: Boolean });
defineEmits(['like-changed']);
</script>
<template>
    <article class="album-card release-card">
        <Link :href="`/albums/${album.slug}`" class="release-card__cover-link"><img :src="album.cover_image_url" :alt="album.title" class="album-card__cover"></Link>
        <div class="release-card__details">
            <Link :href="`/albums/${album.slug}`" class="album-card__body"><strong>{{ album.title }}</strong><small>{{ showDate ? (formatAlbumReleaseDate(album) ?? 'Дата релиза уточняется') : album.artist?.name }}</small></Link>
            <LikeButton :album="album" icon-only @changed="$emit('like-changed', $event)" />
        </div>
    </article>
</template>
