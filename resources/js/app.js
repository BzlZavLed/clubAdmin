import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

import Toast from 'vue-toastification';
import 'vue-toastification/dist/index.css';

const appName = import.meta.env.VITE_APP_NAME || 'Club Management';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) });

        app.use(plugin);
        app.use(ZiggyVue);
        app.use(Toast, {
            position: 'top-right',
            timeout: 3000,
        });

        app.mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
