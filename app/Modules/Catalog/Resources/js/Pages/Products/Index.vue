<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Paginator from 'primevue/paginator';
import Popover from 'primevue/popover';
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import Tag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import { useConfirm } from 'primevue/useconfirm';
import AppDialog from '@/Components/AppDialog.vue';
import FieldStatus from '@/Components/FieldStatus.vue';
import ProductDetail from './Partials/ProductDetail.vue';
import ImageInput from '@/Components/ImageInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { usePermission } from '@/composables/usePermission';
import { useMoney } from '@/composables/useMoney';
import { useUniqueCheck } from '@/composables/useUniqueCheck';

const props = defineProps({
    products: { type: Object, required: true },
    filters: { type: Object, default: () => ({ search: '', category: null, status: null }) },
    categories: { type: Array, default: () => [] },
    units: { type: Array, default: () => [] },
    priceLists: { type: Array, default: () => [] },
});

const { can } = usePermission();
const { money, config } = useMoney();
const confirm = useConfirm();

/* ---------- Tìm kiếm, lọc, phân trang (xử lý ở server) ---------- */
const ALL = 'all'; // PrimeVue Select không hiện lựa chọn có giá trị null
const search = ref(props.filters.search ?? '');
const PAGE_KEY = 'pos.perPage';
const PAGE_SIZES = [10, 15, 30, 50, 100];
const perPage = ref(props.products.per_page);
const categoryFilter = ref(props.filters.category ?? ALL);
const statusFilter = ref(props.filters.status ?? ALL);

const categoryFilterOptions = computed(() => [
    { value: ALL, label: 'Tất cả danh mục' },
    ...props.categories.map((c) => ({ value: String(c.id), label: c.name })),
]);
const statusOptions = [
    { value: ALL, label: 'Mọi trạng thái' },
    { value: 'active', label: 'Đang bán' },
    { value: 'inactive', label: 'Ngừng bán' },
];

const visit = (extra = {}) =>
    router.get(
        route('products.index'),
        {
            search: search.value || undefined,
            category: categoryFilter.value !== ALL ? categoryFilter.value : undefined,
            status: statusFilter.value !== ALL ? statusFilter.value : undefined,
            per_page: perPage.value,
            ...extra,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );

let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => visit(), 300);
});
watch([categoryFilter, statusFilter], () => visit());
onBeforeUnmount(() => clearTimeout(searchTimer));

// Đổi số dòng mỗi trang thì quay về trang 1 và nhớ lựa chọn cho lần sau
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

/* ---------- Kiểu hiển thị: máy tính chọn Bảng hoặc Thẻ (nhớ lại), tablet/điện thoại luôn là Thẻ ---------- */
const VIEW_KEY = 'pos.products.view';
const loadView = () => {
    try {
        return localStorage.getItem(VIEW_KEY) === 'card' ? 'card' : 'table';
    } catch {
        return 'table';
    }
};
const view = ref(loadView());
watch(view, (v) => {
    try {
        localStorage.setItem(VIEW_KEY, v);
    } catch {
        // trình duyệt chặn lưu trữ: bỏ qua
    }
});
const viewOptions = [
    { value: 'table', icon: 'pi pi-table', label: 'Dạng bảng' },
    { value: 'card', icon: 'pi pi-th-large', label: 'Dạng thẻ' },
];
const media = window.matchMedia('(min-width: 1024px)');
const isDesktop = ref(media.matches);
const onMedia = (e) => (isDesktop.value = e.matches);
media.addEventListener('change', onMedia);
onBeforeUnmount(() => media.removeEventListener('change', onMedia));
const showTable = computed(() => isDesktop.value && view.value === 'table');

/* ---------- Form thêm / sửa ---------- */
const dialog = ref(false);
const editing = ref(null);

const form = useForm({
    code: '',
    name: '',
    barcode: '',
    category_id: null,
    unit_id: null,
    tax_rate: 0,
    tax_enabled: false, // chỉ để bật/tắt ô thuế; không gửi lên server
    description: '',
    is_active: true,
    image_file: null,
    remove_image: false,
    retail_price: null, // giá bán lẻ (bảng giá mặc định)
    qty_pricing: false, // bật "Giá theo số lượng"
    tiers: {}, // { [price_list_id]: { min_qty, price } } cho các bảng giá khác
    track_stock: false,
    cost_price: null,
});

// Thuế khai báo riêng cho từng sản phẩm, mặc định tắt (độc lập với công tắc thuế chung ở trang Cửa hàng)
const defaultTaxRate = computed(() => Number(config.value.tax_default_rate) || 10);
const taxOptions = computed(() => {
    const rates = new Set((config.value.tax_rates ?? []).map(Number).filter((r) => r > 0));
    if (form.tax_rate > 0) rates.add(Number(form.tax_rate)); // giữ mức thuế cũ của sản phẩm dù đã bỏ khỏi danh sách
    return [...rates].sort((a, b) => a - b).map((r) => ({ label: `${r}%`, value: r }));
});
watch(
    () => form.tax_enabled,
    (on) => {
        form.clearErrors('tax_rate');
        form.tax_rate = on ? form.tax_rate || defaultTaxRate.value : 0;
    },
);
const defaultList = computed(() => props.priceLists.find((l) => l.is_default));
const tierLists = computed(() => props.priceLists.filter((l) => l.is_active && !l.is_default));
const categoryOptions = computed(() => props.categories.filter((c) => c.is_active || c.id === form.category_id));
const unitOptions = computed(() => props.units.filter((u) => u.is_active || u.id === form.unit_id));
const defaultUnitId = computed(() => props.units.find((u) => u.is_default && u.is_active)?.id ?? null);
const unitName = computed(() => props.units.find((u) => u.id === form.unit_id)?.name ?? 'đơn vị tính');
const fractionDigits = computed(() => Number(config.value.decimals) || 0);
const err = (key) => form.errors[key];

// Kiểm tra trùng mã / mã vạch ngay khi nhập (khi sửa: bỏ qua chính sản phẩm đang sửa)
const checkOptions = (key) => ({
    ignoreId: () => editing.value?.id ?? null,
    original: () => editing.value?.[key] ?? null,
    url: () => route('products.check'),
});
const codeCheck = useUniqueCheck('code', () => form.code, checkOptions('code'));
const barcodeCheck = useUniqueCheck('barcode', () => form.barcode, checkOptions('barcode'));
const nameCheck = useUniqueCheck('name', () => form.name, checkOptions('name'));
const hasConflict = computed(() => [nameCheck, codeCheck, barcodeCheck].some((c) => ['taken', 'invalid'].includes(c.status)));

// Xóa lỗi cũ của ô vừa sửa
['code', 'name', 'barcode', 'tax_rate', 'category_id', 'unit_id'].forEach((f) => watch(() => form[f], () => form.clearErrors(f)));

const emptyTiers = () => Object.fromEntries(tierLists.value.map((l) => [l.id, { min_qty: null, price: null }]));

// Lỗi của dòng giá theo chỉ số trong danh sách gửi lên server (bảng giá mặc định đứng đầu)
const tierIndex = (listId) => 1 + tierLists.value.findIndex((l) => l.id === listId);

// Mỗi bảng giá một mức số lượng riêng: báo trùng ngay khi nhập
const dupQty = (listId) => {
    if (!form.qty_pricing) return false;
    const q = form.tiers[listId]?.min_qty;
    if (q === null || q === undefined || q === '') return false;
    return tierLists.value.some((l) => l.id !== listId && form.tiers[l.id]?.price != null && Number(form.tiers[l.id]?.min_qty) === Number(q) && form.tiers[listId]?.price != null);
};
const qtyConflict = computed(() => form.qty_pricing && tierLists.value.some((l) => dupQty(l.id)));

function resetChecks() {
    nameCheck.reset();
    codeCheck.reset();
    barcodeCheck.reset();
}

function openCreate() {
    editing.value = null;
    resetChecks();
    form.reset();
    form.clearErrors();
    form.tax_rate = 0;
    form.tax_enabled = false;
    form.unit_id = defaultUnitId.value;
    form.tiers = emptyTiers();
    dialog.value = true;
}

function openEdit(p) {
    detailOpen.value = false;
    editing.value = p;
    resetChecks();
    form.clearErrors();
    form.code = p.code;
    form.name = p.name;
    form.barcode = p.barcode ?? '';
    form.category_id = p.category_id;
    form.unit_id = p.unit_id;
    form.tax_rate = Number(p.tax_rate);
    form.tax_enabled = Number(p.tax_rate) > 0;
    form.description = p.description ?? '';
    form.is_active = p.is_active;
    form.image_file = null;
    form.remove_image = false;
    form.qty_pricing = p.qty_pricing;
    form.track_stock = p.track_stock;
    form.cost_price = p.cost_price ? Number(p.cost_price) : null;

    form.retail_price = p.prices.find((x) => x.price_list_id === defaultList.value?.id)?.price ?? null;
    const tiers = emptyTiers();
    p.prices.forEach((x) => {
        if (tiers[x.price_list_id]) tiers[x.price_list_id] = { min_qty: x.min_qty, price: x.price };
    });
    form.tiers = tiers;
    dialog.value = true;
}

function submit() {
    if (hasConflict.value || qtyConflict.value) return;

    form.transform((data) => {
        const payload = {
            code: data.code,
            name: data.name,
            barcode: data.barcode,
            category_id: data.category_id,
            unit_id: data.unit_id,
            tax_rate: data.tax_enabled ? data.tax_rate : 0,
            description: data.description,
            is_active: data.is_active,
            qty_pricing: data.qty_pricing,
            track_stock: data.track_stock,
            cost_price: data.cost_price ?? 0,
            // Bảng giá mặc định (bán lẻ) đứng đầu, sau đó là các bảng giá khác; số lượng chỉ gửi khi bật tự động chuyển giá
            prices: [
                { price_list_id: defaultList.value?.id, price: data.retail_price },
                ...tierLists.value.map((l) => ({
                    price_list_id: l.id,
                    price: data.tiers[l.id]?.price,
                    ...(data.qty_pricing ? { min_qty: data.tiers[l.id]?.min_qty } : {}),
                })),
            ],
        };
        if (data.image_file) payload.image_file = data.image_file;
        if (data.remove_image) payload.remove_image = true;
        return editing.value ? { ...payload, _method: 'put' } : payload;
    });

    const options = {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            dialog.value = false;
            form.reset();
        },
    };

    if (editing.value) {
        form.post(route('products.update', editing.value.id), options);
    } else {
        form.post(route('products.store'), options);
    }
}

function remove(p) {
    confirm.require({
        header: 'Xóa sản phẩm',
        message: `Xóa sản phẩm "${p.name}"? Thao tác này không thể hoàn tác.`,
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Hủy',
        acceptLabel: 'Xóa',
        rejectProps: { severity: 'secondary', outlined: true },
        acceptProps: { severity: 'danger' },
        accept: () => {
            detailOpen.value = false;
            router.delete(route('products.destroy', p.id), { preserveScroll: true });
        },
    });
}

/* ---------- Xem chi tiết, sao chép, ngừng bán ---------- */
const detailOpen = ref(false);
const detailId = ref(null);
const detailProduct = computed(() => props.products.data.find((p) => p.id === detailId.value) ?? null);
function openDetail(p) {
    detailId.value = p.id;
    detailOpen.value = true;
}
const toggleActive = (p) => router.patch(route('products.toggle', p.id), {}, { preserveScroll: true });

// Sao chép: mở form thêm mới với dữ liệu của sản phẩm này; mã, mã vạch, ảnh để trống
function duplicate(p) {
    detailOpen.value = false;
    openCreate();
    form.name = `${p.name} - Bản sao`;
    form.category_id = p.category_id;
    form.unit_id = p.unit_id;
    form.tax_rate = Number(p.tax_rate);
    form.tax_enabled = Number(p.tax_rate) > 0;
    form.description = p.description ?? '';
    form.is_active = p.is_active;
    form.qty_pricing = p.qty_pricing;
    form.track_stock = p.track_stock;
    form.cost_price = p.cost_price ? Number(p.cost_price) : null;
    form.retail_price = p.prices.find((x) => x.price_list_id === defaultList.value?.id)?.price ?? null;
    const tiers = emptyTiers();
    p.prices.forEach((x) => {
        if (tiers[x.price_list_id]) tiers[x.price_list_id] = { min_qty: x.min_qty, price: x.price };
    });
    form.tiers = tiers;
}

/* ---------- Xem giá bằng cách chạm (thiết bị cảm ứng không có hover) ---------- */
const pricePop = ref();
const popProduct = ref(null);
const popRows = computed(() => {
    const p = popProduct.value;
    if (!p) return [];
    return props.priceLists
        .map((l) => ({ l, row: p.prices.find((x) => x.price_list_id === l.id) }))
        .filter(({ row }) => row && Number(row.price) > 0)
        .map(({ l, row }) => ({
            id: l.id,
            name: l.name,
            price: row.price,
            from: !l.is_default && p.qty_pricing && row.min_qty ? Number(row.min_qty) : null,
        }));
});
function showPrices(event, p) {
    popProduct.value = p;
    pricePop.value.toggle(event);
}

/* ---------- Hiển thị bảng ---------- */
// Giá bán lẻ = giá ở bảng giá mặc định
const retailPrice = (p) => (defaultList.value ? p.prices.find((x) => x.price_list_id === defaultList.value.id)?.price ?? null : null);
const hasOtherPrices = (p) => p.prices.some((x) => x.price_list_id !== defaultList.value?.id && Number(x.price) > 0);
const stockText = (p) => (p.track_stock ? Number(p.stock ?? 0).toLocaleString('vi-VN') : '-');
const subline = (p) => [p.code, p.category, p.unit].filter(Boolean).join(' · ');
</script>

<template>
    <Head title="Sản phẩm" />

    <AppLayout title="Sản phẩm">
        <div class="app-panel app-fill">
            <div class="app-toolbar">
                <IconField class="app-search">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="search" placeholder="Tìm theo tên, mã hoặc mã vạch" aria-label="Tìm sản phẩm" fluid />
                </IconField>
                <Select
                    v-model="categoryFilter"
                    :options="categoryFilterOptions"
                    optionLabel="label"
                    optionValue="value"
                    aria-label="Lọc theo danh mục"
                    class="pr-filter"
                />
                <Select
                    v-model="statusFilter"
                    :options="statusOptions"
                    optionLabel="label"
                    optionValue="value"
                    aria-label="Lọc theo trạng thái"
                    class="pr-filter"
                />
                <SelectButton
                    v-if="isDesktop"
                    v-model="view"
                    :options="viewOptions"
                    optionValue="value"
                    :allowEmpty="false"
                    aria-label="Kiểu hiển thị"
                >
                    <template #option="{ option }">
                        <i :class="option.icon" aria-hidden="true" :title="option.label" />
                        <span class="sr-only">{{ option.label }}</span>
                    </template>
                </SelectButton>
                <Button v-if="can('products.create')" label="Thêm sản phẩm" icon="pi pi-plus" @click="openCreate" />
            </div>

            <DataTable
                v-if="showTable"
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
                <template #empty>Chưa có sản phẩm nào khớp với tìm kiếm.</template>

                <Column field="name" header="Sản phẩm">
                    <template #body="{ data }">
                        <div class="pr-name">
                            <img v-if="data.image_url" :src="data.image_url" alt="" class="pr-thumb" />
                            <span v-else class="pr-thumb pr-thumb--empty pi pi-image" aria-hidden="true" />
                            <div class="pr-name-text">
                                <button type="button" class="pr-link" @click="openDetail(data)">{{ data.name }}</button>
                                <small class="pr-sub">{{ subline(data) }}</small>
                            </div>
                        </div>
                    </template>
                </Column>
                <Column field="code" header="Mã" headerClass="pr-hide-md" bodyClass="pr-hide-md" />
                <Column field="barcode" header="Mã vạch" headerClass="pr-hide-md" bodyClass="pr-hide-md">
                    <template #body="{ data }">{{ data.barcode || '—' }}</template>
                </Column>
                <Column field="category" header="Danh mục" headerClass="pr-hide-md" bodyClass="pr-hide-md">
                    <template #body="{ data }">{{ data.category || '—' }}</template>
                </Column>
                <Column field="unit" header="Đơn vị" headerClass="pr-hide-md" bodyClass="pr-hide-md">
                    <template #body="{ data }">{{ data.unit || '—' }}</template>
                </Column>
                <Column header="Giá bán" headerClass="pr-col-right pr-price-head" bodyClass="pr-col-right">
                    <template #body="{ data }">
                        <div class="pr-pricecell">
                            <span class="pr-pricetext">{{ retailPrice(data) !== null ? money(retailPrice(data)) : '—' }}</span>
                            <button
                                v-if="hasOtherPrices(data)"
                                type="button"
                                class="pr-info"
                                aria-label="Xem các loại giá khác"
                                @click.stop="showPrices($event, data)"
                            >
                                <i class="pi pi-info-circle" aria-hidden="true" />
                            </button>
                            <span v-else class="pr-info pr-info--none" aria-hidden="true" />
                        </div>
                    </template>
                </Column>
                <Column field="description" header="Ghi chú" headerClass="pr-hide-md" bodyClass="pr-hide-md" style="max-width: 14rem">
                    <template #body="{ data }">
                        <span v-if="data.description" class="pr-note-cell" :title="data.description">{{ data.description }}</span>
                        <span v-else>—</span>
                    </template>
                </Column>
                <Column header="Trạng thái" headerClass="pr-hide-sm" bodyClass="pr-hide-sm" style="width: 8rem">
                    <template #body="{ data }">
                        <Tag :value="data.is_active ? 'Đang bán' : 'Ngừng bán'" :severity="data.is_active ? 'success' : 'secondary'" />
                    </template>
                </Column>
                <Column header="" style="width: 7rem">
                    <template #body="{ data }">
                        <div class="pr-actions">
                            <Button v-if="can('products.update')" icon="pi pi-pencil" text rounded severity="secondary" aria-label="Sửa" @click="openEdit(data)" />
                            <Button v-if="can('products.delete')" icon="pi pi-trash" text rounded severity="danger" aria-label="Xóa" @click="remove(data)" />
                        </div>
                    </template>
                </Column>
            </DataTable>

            <!-- Dạng thẻ (máy tính chọn Thẻ; máy tính bảng và điện thoại luôn dùng) -->
            <template v-else>
                <div v-if="products.data.length" class="pr-cards">
                    <article v-for="p in products.data" :key="p.id" class="pr-card" @click="openDetail(p)">
                        <header class="pr-top">
                            <span class="pr-code">{{ p.code }}</span>
                            <div v-if="can('products.update') || can('products.delete')" class="pr-actions">
                                <Button v-if="can('products.update')" icon="pi pi-pencil" text rounded size="small" severity="secondary" :aria-label="`Sửa ${p.name}`" @click.stop="openEdit(p)" />
                                <Button v-if="can('products.delete')" icon="pi pi-trash" text rounded size="small" severity="danger" :aria-label="`Xóa ${p.name}`" @click.stop="remove(p)" />
                            </div>
                        </header>

                        <div class="pr-main">
                            <div class="pr-who">
                                <img v-if="p.image_url" :src="p.image_url" alt="" class="pr-card-img" />
                                <span v-else class="pr-card-img pr-thumb--empty pi pi-image" aria-hidden="true" />
                                <span
                                    class="pr-dot"
                                    :class="p.is_active ? 'is-on' : 'is-off'"
                                    role="img"
                                    :aria-label="p.is_active ? 'Đang bán' : 'Ngừng bán'"
                                    :title="p.is_active ? 'Đang bán' : 'Ngừng bán'"
                                />
                            </div>
                            <div class="pr-lines">
                                <h3 class="pr-card-name"><button type="button" class="pr-link" @click.stop="openDetail(p)">{{ p.name }}</button></h3>
                                <p class="pr-line"><i class="pi pi-tag" aria-hidden="true" /><span class="sr-only">Danh mục:</span><span>{{ p.category || '-' }}</span></p>
                                <p class="pr-line"><i class="pi pi-box" aria-hidden="true" /><span class="sr-only">Đơn vị:</span><span>{{ p.unit || '-' }}</span></p>
                                <p class="pr-line"><i class="pi pi-warehouse" aria-hidden="true" /><span class="sr-only">Tồn kho:</span><span>{{ stockText(p) }}</span></p>
                            </div>
                        </div>

                        <p v-if="p.description" class="pr-note">
                            <i class="pi pi-comment" aria-hidden="true" />
                            <span class="sr-only">Ghi chú:</span>
                            <span>{{ p.description }}</span>
                        </p>

                        <footer class="pr-price">
                            <span>Giá bán <Tag v-if="p.qty_pricing" value="Theo SL" severity="info" /></span>
                            <div class="pr-pricecell">
                                <strong>{{ retailPrice(p) !== null ? money(retailPrice(p)) : '—' }}</strong>
                                <button
                                    v-if="hasOtherPrices(p)"
                                    type="button"
                                    class="pr-info"
                                    aria-label="Xem các loại giá khác"
                                    @click.stop="showPrices($event, p)"
                                >
                                    <i class="pi pi-info-circle" aria-hidden="true" />
                                </button>
                                <span v-else class="pr-info pr-info--none" aria-hidden="true" />
                            </div>
                        </footer>
                    </article>
                </div>
                <p v-else class="dash-empty">Chưa có sản phẩm nào khớp với tìm kiếm.</p>

                <Paginator :first="first" :rows="products.per_page" :rowsPerPageOptions="PAGE_SIZES" :totalRecords="products.total" @page="onPage" />
            </template>
        </div>

        <ProductDetail
            v-model:visible="detailOpen"
            :product="detailProduct"
            :priceLists="priceLists"
            :canEdit="can('products.update')"
            :canDelete="can('products.delete')"
            :canCreate="can('products.create')"
            @edit="openEdit"
            @remove="remove"
            @duplicate="duplicate"
            @toggle="toggleActive"
        />

        <Popover ref="pricePop">
            <div v-if="popProduct" class="pr-pop">
                <strong class="pr-pop-title">{{ popProduct.name }}</strong>
                <div v-for="r in popRows" :key="r.id" class="pr-tip-row">
                    <span>{{ r.name }}<small v-if="r.from"> (từ {{ r.from }})</small></span>
                    <b>{{ money(r.price) }}</b>
                </div>
            </div>
        </Popover>

        <AppDialog
            v-model:visible="dialog"
            :header="editing ? 'Sửa sản phẩm' : 'Thêm sản phẩm'"
            :style="{ width: '42rem' }"
            :breakpoints="{ '900px': '96vw' }"
        >
            <form @submit.prevent="submit" novalidate>
                <!-- Thông tin chung -->
                <div class="pr-head">
                    <div class="field pr-head-name">
                        <label for="p-name">Tên sản phẩm <span class="app-req" aria-hidden="true">*</span></label>
                        <InputText
                            id="p-name"
                            v-model="form.name"
                            autocomplete="off"
                            aria-required="true"
                            fluid
                            :invalid="!!err('name') || ['taken', 'invalid'].includes(nameCheck.status)"
                            @blur="nameCheck.check()"
                        />
                        <small v-if="err('name')" class="field-error">{{ err('name') }}</small>
                        <FieldStatus v-else :status="nameCheck.status" :message="nameCheck.message" />
                    </div>
                    <ImageInput
                        v-model:file="form.image_file"
                        v-model:remove="form.remove_image"
                        :url="editing?.image_url"
                        size="5rem"
                        label="ảnh sản phẩm"
                        :error="err('image_file')"
                    />
                    
                </div>

                <div class="pr-grid">
                    <div class="field">
                        <label for="p-category">Danh mục<span class="app-req" aria-hidden="true">*</span></label>
                        <Select
                            v-model="form.category_id"
                            inputId="p-category"
                            :options="categoryOptions"
                            optionLabel="name"
                            optionValue="id"
                            placeholder="Chọn danh mục"
                            aria-required="true"
                            fluid
                            :invalid="!!err('category_id')"
                        />
                        <small v-if="err('category_id')" class="field-error">{{ err('category_id') }}</small>
                    </div>
                    <div class="field">
                        <label for="p-unit">Đơn vị tính<span class="app-req" aria-hidden="true">*</span></label>
                        <Select
                            v-model="form.unit_id"
                            inputId="p-unit"
                            :options="unitOptions"
                            optionLabel="name"
                            optionValue="id"
                            placeholder="Chọn đơn vị"
                            aria-required="true"
                            fluid
                            :invalid="!!err('unit_id')"
                        />
                        <small v-if="err('unit_id')" class="field-error">{{ err('unit_id') }}</small>
                    </div>
                    <div class="field">
                        <label for="p-code">Mã sản phẩm</label>
                        <InputText
                            id="p-code"
                            v-model="form.code"
                            autocomplete="off"
                            autocapitalize="characters"
                            spellcheck="false"
                            fluid
                            :invalid="!!err('code') || ['taken', 'invalid'].includes(codeCheck.status)"
                            @blur="codeCheck.check()"
                        />
                        <small v-if="err('code')" class="field-error">{{ err('code') }}</small>
                        <FieldStatus v-else :status="codeCheck.status" :message="codeCheck.message" />
                        <small v-if="!err('code') && codeCheck.status === 'idle' && !editing" class="field-hint">Bỏ trống để tự động đánh số.</small>
                    </div>
                    <div class="field">
                        <label for="p-barcode">Mã vạch</label>
                        <InputText
                            id="p-barcode"
                            v-model="form.barcode"
                            autocomplete="off"
                            spellcheck="false"
                            fluid
                            :invalid="!!err('barcode') || ['taken', 'invalid'].includes(barcodeCheck.status)"
                            @blur="barcodeCheck.check()"
                        />
                        <small v-if="err('barcode')" class="field-error">{{ err('barcode') }}</small>
                        <FieldStatus v-else :status="barcodeCheck.status" :message="barcodeCheck.message" />
                    </div>
                    <div class="field pr-tax">
                        <label for="p-tax-on">Thuế</label>
                        <div class="pr-tax-row">
                            <Checkbox v-model="form.tax_enabled" inputId="p-tax-on" binary />
                            <label for="p-tax-on" class="pr-tax-label">Sản phẩm chịu thuế</label>
                            <Select
                                v-if="form.tax_enabled"
                                v-model="form.tax_rate"
                                inputId="p-tax"
                                :options="taxOptions"
                                optionLabel="label"
                                optionValue="value"
                                aria-label="Thuế suất"
                                class="pr-tax-select"
                                :invalid="!!err('tax_rate')"
                            />
                        </div>
                        <small v-if="err('tax_rate')" class="field-error">{{ err('tax_rate') }}</small>
                    </div>
                </div>

                <!-- Giá bán -->
                <section class="pr-section" aria-labelledby="p-price-title">
                    <h3 id="p-price-title" class="pr-section-title">Giá bán</h3>

                    <!-- Lưới 2 cột: bảng giá ở cột trái, số lượng tự chuyển ở cột phải -->
                    <div class="pr-prices">
                    <div class="field">
                        <label for="p-retail">{{ defaultList?.name ?? 'Giá bán lẻ' }}<span class="app-req" aria-hidden="true">*</span></label>
                        <InputNumber
                            v-model="form.retail_price"
                            inputId="p-retail"
                            :min="0"
                            :minFractionDigits="fractionDigits"
                            :maxFractionDigits="fractionDigits"
                            locale="vi-VN"
                            placeholder="0"
                            aria-required="true"
                            fluid
                            :invalid="!!err('prices.0.price')"
                        />
                        <small v-if="err('prices.0.price')" class="field-error">{{ err('prices.0.price') }}</small>
                    </div>
                    <div class="field">
                        <label for="p-cost">Giá vốn</label>
                        <InputNumber v-model="form.cost_price" inputId="p-cost" :min="0" :maxFractionDigits="0" locale="vi-VN" placeholder="0" fluid :invalid="!!err('cost_price')" />
                        <small v-if="err('cost_price')" class="field-error">{{ err('cost_price') }}</small>
                    </div>

                    <!-- Các bảng giá khác: luôn nhập được, chọn thủ công khi bán -->
                    <template v-if="tierLists.length">
                        <div v-for="l in tierLists" :key="l.id" class="pr-tier">
                            <div class="field">
                                <label :for="`p-tier-price-${l.id}`">{{ l.name }}</label>
                                <InputNumber
                                    v-model="form.tiers[l.id].price"
                                    :inputId="`p-tier-price-${l.id}`"
                                    :min="0"
                                    :minFractionDigits="fractionDigits"
                                    :maxFractionDigits="fractionDigits"
                                    locale="vi-VN"
                                    placeholder="Không bán theo giá này"
                                    fluid
                                    :invalid="!!err(`prices.${tierIndex(l.id)}.price`)"
                                />
                                <small v-if="err(`prices.${tierIndex(l.id)}.price`)" class="field-error">{{ err(`prices.${tierIndex(l.id)}.price`) }}</small>
                            </div>
                            <div class="field" :class="{ 'pr-dim': !form.qty_pricing }">
                                <label :for="`p-tier-qty-${l.id}`">Tự chuyển từ số lượng ({{ unitName }})</label>
                                <InputNumber
                                    v-model="form.tiers[l.id].min_qty"
                                    :inputId="`p-tier-qty-${l.id}`"
                                    :min="0"
                                    :maxFractionDigits="3"
                                    locale="vi-VN"
                                    placeholder="VD: 100"
                                    :disabled="!form.qty_pricing"
                                    fluid
                                    :invalid="(form.qty_pricing && dupQty(l.id)) || !!err(`prices.${tierIndex(l.id)}.min_qty`)"
                                />
                                <small v-if="form.qty_pricing && dupQty(l.id)" class="field-error">Số lượng này đã dùng cho một bảng giá khác. Mỗi bảng giá cần một mức riêng.</small>
                                <small v-else-if="err(`prices.${tierIndex(l.id)}.min_qty`)" class="field-error">{{ err(`prices.${tierIndex(l.id)}.min_qty`) }}</small>
                            </div>
                        </div>
                    </template>
                    </div>
                    <small v-if="tierLists.length" class="field-hint pr-hint-block">
                        Các bảng giá này luôn chọn thủ công được khi bán, kể cả chưa đủ số lượng.
                    </small>
                    <p v-else class="field-hint">
                        Muốn bán nhiều loại giá (ví dụ "Giá sỉ"), hãy thêm bảng giá trong Danh mục &amp; đơn vị, tab Bảng giá.
                    </p>

                    <template v-if="tierLists.length">
                        <div class="pr-check pr-auto">
                            <Checkbox v-model="form.qty_pricing" inputId="p-qty" binary />
                            <label for="p-qty">Tự động chuyển giá theo số lượng</label>
                        </div>
                        <small v-if="form.qty_pricing" class="field-hint pr-hint-block">
                            Khi số lượng bán từ mức đã nhập trở lên, màn hình bán hàng hỏi có áp dụng giá đó không. Để trống số lượng nếu bảng giá đó chỉ chọn thủ công.
                        </small>
                        <small v-if="err('qty_pricing')" class="field-error pr-hint-block">{{ err('qty_pricing') }}</small>
                    </template>
                </section>

                <!-- Tồn kho -->
                <section class="pr-section" aria-labelledby="p-stock-title">
                    <h3 id="p-stock-title" class="pr-section-title">Tồn kho</h3>
                    <div class="pr-check">
                        <Checkbox v-model="form.track_stock" inputId="p-track" binary />
                        <label for="p-track">Quản lý tồn kho</label>
                    </div>
                    <small class="field-hint pr-hint-block">
                        Bật thì khi bán sẽ trừ kho và không cho bán quá số lượng tồn. Tồn đầu kỳ nhập bằng phiếu nhập kho. Tắt thì không theo dõi số lượng.
                    </small>
                </section>

                <div class="field">
                    <label for="p-desc">Ghi chú</label>
                    <Textarea id="p-desc" v-model="form.description" rows="2" autoResize fluid />
                </div>

                <div class="pr-check">
                    <Checkbox v-model="form.is_active" inputId="p-active" binary />
                    <label for="p-active">Đang bán</label>
                </div>

                <div class="dialog-actions">
                    <Button type="button" label="Hủy" severity="secondary" outlined @click="dialog = false" />
                    <Button type="submit" :label="editing ? 'Lưu thay đổi' : 'Thêm sản phẩm'" :loading="form.processing" :disabled="hasConflict || qtyConflict" />
                </div>
            </form>
        </AppDialog>
    </AppLayout>
</template>

<style>
.pr-filter { min-width: 10.5rem; }
.pr-actions { display: flex; gap: 0.25rem; justify-content: flex-end; }

/* Cột giá: tiêu đề và nội dung cùng căn phải */
.pr-col-right { text-align: right !important; }
.pr-col-right .p-datatable-column-header-content { justify-content: flex-end; }
/* Tiêu đề lùi vào bằng ô ⓘ để thẳng hàng với số tiền */
.pr-price-head .p-datatable-column-header-content { padding-right: calc(2.25rem + 0.15rem); }

/* Ô sản phẩm trong bảng */
.pr-name { display: flex; align-items: center; gap: 0.6rem; min-width: 0; }
.pr-thumb { width: 2.4rem; height: 2.4rem; flex: none; border-radius: 0.4rem; object-fit: cover; }
.pr-thumb--empty { display: grid; place-items: center; background: var(--app-line); color: var(--app-muted); }
.pr-name-text { display: flex; flex-direction: column; min-width: 0; }
.pr-sub { display: none; color: var(--app-muted); }

/* Máy tính bảng: bớt cột, đưa thông tin xuống dòng phụ dưới tên */
@media (max-width: 900px) {
    .pr-hide-md { display: none !important; }
    .pr-sub { display: block; }
}
/* Điện thoại */
@media (max-width: 560px) {
    .pr-hide-sm { display: none !important; }
    .pr-filter { flex: 1 1 100%; }
}

/* Form */
.pr-head { display: flex; gap: 1rem; align-items: flex-start; margin-bottom: 1rem; }
.pr-head-name { flex: 1 1 14rem; margin-bottom: 0; min-width: 0; }
.pr-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: 0 1rem; }
.pr-section { border-top: 1px solid var(--app-line); padding-top: 0.9rem; margin-bottom: 1rem; }
.pr-section-title { font-size: 0.95rem; font-weight: 600; margin: 0 0 0.75rem; }
.pr-check { display: flex; align-items: center; gap: 0.5rem; }
.pr-check label { font-weight: 500; }
.pr-hint-block { display: block; margin: 0.35rem 0 0; }
/* Lưới 2 cột đều nhau, ô tràn hết chiều rộng form; .pr-tier "tan" vào lưới để giá và số lượng thẳng hàng */
.pr-prices { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 1rem; }
.pr-tier { display: contents; }
.pr-auto { margin-top: 0.75rem; }
.pr-dim { opacity: 0.5; }
.pr-sub--show { display: block; }
.pr-tax-row { display: flex; align-items: center; flex-wrap: wrap; gap: 0.5rem; min-height: 2.75rem; }
.pr-tax-label { font-weight: 400; }
.pr-tax-select { width: 6.5rem; }
.pr-link { padding: 0; border: 0; background: none; color: inherit; font: inherit; font-weight: 600; text-align: left; cursor: pointer; overflow-wrap: anywhere; }
.pr-link:hover { color: var(--app-primary); text-decoration: underline; }
.pr-link:focus-visible { outline: 2px solid var(--app-primary); outline-offset: 2px; border-radius: 0.3rem; }
.pr-pricecell { display: flex; align-items: center; justify-content: flex-end; gap: 0.15rem; }
.pr-pricetext { font-variant-numeric: tabular-nums; white-space: nowrap; }
/* Ô ⓘ luôn chiếm chỗ cố định (kể cả khi trống) để giá các dòng thẳng hàng */
.pr-info { flex: none; display: inline-grid; place-items: center; width: 2.25rem; height: 2.25rem; margin: -0.5rem 0; border: 0; border-radius: 50%; background: transparent; color: var(--app-primary); cursor: pointer; }
.pr-info--none { cursor: default; }
.pr-info:focus-visible { outline: 2px solid var(--app-primary); }
.pr-pop { display: flex; flex-direction: column; gap: 0.2rem; min-width: 12rem; }
.pr-pop-title { margin-bottom: 0.2rem; font-size: 0.9rem; }
.pr-note-cell { display: block; max-width: 14rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pr-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr)); gap: 1rem; padding: 1rem; }
.pr-card { cursor: pointer; transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease; display: flex; flex-direction: column; gap: 0.75rem; padding: 0.85rem 1rem 1rem; border: 1px solid var(--app-glass-border); border-radius: 16px; background: rgba(255, 255, 255, 0.72); box-shadow: 0 2px 10px rgba(28, 58, 68, 0.06); }
.pr-card:hover { transform: translateY(-2px); background: rgba(255, 255, 255, 0.92); box-shadow: var(--app-shadow); }
@media (prefers-reduced-motion: reduce) { .pr-card { transition: none; } .pr-card:hover { transform: none; } }
.pr-top { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; min-height: 2rem; }
.pr-top .pr-actions { display: flex; flex: none; gap: 0.1rem; margin-right: -0.35rem; }
.pr-main { display: flex; align-items: flex-start; gap: 0.9rem; }
.pr-who { flex: none; display: flex; flex-direction: column; align-items: center; gap: 0.55rem; }
.pr-code { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; padding: 0.15rem 0.6rem; border-radius: 999px; background: var(--app-primary-soft); color: var(--app-primary); font-size: 0.78rem; font-weight: 600; letter-spacing: 0.02em; }
.pr-card-img { width: 4.25rem; height: 4.25rem; flex: none; border-radius: 50%; object-fit: cover; display: grid; place-items: center; box-shadow: 0 6px 14px color-mix(in srgb, var(--p-primary-700) 24%, transparent); }
.pr-card-img.pr-thumb--empty { background: color-mix(in srgb, var(--app-primary) 8%, #fff); color: var(--app-muted); font-size: 1.4rem; }
.pr-dot { width: 0.8rem; height: 0.8rem; border-radius: 50%; box-shadow: 0 0 0 3px color-mix(in srgb, currentColor 18%, transparent); }
.pr-dot.is-on { color: #16a34a; background: #16a34a; }
.pr-dot.is-off { color: #dc2626; background: #dc2626; }
.pr-note { display: flex; gap: 0.5rem; margin: 0; padding: 0.55rem 0.75rem; border-radius: 0.8rem; background: rgba(255, 255, 255, 0.7); color: var(--app-muted); font-size: 0.84rem; line-height: 1.4; }
.pr-note i { flex: none; margin-top: 0.15rem; font-size: 0.8rem; }
.pr-note span:last-child { min-width: 0; overflow-wrap: anywhere; white-space: pre-line; }
.pr-lines { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 0.3rem; }
.pr-card-name { margin: 0 0 0.1rem; font-size: 1.05rem; font-weight: 700; line-height: 1.25; letter-spacing: -0.01em; overflow-wrap: anywhere; }
.pr-line { display: flex; align-items: flex-start; gap: 0.5rem; margin: 0; font-size: 0.88rem; line-height: 1.35; overflow-wrap: anywhere; }
.pr-line i { flex: none; width: 1rem; margin-top: 0.15rem; color: var(--p-primary-600); font-size: 0.85rem; text-align: center; }
.pr-line-note { display: -webkit-box; overflow: hidden; -webkit-line-clamp: 2; -webkit-box-orient: vertical; color: var(--app-muted); }
.pr-price { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-top: auto; padding: 0.55rem 0.8rem; border-radius: 0.8rem; background: color-mix(in srgb, var(--app-primary) 10%, transparent); cursor: default; }
.pr-price > span { display: inline-flex; align-items: center; gap: 0.4rem; color: var(--app-muted); font-size: 0.84rem; }
.pr-price strong { color: var(--app-primary); font-size: 1rem; font-weight: 700; font-variant-numeric: tabular-nums; }
.pr-price:focus-visible { outline: 2px solid var(--app-primary); outline-offset: 2px; }
.pr-tip .p-tooltip-text { padding: 0.5rem 0.7rem; }
.pr-tip-row { display: flex; justify-content: space-between; gap: 1.2rem; white-space: nowrap; line-height: 1.6; }
@media (max-width: 639px) {
    .pr-cards { grid-template-columns: 1fr; padding: 0.75rem; gap: 0.75rem; }
}
.pr-tag { margin-left: 0.4rem; }

@media (max-width: 560px) {
    .pr-prices { grid-template-columns: 1fr; }
}
</style>
