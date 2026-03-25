<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const page = usePage();
const appName = computed(() => page.props.appName ?? 'Музыка');

const form = useForm({
    email: '',
    password: '',
    remember: true,
});

const submit = () => {
    form.post('/login');
};
</script>

<template>
    <Head title="Войти" />

    <section class="auth-shell">
        <div class="auth-panel">
            <span class="eyebrow">Добро пожаловать</span>
            <h1>С возвращением в {{ appName }}</h1>
            <p>
                Войдите в аккаунт, чтобы продолжить слушать музыку, управлять очередью и сохранять любимые треки.
            </p>
        </div>

        <div class="auth-card">
            <div class="auth-card__header">
                <span class="eyebrow">Вход</span>
                <h2>Авторизация</h2>
                <p class="section-description">Введите email и пароль, чтобы открыть свою коллекцию.</p>
            </div>

            <form class="form-stack" @submit.prevent="submit">
                <label class="form-field">
                    <span>Email</span>
                    <input v-model="form.email" type="email" autocomplete="email" required placeholder="you@example.com">
                    <small v-if="form.errors.email" class="form-error">{{ form.errors.email }}</small>
                </label>

                <label class="form-field">
                    <span>Пароль</span>
                    <input v-model="form.password" type="password" autocomplete="current-password" required placeholder="Введите пароль">
                    <small v-if="form.errors.password" class="form-error">{{ form.errors.password }}</small>
                </label>

                <label class="checkbox-field">
                    <input v-model="form.remember" type="checkbox">
                    <span>Запомнить меня</span>
                </label>

                <button class="primary-button primary-button--wide" type="submit" :disabled="form.processing">
                    Войти
                </button>
            </form>

            <p class="auth-footer">
                Ещё нет аккаунта?
                <Link href="/register">Создать аккаунт</Link>
            </p>
        </div>
    </section>
</template>
