<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import SearchBox from '../Components/SearchBox.vue';
import PlayerBar from '../Components/PlayerBar.vue';

const page = usePage();

const user = computed(() => page.props.auth?.user ?? null);
const flashMessage = computed(() => page.props.flash?.message ?? null);
const currentUrl = computed(() => page.url || '/');

const navigation = [
    { label: 'Главная', href: '/' },
    { label: 'Поиск', href: '/search' },
    { label: 'Мои треки', href: '/library/tracks', auth: true },
];

const isActive = (href) => {
    if (href === '/') {
        return currentUrl.value === '/';
    }

    return currentUrl.value.startsWith(href);
};

const logout = () => {
    router.post('/logout');
};
</script>

<template>
    <div class="app-shell">
        <Head />

        <div class="ambient ambient-left"></div>
        <div class="ambient ambient-right"></div>

        <header class="site-header">
            <div class="container site-header__inner">
                <Link href="/" class="brand">
                    <span class="brand__badge">♫</span>
                    <span>
                        <strong>WaveFlow</strong>
                        <small>Laravel music player</small>
                    </span>
                </Link>

                <nav class="nav-desktop">
                    <Link
                        v-for="item in navigation"
                        :key="item.href"
                        v-show="!item.auth || user"
                        :href="item.href"
                        class="nav-link"
                        :class="{ 'nav-link--active': isActive(item.href) }"
                    >
                        {{ item.label }}
                    </Link>
                </nav>

                <SearchBox :initial-query="page.props.term ?? ''" compact class="site-search" />

                <div class="header-actions">
                    <template v-if="user">
                        <div class="user-chip">
                            <span class="user-chip__avatar">{{ user.name.slice(0, 1).toUpperCase() }}</span>
                            <div>
                                <strong>{{ user.name }}</strong>
                                <small>{{ user.email }}</small>
                            </div>
                        </div>
                        <button class="ghost-button" type="button" @click="logout">Выйти</button>
                    </template>
                    <template v-else>
                        <Link href="/login" class="ghost-button">Войти</Link>
                        <Link href="/register" class="primary-button">Регистрация</Link>
                    </template>
                </div>
            </div>
        </header>

        <main class="container page-shell">
            <div v-if="flashMessage" class="flash-message">
                {{ flashMessage }}
            </div>

            <slot />
        </main>

        <nav class="nav-mobile">
            <Link
                v-for="item in navigation"
                :key="item.href"
                v-show="!item.auth || user"
                :href="item.href"
                class="nav-mobile__link"
                :class="{ 'nav-mobile__link--active': isActive(item.href) }"
            >
                {{ item.label }}
            </Link>
        </nav>

        <PlayerBar />
    </div>
</template>
