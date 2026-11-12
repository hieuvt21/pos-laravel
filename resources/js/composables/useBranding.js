import { computed, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const SHADES = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];
const FALLBACK = { name: 'Pos', theme: 'teal', theme_custom: '#0f766e', logo: { type: 'icon', icon: 'letter', image_url: null } };

// ---- Tên cửa hàng cho tiêu đề tab ----
let brandName = import.meta.env.VITE_APP_NAME || 'Pos';
export const getBrandName = () => brandName;
export const setBrandName = (name) => {
    if (name) brandName = name;
};

// ---- Màu chủ đề ----
// Đổi màu chủ đạo của toàn bộ giao diện (PrimeVue + CSS riêng) bằng cách trỏ
// các biến --p-primary-* sang bảng màu được chọn.
// Màu tự chọn: mã màu người dùng nhập chính là sắc 700 (màu nút bấm); các sắc nhạt hơn pha thêm trắng,
// sắc đậm hơn pha thêm đen.
const HEX = /^#[0-9a-f]{6}$/i;
const LIGHT = { 50: 0.08, 100: 0.16, 200: 0.32, 300: 0.5, 400: 0.7, 500: 0.85, 600: 0.93 };
const DARK = { 800: 0.12, 900: 0.26, 950: 0.4 };

const toRgb = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
const toHex = (rgb) => `#${rgb.map((v) => Math.round(v).toString(16).padStart(2, '0')).join('')}`;
const mix = (hex, target, amount) => toHex(toRgb(hex).map((v, i) => v + (target[i] - v) * amount));

export function makePalette(hex) {
    const base = hex.toLowerCase();
    const palette = { 700: base };
    for (const [shade, keep] of Object.entries(LIGHT)) palette[shade] = mix(base, [255, 255, 255], 1 - keep);
    for (const [shade, black] of Object.entries(DARK)) palette[shade] = mix(base, [0, 0, 0], black);
    return palette;
}

// Chữ trắng trên màu này có đủ rõ không (tỉ lệ tương phản WCAG >= 4.5)?
export function isTooLight(hex) {
    if (!HEX.test(hex)) return false;
    const [r, g, b] = toRgb(hex).map((v) => {
        const c = v / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });
    const luminance = 0.2126 * r + 0.7152 * g + 0.0722 * b;
    return 1.05 / (luminance + 0.05) < 4.5;
}

export function applyTheme(key, custom) {
    const root = document.documentElement;

    if (key === 'custom' && HEX.test(custom ?? '')) {
        const palette = makePalette(custom);
        SHADES.forEach((shade) => root.style.setProperty(`--p-primary-${shade}`, palette[shade]));
        return;
    }

    const name = key && key !== 'custom' ? key : 'teal';
    SHADES.forEach((shade) => root.style.setProperty(`--p-primary-${shade}`, `var(--p-${name}-${shade})`));
}

// ---- Favicon theo logo ----
let faviconKey = '';

const cssVar = (name, fallback) =>
    getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;

// Vẽ favicon từ logo dạng chữ cái / biểu tượng: nền màu chủ đề + ký hiệu trắng
async function drawFavicon(branding) {
    const size = 64;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');

    const gradient = ctx.createLinearGradient(0, 0, size, size);
    gradient.addColorStop(0, cssVar('--p-primary-500', '#14b8a6'));
    gradient.addColorStop(1, cssVar('--p-primary-700', '#0f766e'));
    ctx.fillStyle = gradient;
    ctx.beginPath();
    ctx.roundRect(0, 0, size, size, 15);
    ctx.fill();

    ctx.fillStyle = '#ffffff';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';

    const icon = branding.logo?.icon;
    if (icon && icon !== 'letter') {
        // Lấy đúng ký tự của biểu tượng PrimeIcons từ CSS
        const probe = document.createElement('i');
        probe.className = `pi ${icon}`;
        probe.style.cssText = 'position:absolute;visibility:hidden';
        document.body.appendChild(probe);
        const glyph = getComputedStyle(probe, '::before').content.replace(/["']/g, '');
        probe.remove();

        await document.fonts.load('36px primeicons');
        ctx.font = '36px primeicons';
        ctx.fillText(glyph, size / 2, size / 2 + 2);
    } else {
        await document.fonts.load('700 38px "Be Vietnam Pro"');
        ctx.font = '700 38px "Be Vietnam Pro", system-ui, sans-serif';
        ctx.fillText((branding.name || 'A').trim().charAt(0).toUpperCase() || 'A', size / 2, size / 2 + 3);
    }

    return canvas.toDataURL('image/png');
}

async function updateFavicon(branding) {
    const key = JSON.stringify([branding.name, branding.theme, branding.theme_custom, branding.logo]);
    if (key === faviconKey) return;
    faviconKey = key;

    try {
        const useImage = branding.logo?.type === 'image' && branding.logo?.image_url;
        const href = useImage ? branding.logo.image_url : await drawFavicon(branding);
        if (key !== faviconKey) return; // logo đã đổi trong lúc đang vẽ

        let link = document.querySelector('link[rel~="icon"]');
        if (!link) {
            link = document.createElement('link');
            link.rel = 'icon';
            document.head.appendChild(link);
        }
        link.removeAttribute('type');
        link.href = href;
    } catch {
        faviconKey = ''; // thử lại ở lần sau
    }
}

export function useBranding() {
    const page = usePage();
    const branding = computed(() => page.props.branding ?? FALLBACK);

    watch(() => [branding.value.theme, branding.value.theme_custom], ([theme, custom]) => applyTheme(theme, custom), { immediate: true });

    // Tên cửa hàng đổi -> cập nhật luôn tiêu đề tab đang hiển thị
    watch(
        () => branding.value.name,
        (name, old) => {
            setBrandName(name);
            if (old && old !== name) {
                if (document.title === old) document.title = name;
                else if (document.title.endsWith(` - ${old}`)) document.title = document.title.slice(0, -old.length) + name;
            }
        },
        { immediate: true },
    );

    watch(
        () => [branding.value.name, branding.value.theme, branding.value.theme_custom, branding.value.logo],
        () => updateFavicon(branding.value),
        { immediate: true, deep: true },
    );

    return { branding };
}
