<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import SearchBox from '../Components/SearchBox.vue';
import PlayerBar from '../Components/PlayerBar.vue';
import AppIcon from '../Components/AppIcon.vue';
import { OFFLINE_OWNER_KEY } from '../utils/offlineTracks';

const page = usePage();

const appName = computed(() => page.props.appName ?? 'Музыка');
const user = computed(() => page.props.auth?.user ?? null);
const flashMessage = computed(() => page.props.flash?.message ?? null);
const currentUrl = computed(() => page.url || '/');
const isProfileMenuOpen = ref(false);

const navigation = [
    { label: 'Главная', href: '/' },
    { label: 'Поиск', href: '/search' },
    { label: 'Моя коллекция', href: '/library/tracks', auth: true },
    { label: 'Парсинг', href: '/tools/parsing', auth: true },
];

const visibleNavigation = computed(() => navigation.filter((item) => !item.auth || user.value));
const mobileNavigation = computed(() => [
    { label: 'Главная', href: '/', icon: 'home' },
    { label: 'Поиск', href: '/search', icon: 'search' },
    { label: 'Коллекция', href: user.value ? '/library/tracks' : '/login', icon: 'queue' },
]);

const isActive = (href) => {
    if (href === '/') {
        return currentUrl.value === '/';
    }

    return currentUrl.value.startsWith(href);
};

const closeMenus = () => {
    isProfileMenuOpen.value = false;
};

const toggleProfileMenu = () => {
    isProfileMenuOpen.value = !isProfileMenuOpen.value;
};

const logout = () => {
    closeMenus();
    localStorage.removeItem(OFFLINE_OWNER_KEY);
    router.post('/logout');
};

watch(() => user.value?.id, (id) => {
    if (id != null) localStorage.setItem(OFFLINE_OWNER_KEY, String(id));
    else localStorage.removeItem(OFFLINE_OWNER_KEY);
}, { immediate: true });

watch(currentUrl, () => {
    closeMenus();
});
</script>

<template>
    <div class="app-shell montage-app">
        <Head />

        <div class="ambient ambient-left"></div>
        <div class="ambient ambient-right"></div>

        <header class="site-header">
            <div class="container site-header__inner">
                <div class="site-header__bar">
                    <Link href="/" class="brand">
                        <span class="brand__badge"><AppIcon name="music" :size="21" /></span>
                        <span class="brand__text">
                            <strong>{{ appName }}</strong>
                            <small>музыка рядом</small>
                        </span>
                    </Link>

                    <nav class="nav-desktop">
                        <Link
                            v-for="item in visibleNavigation"
                            :key="item.href"
                            :href="item.href"
                            class="nav-link"
                            :class="{ 'nav-link--active': isActive(item.href) }"
                        >
                            {{ item.label }}
                        </Link>
                    </nav>

                    <SearchBox :initial-query="page.props.term ?? ''" compact class="site-search site-search--desktop" />

                    <div class="header-actions header-actions--desktop">
                        <template v-if="user">
                            <div class="profile-menu" :class="{ 'profile-menu--open': isProfileMenuOpen }">
                                <button class="profile-menu__trigger" type="button" :aria-expanded="isProfileMenuOpen" aria-label="Меню аккаунта" @click="toggleProfileMenu" @keydown.esc="closeMenus">
                                    <span class="user-chip__avatar" :aria-label="`Профиль: ${user.name}`">
                                        {{ user.name.slice(0, 1).toUpperCase() }}
                                    </span>
                                    <span class="profile-menu__caret"><AppIcon name="chevronDown" :size="15" /></span>
                                </button>

                                <div v-if="isProfileMenuOpen" class="profile-menu__panel">
                                    <div class="profile-menu__user">
                                        <strong>{{ user.name }}</strong>
                                        <small>{{ user.email }}</small>
                                    </div>

                                    <Link href="/tools/parsing" class="profile-menu__link profile-menu__parsing" @click="closeMenus">Парсинг</Link>
                                    <button class="profile-menu__link profile-menu__link--button" type="button" @click="logout">
                                        Выйти
                                    </button>
                                </div>
                            </div>
                        </template>

                        <template v-else>
                            <Link href="/login" class="ghost-button ghost-button--small">Войти</Link>
                            <Link href="/register" class="primary-button primary-button--small">Регистрация</Link>
                        </template>
                    </div>

                </div>
            </div>
        </header>

        <main class="container page-shell">
            <div v-if="flashMessage" class="flash-message">
                {{ flashMessage }}
            </div>

            <slot />
        </main>

        <PlayerBar />
        <nav class="mobile-bottom-nav" aria-label="Основные разделы">
            <Link v-for="item in mobileNavigation" :key="item.href" :href="item.href" :aria-current="isActive(item.href) ? 'page' : undefined"><AppIcon :name="item.icon" :size="20" />{{ item.label }}</Link>
        </nav>
    </div>
</template>
