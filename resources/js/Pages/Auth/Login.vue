<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const form = useForm({
    email: 'demo@waveflow.local',
    password: 'password',
    remember: true,
});

const submit = () => {
    form.post('/login');
};
</script>

<template>
        <Head title="Войти" />

        <section class="auth-shell">
            <div class="auth-card">
                <div class="section-heading section-heading--tight">
                    <div>
                        <span class="eyebrow">Авторизация</span>
                        <h1>Войти в WaveFlow</h1>
                    </div>
                </div>

                <form class="form-stack" @submit.prevent="submit">
                    <label class="form-field">
                        <span>Email</span>
                        <input v-model="form.email" type="email" autocomplete="email" required>
                        <small v-if="form.errors.email" class="form-error">{{ form.errors.email }}</small>
                    </label>

                    <label class="form-field">
                        <span>Пароль</span>
                        <input v-model="form.password" type="password" autocomplete="current-password" required>
                        <small v-if="form.errors.password" class="form-error">{{ form.errors.password }}</small>
                    </label>

                    <label class="checkbox-field">
                        <input v-model="form.remember" type="checkbox">
                        <span>Запомнить меня</span>
                    </label>

                    <button class="primary-button primary-button--wide" type="submit" :disabled="form.processing">Войти</button>
                </form>

                <p class="auth-footer">
                    Ещё нет аккаунта?
                    <Link href="/register">Создать</Link>
                </p>
            </div>
        </section>
</template>
