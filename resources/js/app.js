document.documentElement.setAttribute("data-theme", "dark");

import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createPinia } from 'pinia'
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import GlobalModals from '@/Components/Common/GlobalModals.vue'

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const pinia = createPinia()
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(pinia)
            .use(ZiggyVue)
            .component('GlobalModals', GlobalModals)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
