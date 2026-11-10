<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    track: {
        type: Object,
        default: null,
    },
    artists: {
        type: Array,
        default: null,
    },
    links: {
        type: Boolean,
        default: true,
    },
});

const resolvedArtists = computed(() => {
    const source = Array.isArray(props.artists) && props.artists.length
        ? props.artists
        : Array.isArray(props.track?.artists) && props.track.artists.length
            ? props.track.artists
            : props.track?.artist
                ? [props.track.artist]
                : [];

    return source.filter((artist, index, collection) => {
        if (!artist) {
            return false;
        }

        const key = artist.id ?? artist.slug ?? artist.name ?? index;

        return collection.findIndex((item, itemIndex) => {
            const itemKey = item?.id ?? item?.slug ?? item?.name ?? itemIndex;
            return itemKey === key;
        }) === index;
    });
});

const creditGroups = computed(() => {
    const groups = [];
    const primary = resolvedArtists.value.filter((artist) => !artist.credit_role || artist.credit_role === 'primary');
    const featured = resolvedArtists.value.filter((artist) => artist.credit_role === 'featured');
    const otherRoles = new Map();

    for (const artist of resolvedArtists.value) {
        const role = artist.credit_role;

        if (!role || role === 'primary' || role === 'featured') {
            continue;
        }

        if (!otherRoles.has(role)) {
            otherRoles.set(role, []);
        }

        otherRoles.get(role).push(artist);
    }

    if (primary.length) {
        groups.push({ label: '', artists: primary, separator: '&' });
    }

    if (featured.length) {
        groups.push({ label: 'feat.', artists: featured, separator: '&' });
    }

    const roleLabels = {
        with: 'with',
        guest: 'feat.',
        remixer: 'remix by',
    };

    for (const [role, artists] of otherRoles) {
        groups.push({
            label: roleLabels[role] ?? role.replaceAll('_', ' '),
            artists,
            separator: '&',
        });
    }

    return groups;
});
</script>

<template>
    <span v-if="creditGroups.length" class="track-artists">
        <template v-for="(group, groupIndex) in creditGroups" :key="`${group.label}-${groupIndex}`">
            <span v-if="groupIndex" class="track-artists__role-separator">·</span>
            <span v-if="group.label" class="track-artists__role">{{ group.label }}</span>
            <template v-for="(artist, artistIndex) in group.artists" :key="artist.id ?? `${artist.name}-${artistIndex}`">
                <span v-if="artistIndex" class="track-artists__separator">{{ group.separator }}</span>
                <Link v-if="links && artist.slug" :href="`/artists/${artist.slug}`" class="track-artists__link" @click.stop>
                    {{ artist.name }}
                </Link>
                <span v-else class="track-artists__text">{{ artist.name }}</span>
            </template>
        </template>
    </span>

    <span v-else class="track-artists__fallback">Неизвестный исполнитель</span>
</template>

<style scoped>
.track-artists {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.35rem;
    min-width: 0;
}

.track-artists__separator {
    color: #9aa4a5;
    margin-inline: 0.08rem;
}

.track-artists__role-separator {
    color: #9aa4a5;
    margin-inline: 0.08rem;
}

.track-artists__role {
    color: var(--text-soft);
    font-size: 0.9em;
    font-weight: 600;
}

.track-artists__link,
.track-artists__text,
.track-artists__fallback {
    min-width: 0;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.track-artists__link {
    color: var(--text-soft);
    text-decoration: none;
    transition: color 0.18s ease;
}

.track-artists__link:hover {
    color: var(--text);
}

.track-artists__fallback {
    color: var(--text-soft);
}
</style>
