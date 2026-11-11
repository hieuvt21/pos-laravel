<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import BrandMark from '@/Components/BrandMark.vue';
import { useBranding } from '@/composables/useBranding';
import { useNavigation } from '@/composables/useNavigation';
import { useUiSettings } from '@/composables/useUiSettings';

defineProps({ pinnable: { type: Boolean, default: false } });
defineEmits(['navigate']);

const { groups, isActive } = useNavigation();
const { branding } = useBranding();
const { settings, toggleSidebarMode } = useUiSettings();

const pinned = computed(() => settings.sidebarMode === 'pinned');
const pinTitle = computed(() =>
    pinned.value ? 'Đang ghim. Bấm để thu gọn, tự mở khi rê chuột' : 'Bấm để ghim thanh bên luôn mở',
);
</script>

<template>
    <nav class="app-nav" aria-label="Menu chính">
        <div class="app-brandrow">
            <Link :href="route('home')" class="app-brand" @click="$emit('navigate')">
                <BrandMark :logo="branding.logo" :name="branding.name" />
                <span class="app-label app-brand-name">{{ branding.name }}</span>
            </Link>
            <button
                v-if="pinnable"
                type="button"
                class="app-pin app-label"
                :aria-pressed="pinned"
                :title="pinTitle"
                :aria-label="pinTitle"
                @click="toggleSidebarMode"
            >
                <i class="pi pi-thumbtack" aria-hidden="true" />
            </button>
        </div>

        <div v-for="group in groups" :key="group.label" class="app-group">
            <p class="app-group-label">{{ group.label }}</p>
            <Link
                v-for="item in group.items"
                :key="item.route"
                :href="route(item.route)"
                class="app-link"
                :class="{ 'is-active': isActive(item) }"
                :aria-current="isActive(item) ? 'page' : undefined"
                :title="item.label"
                @click="$emit('navigate')"
            >
                <i :class="item.icon" aria-hidden="true" />
                <span class="app-label">{{ item.label }}</span>
            </Link>
        </div>
    </nav>
</template>
