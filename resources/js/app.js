// document.documentElement.setAttribute("data-theme", "dark");

import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createPinia } from 'pinia';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import GlobalModals from '@/Components/Common/GlobalModals.vue';

import { Capacitor } from '@capacitor/core';
import { SplashScreen } from '@capacitor/splash-screen';
import { StatusBar } from '@capacitor/status-bar';
import { App as CapacitorApp } from '@capacitor/app';
import { InertiaProgress } from '@inertiajs/progress';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

InertiaProgress.init({
    color: '#4B5563',
    showSpinner: true,
});

createInertiaApp({
    title: (title) => `${title} | ${appName}`,
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const pinia = createPinia();
        const vueApp = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(pinia)
            .use(ZiggyVue)
            .component('GlobalModals', GlobalModals);

        vueApp.directive('click-outside', {
            beforeMount(el, binding) {
                el.__ClickOutside__ = (e) => {
                    if (!el.contains(e.target)) binding.value(e);
                };
                document.addEventListener('click', el.__ClickOutside__);
            },
            unmounted(el) {
                document.removeEventListener('click', el.__ClickOutside__);
            },
        });

        vueApp.mount(el);

        if (Capacitor.isNativePlatform()) {
            // Отключаем перекрытие статус-бара
            StatusBar.setOverlaysWebView({ overlay: false }).catch(() => {});

            window.addEventListener('load', async () => {
                try {
                    await SplashScreen.hide();
                } catch (e) {
                    console.warn('SplashScreen.hide() error:', e);
                }
            });

            CapacitorApp.addListener('backButton', () => {
                if (window.history.length > 1) {
                    window.history.back();
                } else {
                    CapacitorApp.exitApp();
                }
            });
        }

        return vueApp;
    },
    progress: {
        color: '#4B5563',
    },
});
