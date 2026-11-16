<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import Paginator from 'primevue/paginator';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import AppDialog from '@/Components/AppDialog.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';
import { usePermission } from '@/composables/usePermission';
import StockCard from './Partials/StockCard.vue';
import './inventory.css';

const props = defineProps({
    products: { type: Object, required: true },
    stats: { type: Object, required: true },
    filters: { type: Object, default: () => ({ search: '', category: null, status: null, sort: 'name' }) },
    categories: { type: Array, default: () => [] },
    canCost: { type: Boolean, default: false },
    allowNegative: { type: Boolean, default: false },
});

const { can } = usePermission();
const { money } = useMoney();

const nf = new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 3 });
const qty = (n) => nf.format(n);

/* ---------- Máy tính: bảng. Máy tính bảng / điện thoại: thẻ (cùng mốc 1024px với thanh bên) ---------- */
const media = window.matchMedia('(min-width: 1024px)');
const isDesktop = ref(media.matches);
const onMedia = (e) => (isDesktop.value = e.matches);
media.addEventListener('change', onMedia);
onBeforeUnmount(() => media.removeEventListener('change', onMedia));

/* ---------- Tìm kiếm, lọc, sắp xếp, phân trang (xử lý ở server) ---------- */
const ALL = 'all'; // PrimeVue Select không hiện lựa chọn có giá trị null
const search = ref(props.filters.search ?? '');
const category = ref(props.filters.category ?? ALL);
const status = ref(props.filters.status ?? null);
const sort = ref(props.filters.sort ?? 'name');

const categoryOptions = computed(() => [{ value: ALL, label: 'Tất cả danh mục' }, ...props.categories.map((c) => ({ value: c.id, label: c.name }))]);
const sortOptions = computed(() => [
    { value: 'name', label: 'Theo tên (A → Z)' },
    { value: 'stock_asc', label: 'Tồn thấp → cao' },
    { value: 'stock_desc', label: 'Tồn cao → thấp' },
    ...(props.canCost ? [{ value: 'value_desc', label: 'Giá trị tồn cao → thấp' }] : []),
]);

const PAGE_KEY = 'pos.perPage'; // dùng chung với các trang danh sách khác
const PAGE_SIZES = [10, 15, 30, 50, 100];
const perPage = ref(props.products.per_page);

const queryParams = (extra = {}) => ({
    per_page: perPage.value,
    search: search.value || undefined,
    category: category.value !== ALL ? category.value : undefined,
    status: status.value || undefined,
    sort: sort.value !== 'name' ? sort.value : undefined,
    ...extra,
});
const visit = (extra = {}) =>
    router.get(route('inventory.index'), queryParams(extra), { preserveState: true, preserveScroll: true, replace: true });

let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => visit(), 300);
});
watch([category, sort], () => visit());
onBeforeUnmount(() => clearTimeout(searchTimer));

// Chip "Tất cả" (null) hoặc bấm lại chip đang chọn thì bỏ lọc
function setStatus(value) {
    status.value = value === null || status.value === value ? null : value;
    visit();
}

function onPage(e) {
    const changed = e.rows !== perPage.value;
    perPage.value = e.rows;
    if (changed) {
        try {
            localStorage.setItem(PAGE_KEY, String(e.rows));
        } catch {
            // trình duyệt chặn lưu trữ: bỏ qua
        }
    }
    visit({ page: changed ? undefined : e.page + 1 });
}
onMounted(() => {
    try {
        const saved = Number(localStorage.getItem(PAGE_KEY));
        if (PAGE_SIZES.includes(saved) && saved !== props.products.per_page) {
            perPage.value = saved;
            visit();
        }
    } catch {
        // bỏ qua
    }
});
const first = computed(() => (props.products.current_page - 1) * props.products.per_page);

const chips = computed(() => [
    { value: null, label: 'Tất cả', count: props.stats.total },
    { value: 'ok', label: 'Còn hàng', count: props.stats.ok },
    { value: 'low', label: 'Sắp hết', count: props.stats.low },
    { value: 'out', label: 'Hết hàng', count: props.stats.out },
]);
const STATUS = {
    ok: { label: 'Còn hàng', severity: 'success' },
    low: { label: 'Sắp hết', severity: 'warn' },
    out: { label: 'Hết hàng', severity: 'danger' },
};

const hasFilter = computed(() => !!(search.value || status.value || category.value !== ALL));
const emptyText = computed(() =>
    props.stats.total === 0 && !hasFilter.value
        ? 'Chưa có sản phẩm nào bật quản lý tồn kho. Vào Sản phẩm, mở sản phẩm cần theo dõi và bật "Quản lý tồn kho".'
        : 'Không có sản phẩm nào khớp với bộ lọc.',
);

/* ---------- Thẻ kho ---------- */
const cardOpen = ref(false);
const cardProduct = ref(null);
function openCard(p) {
    cardProduct.value = { id: p.id, name: p.name };
    cardOpen.value = true;
}

/* ---------- Cài đặt kho ---------- */
const settingsOpen = ref(false);
const settingsForm = useForm({ allow_negative: props.allowNegative });
function openSettings() {
    settingsForm.allow_negative = props.allowNegative;
    settingsForm.clearErrors();
    settingsOpen.value = true;
}
function saveSettings() {
    settingsForm.put(route('inventory.settings'), { preserveScroll: true, onSuccess: () => (settingsOpen.value = false) });
}
</script>

<template>
    <Head title="Tồn kho" />

    <AppLayout title="Tồn kho">
        <div class="app-panel app-fill">
            <div class="app-toolbar">
                <IconField class="iv-search">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="search" placeholder="Tìm theo tên, mã hoặc mã vạch" aria-label="Tìm sản phẩm trong kho" fluid />
                </IconField>
                <Select
                    v-model="category"
                    :options="categoryOptions"
                    optionLabel="label"
                    optionValue="value"
                    aria-label="Lọc theo danh mục"
                    style="min-width: 11rem"
                />
                <Select v-model="sort" :options="sortOptions" optionLabel="label" optionValue="value" aria-label="Sắp xếp" style="min-width: 11.5rem" />
                <Tag v-if="allowNegative" value="Cho phép tồn âm" severity="warn" />
                <Button v-if="can('inventory.receive')" icon="pi pi-download" label="Nhập kho" @click="router.visit(route('inventory.receipts.create'))" />
                <Button
                    v-if="can('inventory.issue')"
                    icon="pi pi-upload"
                    label="Xuất kho"
                    severity="secondary"
                    outlined
                    @click="router.visit(route('inventory.issues.create'))"
                />
                <Button
                    v-if="can('inventory.count')"
                    icon="pi pi-check-square"
                    label="Kiểm kho"
                    severity="secondary"
                    outlined
                    @click="router.visit(route('inventory.counts.create'))"
                />
                <Button
                    v-if="can('inventory.receive')"
                    icon="pi pi-file-excel"
                    label="Nhập Excel"
                    severity="secondary"
                    outlined
                    @click="router.visit(route('inventory.import.index'))"
                />
                <Button
                    v-if="can('inventory.settings')"
                    icon="pi pi-cog"
                    label="Cài đặt"
                    severity="secondary"
                    outlined
                    @click="openSettings"
                />
            </div>

            <div class="iv-stats">
                <div class="iv-chips" role="group" aria-label="Lọc theo tình trạng tồn">
                    <button
                        v-for="chip in chips"
                        :key="chip.label"
                        type="button"
                        class="iv-chip"
                        :class="{ 'is-on': status === chip.value }"
                        :aria-pressed="status === chip.value"
                        @click="setStatus(chip.value)"
                    >
                        {{ chip.label }}
                        <strong>{{ chip.count }}</strong>
                    </button>
                </div>
                <p v-if="canCost" class="iv-total">
                    Giá trị tồn:
                    <strong>{{ money(stats.total_value) }}</strong>
                </p>
            </div>

            <!-- Máy tính: bảng -->
            <DataTable
                v-if="isDesktop"
                :value="products.data"
                dataKey="id"
                lazy
                paginator
                :rowsPerPageOptions="PAGE_SIZES"
                :rows="products.per_page"
                :first="first"
                :totalRecords="products.total"
                @page="onPage"
            >
                <template #empty>{{ emptyText }}</template>

                <Column field="code" header="Mã" style="width: 8rem" />
                <Column header="Sản phẩm">
                    <template #body="{ data }">
                        <button type="button" class="iv-link" @click="openCard(data)">{{ data.name }}</button>
                        <Tag v-if="!data.is_active" value="Ngừng bán" severity="secondary" class="iv-inactive" />
                        <div v-if="data.barcode" class="iv-sub">{{ data.barcode }}</div>
                    </template>
                </Column>
                <Column header="Danh mục">
                    <template #body="{ data }">{{ data.category || '—' }}</template>
                </Column>
                <Column header="Tồn" headerClass="iv-col-right" bodyClass="iv-col-right" style="width: 9rem">
                    <template #body="{ data }">
                        <strong class="iv-qty" :class="{ 'is-out': data.status === 'out' }">{{ qty(data.on_hand) }}</strong>
                        <span class="iv-sub iv-unit">{{ data.unit }}</span>
                    </template>
                </Column>
                <Column header="Tình trạng" style="width: 9rem">
                    <template #body="{ data }">
                        <Tag :value="STATUS[data.status].label" :severity="STATUS[data.status].severity" />
                    </template>
                </Column>
                <Column v-if="canCost" header="Giá vốn" headerClass="iv-col-right" bodyClass="iv-col-right" style="width: 9rem">
                    <template #body="{ data }">{{ money(data.cost_price) }}</template>
                </Column>
                <Column v-if="canCost" header="Giá trị tồn" headerClass="iv-col-right" bodyClass="iv-col-right" style="width: 10rem">
                    <template #body="{ data }">{{ money(data.stock_value) }}</template>
                </Column>
                <Column header="" style="width: 4rem">
                    <template #body="{ data }">
                        <Button icon="pi pi-history" text rounded severity="secondary" :aria-label="`Xem thẻ kho ${data.name}`" @click="openCard(data)" />
                    </template>
                </Column>
            </DataTable>

            <!-- Máy tính bảng, điện thoại: thẻ -->
            <template v-else>
                <div v-if="products.data.length" class="iv-grid">
                    <article v-for="p in products.data" :key="p.id" class="iv-card" @click="openCard(p)">
                        <header class="iv-card-top">
                            <span class="iv-code">{{ p.code }}</span>
                            <Tag :value="STATUS[p.status].label" :severity="STATUS[p.status].severity" />
                        </header>
                        <h3 class="iv-name">{{ p.name }}</h3>
                        <p v-if="p.category" class="iv-sub">{{ p.category }}</p>
                        <div class="iv-card-qty">
                            <span>Tồn</span>
                            <strong :class="{ 'is-out': p.status === 'out' }">{{ qty(p.on_hand) }} {{ p.unit }}</strong>
                        </div>
                        <div v-if="canCost" class="iv-card-qty">
                            <span>Giá trị tồn</span>
                            <strong>{{ money(p.stock_value) }}</strong>
                        </div>
                        <Button label="Xem thẻ kho" icon="pi pi-history" severity="secondary" outlined size="small" @click.stop="openCard(p)" />
                    </article>
                </div>
                <p v-else class="iv-empty">{{ emptyText }}</p>

                <Paginator
                    :first="first"
                    :rows="products.per_page"
                    :rowsPerPageOptions="PAGE_SIZES"
                    :totalRecords="products.total"
                    @page="onPage"
                />
            </template>
        </div>

        <StockCard v-model:visible="cardOpen" :product="cardProduct" :canCost="canCost" />

        <AppDialog v-model:visible="settingsOpen" header="Cài đặt kho" :style="{ width: '27rem' }" :breakpoints="{ '640px': '94vw' }">
            <form novalidate @submit.prevent="saveSettings">
                <div class="field" style="flex-direction: row; align-items: flex-start; gap: 0.6rem">
                    <Checkbox v-model="settingsForm.allow_negative" inputId="iv-neg" binary />
                    <label for="iv-neg">
                        Cho phép bán và xuất quá số tồn (tồn âm)
                        <small class="field-hint" style="display: block; margin-top: 0.25rem">
                            Tắt (khuyên dùng): hệ thống chặn bán khi không đủ hàng. Bật: vẫn bán được và tồn có thể xuống dưới 0, màn bán hàng sẽ
                            cảnh báo. Khi nhập hàng sau đó, giá vốn lấy theo giá nhập mới.
                        </small>
                    </label>
                </div>
                <small v-if="settingsForm.errors.allow_negative" class="field-error">{{ settingsForm.errors.allow_negative }}</small>

                <div class="app-dialog-actions" style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1rem">
                    <Button type="button" label="Hủy" severity="secondary" text @click="settingsOpen = false" />
                    <Button type="submit" label="Lưu" icon="pi pi-check" :loading="settingsForm.processing" />
                </div>
            </form>
        </AppDialog>
    </AppLayout>
</template>
