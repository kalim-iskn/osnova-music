<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import SearchBox from '../Components/SearchBox.vue';
import PlayerBar from '../Components/PlayerBar.vue';

const page = usePage();

const appName = computed(() => page.props.appName ?? 'Музыка');
const user = computed(() => page.props.auth?.user ?? null);
const flashMessage = computed(() => page.props.flash?.message ?? null);
const currentUrl = computed(() => page.url || '/');
const isMobileMenuOpen = ref(false);
const isProfileMenuOpen = ref(false);

const navigation = [
    { label: 'Главная', href: '/' },
    { label: 'Поиск', href: '/search' },
    { label: 'Мои треки', href: '/library/tracks', auth: true },
];

const visibleNavigation = computed(() => navigation.filter((item) => !item.auth || user.value));

const isActive = (href) => {
    if (href === '/') {
        return currentUrl.value === '/';
    }

    return currentUrl.value.startsWith(href);
};

const closeMenus = () => {
    isMobileMenuOpen.value = false;
    isProfileMenuOpen.value = false;
};

const toggleMobileMenu = () => {
    isMobileMenuOpen.value = !isMobileMenuOpen.value;

    if (isMobileMenuOpen.value) {
        isProfileMenuOpen.value = false;
    }
};

const toggleProfileMenu = () => {
    isProfileMenuOpen.value = !isProfileMenuOpen.value;
};

const logout = () => {
    closeMenus();
    router.post('/logout');
};

watch(currentUrl, () => {
    closeMenus();
});
</script>

<template>
    <div class="app-shell">
        <Head />

        <div class="ambient ambient-left"></div>
        <div class="ambient ambient-right"></div>

        <header class="site-header">
            <div class="container site-header__inner">
                <div class="site-header__bar">
                    <Link href="/" class="brand">
                        <span class="brand__badge">♫</span>
                        <span class="brand__text">
                            <strong>{{ appName }}</strong>
                            <small>Ваша музыка в одном месте</small>
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

                    <SearchBox :initial-query="page.props.term ?? ''" class="site-search site-search--desktop" />

                    <div class="header-actions header-actions--desktop">
                        <template v-if="user">
                            <div class="profile-menu" :class="{ 'profile-menu--open': isProfileMenuOpen }">
                                <button class="profile-menu__trigger" type="button" @click="toggleProfileMenu">
                                    <div class="user-chip">
                                        <span class="user-chip__avatar">{{ user.name.slice(0, 1).toUpperCase() }}</span>
                                        <div class="user-chip__meta">
                                            <strong>{{ user.name }}</strong>
                                            <small>{{ user.email }}</small>
                                        </div>
                                    </div>
                                    <span class="profile-menu__caret" aria-hidden="true">▾</span>
                                </button>

                                <div v-if="isProfileMenuOpen" class="profile-menu__panel">
                                    <div class="profile-menu__user">
                                        <strong>{{ user.name }}</strong>
                                        <small>{{ user.email }}</small>
                                    </div>

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

                    <div class="header-actions-mobile">
                        <template v-if="!user">
                            <Link href="/login" class="ghost-button ghost-button--small header-actions-mobile__auth">Войти</Link>
                            <Link href="/register" class="primary-button primary-button--small header-actions-mobile__auth">Регистрация</Link>
                        </template>

                        <button class="icon-button mobile-menu-toggle" type="button" @click="toggleMobileMenu">
                            {{ isMobileMenuOpen ? '✕' : '☰' }}
                        </button>
                    </div>
                </div>

                <div class="site-header__mobile-search">
                    <SearchBox :initial-query="page.props.term ?? ''" class="site-search" />
                </div>

                <transition name="fade">
                    <div v-if="isMobileMenuOpen" class="mobile-menu">
                        <nav class="mobile-menu__nav">
                            <Link
                                v-for="item in visibleNavigation"
                                :key="item.href"
                                :href="item.href"
                                class="mobile-menu__link"
                                :class="{ 'mobile-menu__link--active': isActive(item.href) }"
                                @click="closeMenus"
                            >
                                {{ item.label }}
                            </Link>
                        </nav>

                        <div class="mobile-menu__footer">
                            <template v-if="user">
                                <div class="mobile-menu__profile">
                                    <span class="user-chip__avatar">{{ user.name.slice(0, 1).toUpperCase() }}</span>
                                    <div class="mobile-menu__profile-meta">
                                        <strong>{{ user.name }}</strong>
                                        <small>{{ user.email }}</small>
                                    </div>
                                </div>

                                <button class="ghost-button ghost-button--small mobile-menu__logout" type="button" @click="logout">
                                    Выйти
                                </button>
                            </template>

                            <template v-else>
                                <div class="mobile-menu__auth">
                                    <Link href="/login" class="ghost-button ghost-button--small" @click="closeMenus">Войти</Link>
                                    <Link href="/register" class="primary-button primary-button--small" @click="closeMenus">Регистрация</Link>
                                </div>
                            </template>
                        </div>
                    </div>
                </transition>
            </div>
        </header>

        <main class="container page-shell">
            <div v-if="flashMessage" class="flash-message">
                {{ flashMessage }}
            </div>

            <slot />
        </main>

        <PlayerBar />
    </div>
</template>
