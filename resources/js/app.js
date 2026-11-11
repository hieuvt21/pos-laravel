import '../css/ui.css';
import 'primeicons/primeicons.css';
import './composables/useUiSettings'; // áp dụng ảnh nền đã lưu ngay khi mở app
import { getBrandName, setBrandName } from './composables/useBranding';

import { createInertiaApp } from '@inertiajs/vue3';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

import PrimeVue from 'primevue/config';
import ToastService from 'primevue/toastservice';
import ConfirmationService from 'primevue/confirmationservice';
import { definePreset } from '@primeuix/themes';
import Aura from '@primeuix/themes/aura';

// Aura với màu chủ đạo teal. Nút/điểm nhấn dùng sắc đậm hơn để chữ trắng đủ tương phản.
// Màu thật do cài đặt "Giao diện" của cửa hàng quyết định (xem composables/useBranding.js).
const Pos = definePreset(Aura, {
    semantic: {
        primary: {
            50: '{teal.50}',
            100: '{teal.100}',
            200: '{teal.200}',
            300: '{teal.300}',
            400: '{teal.400}',
            500: '{teal.500}',
            600: '{teal.600}',
            700: '{teal.700}',
            800: '{teal.800}',
            900: '{teal.900}',
            950: '{teal.950}',
        },
        colorScheme: {
            light: {
                primary: {
                    color: '{primary.700}',
                    inverseColor: '#ffffff',
                    hoverColor: '{primary.800}',
                    activeColor: '{primary.900}',
                },
            },
        },
    },
});

/*
 * Tìm trang Vue ở 2 nơi:
 *  - resources/js/Pages/**                                  (trang chung, nếu có)
 *  - app/Modules/<Module>/Resources/js/Pages/**             (trang của từng module)
 * Tên trang = phần sau "Pages/", ví dụ Inertia::render('Users/Index')
 * -> app/Modules/Core/Resources/js/Pages/Users/Index.vue
 * Tên trang phải duy nhất giữa các module.
 */
const pages = import.meta.glob([
    './Pages/**/*.vue',
    '../../app/Modules/*/Resources/js/Pages/**/*.vue',
]);

const pageMap = Object.fromEntries(
    Object.entries(pages).map(([path, loader]) => [
        path.replace(/^.*\/Pages\//, '').replace(/\.vue$/, ''),
        loader,
    ]),
);

createInertiaApp({
    // Tiêu đề tab: "Trang - Tên cửa hàng" (tên lấy từ cài đặt Giao diện)
    title: (title) => (title ? `${title} - ${getBrandName()}` : getBrandName()),
    resolve: (name) => {
        const loader = pageMap[name];
        if (!loader) throw new Error(`Không tìm thấy trang Vue: "${name}"`);
        return loader();
    },
    setup({ el, App, props, plugin }) {
        setBrandName(props.initialPage?.props?.branding?.name);

        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(PrimeVue, {
                theme: {
                    preset: Pos,
                    options: {
                        darkModeSelector: false,
                        // Để class Tailwind ghi đè được PrimeVue (khớp thứ tự @layer trong app.css)
                        cssLayer: { name: 'primevue', order: 'theme, base, primevue' },
                    },
                },
            })
            .use(ToastService)
            .use(ConfirmationService)
            .mount(el);
    },
    progress: { color: '#0F766E' },
});
