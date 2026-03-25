<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const page = usePage();
const appName = computed(() => page.props.appName ?? 'Музыка');

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post('/register');
};
</script>

<template>
    <Head title="Регистрация" />

    <section class="auth-shell">
        <div class="auth-panel">
            <span class="eyebrow">Новый аккаунт</span>
            <h1>Создайте профиль в {{ appName }}</h1>
            <p>
                Сохраняйте любимые треки, собирайте удобную очередь и возвращайтесь к музыке без лишних действий.
            </p>
        </div>

        <div class="auth-card">
            <div class="auth-card__header">
                <span class="eyebrow">Регистрация</span>
                <h2>Создание аккаунта</h2>
                <p class="section-description">Укажите данные профиля, чтобы открыть доступ к своей медиатеке.</p>
            </div>

            <form class="form-stack" @submit.prevent="submit">
                <label class="form-field">
                    <span>Имя</span>
                    <input v-model="form.name" type="text" autocomplete="name" required placeholder="Как к вам обращаться">
                    <small v-if="form.errors.name" class="form-error">{{ form.errors.name }}</small>
                </label>

                <label class="form-field">
                    <span>Email</span>
                    <input v-model="form.email" type="email" autocomplete="email" required placeholder="you@example.com">
                    <small v-if="form.errors.email" class="form-error">{{ form.errors.email }}</small>
                </label>

                <label class="form-field">
                    <span>Пароль</span>
                    <input v-model="form.password" type="password" autocomplete="new-password" required placeholder="Не менее 8 символов">
                    <small v-if="form.errors.password" class="form-error">{{ form.errors.password }}</small>
                </label>

                <label class="form-field">
                    <span>Подтверждение пароля</span>
                    <input
                        v-model="form.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        required
                        placeholder="Повторите пароль"
                    >
                </label>

                <button class="primary-button primary-button--wide" type="submit" :disabled="form.processing">
                    Создать аккаунт
                </button>
            </form>

            <p class="auth-footer">
                Уже есть аккаунт?
                <Link href="/login">Войти</Link>
            </p>
        </div>
    </section>
</template>
