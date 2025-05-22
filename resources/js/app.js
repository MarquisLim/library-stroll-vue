// document.documentElement.setAttribute("data-theme", "dark");

import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createPinia } from 'pinia'
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import GlobalModals from '@/Components/Common/GlobalModals.vue'
import { Capacitor } from '@capacitor/core'
import { StatusBar } from '@capacitor/status-bar'
import { InertiaProgress } from '@inertiajs/progress'

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

InertiaProgress.init({
    // цвет полоски
    color: '#4B5563',
    // показывать спиннер справа
    showSpinner: true,
    // можно задать задержку в мс, по умолчанию 250
    // delay: 0,
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const pinia = createPinia()
        const vueApp = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(pinia)
            .use(ZiggyVue)
            .component('GlobalModals', GlobalModals)

        vueApp.mount(el)

        if (Capacitor.isNativePlatform()) {
            StatusBar.setOverlaysWebView({ overlay: false }).catch(() => {})
        }

        return vueApp
    },
    progress: {
        color: '#4B5563',
    },
});
