import './bootstrap';
import '../css/montage.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { InertiaProgress } from '@inertiajs/progress';
import { createPinia } from 'pinia';

const appName = document.title || 'WaveFlow';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: async (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue');
        const page = await pages[`./Pages/${name}.vue`]();
        return page;
    },
    setup({ el, App, props, plugin }) {
        const pinia = createPinia();

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(pinia)
            .mount(el);
    },
});

InertiaProgress.init({
    color: '#8b5cf6',
    showSpinner: false,
});

// The offline page is self-contained; only the SW needs to be registered here.
if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Browsers that block service workers retain normal online playback.
        });
    });
}
