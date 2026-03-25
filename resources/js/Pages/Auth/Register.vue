<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

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
            <div class="auth-card">
                <div class="section-heading section-heading--tight">
                    <div>
                        <span class="eyebrow">Регистрация</span>
                        <h1>Создайте аккаунт</h1>
                    </div>
                </div>

                <form class="form-stack" @submit.prevent="submit">
                    <label class="form-field">
                        <span>Имя</span>
                        <input v-model="form.name" type="text" autocomplete="name" required>
                        <small v-if="form.errors.name" class="form-error">{{ form.errors.name }}</small>
                    </label>

                    <label class="form-field">
                        <span>Email</span>
                        <input v-model="form.email" type="email" autocomplete="email" required>
                        <small v-if="form.errors.email" class="form-error">{{ form.errors.email }}</small>
                    </label>

                    <label class="form-field">
                        <span>Пароль</span>
                        <input v-model="form.password" type="password" autocomplete="new-password" required>
                        <small v-if="form.errors.password" class="form-error">{{ form.errors.password }}</small>
                    </label>

                    <label class="form-field">
                        <span>Подтверждение пароля</span>
                        <input v-model="form.password_confirmation" type="password" autocomplete="new-password" required>
                    </label>

                    <button class="primary-button primary-button--wide" type="submit" :disabled="form.processing">Создать аккаунт</button>
                </form>

                <p class="auth-footer">
                    Уже есть аккаунт?
                    <Link href="/login">Войти</Link>
                </p>
            </div>
        </section>
</template>
