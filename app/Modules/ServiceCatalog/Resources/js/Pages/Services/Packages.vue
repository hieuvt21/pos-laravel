<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
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
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import Tag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import { useConfirm } from 'primevue/useconfirm';
import AppDialog from '@/Components/AppDialog.vue';
import CommissionField from '@/Components/CommissionField.vue';
import FieldLabel from '@/Components/FieldLabel.vue';
import FieldStatus from '@/Components/FieldStatus.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';
import { usePermission } from '@/composables/usePermission';
import { useUniqueCheck } from '@/composables/useUniqueCheck';
import './services.css';

/**
 * Mẫu gói bán trước: Combo (1 dịch vụ, nhiều lần) và Liệu trình (nhiều dịch vụ). Một trang dùng chung, prop `type` quyết định.
 * Hạn sử dụng tính từ ngày mua; bảo hành tính sau khi dùng hết gói. Gói của khách và trừ buổi làm ở Bước B (module Packages).
 */
const props = defineProps({
    type: { type: String, required: true }, // 'combo' | 'course'
    packages: { type: Object, required: true },
    filters: { type: Object, default: () => ({ search: '', category: null, status: null }) },
    categories: { type: Array, default: () => [] }, // [{ id, name, is_active }]
    services: { type: Array, default: () => [] }, // dịch vụ đang bán: [{ id, name, price, commission_type, commission_value }]
});

const { can } = usePermission();
const { money, config } = useMoney();
const confirm = useConfirm();

const isCombo = computed(() => props.type === 'combo');
const noun = computed(() => (isCombo.value ? 'combo' : 'liệu trình'));
const title = computed(() => (isCombo.value ? 'Combo' : 'Liệu trình'));
const indexRoute = computed(() => (isCombo.value ? 'service_combos.index' : 'service_courses.index'));
const fractionDigits = computed(() => Number(config.value.decimals) || 0);

/* ---------- Máy tính: bảng. Máy tính bảng / điện thoại: thẻ ---------- */
const media = window.matchMedia('(min-width: 1024px)');
const isDesktop = ref(media.matches);
const onMedia = (e) => (isDesktop.value = e.matches);
media.addEventListener('change', onMedia);
onBeforeUnmount(() => media.removeEventListener('change', onMedia));

/* ---------- Tìm kiếm, lọc, phân trang (xử lý ở server) ---------- */
const ALL = 'all'; // PrimeVue Select không hiện lựa chọn có giá trị null
const search = ref(props.filters.search ?? '');
const category = ref(props.filters.category ?? ALL);
const status = ref(props.filters.status ?? ALL);

const categoryFilterOptions = computed(() => [{ value: ALL, label: 'Tất cả danh mục' }, ...props.categories.map((c) => ({ value: c.id, label: c.name }))]);
const statusOptions = [
    { value: ALL, label: 'Tất cả trạng thái' },
    { value: 'active', label: 'Đang bán' },
    { value: 'inactive', label: 'Ngừng bán' },
];

const PAGE_KEY = 'pos.perPage'; // dùng chung với các trang danh sách khác
const PAGE_SIZES = [10, 15, 30, 50, 100];
const perPage = ref(props.packages.per_page);

const visit = (extra = {}) =>
    router.get(
        route(indexRoute.value),
        {
            per_page: perPage.value,
            search: search.value || undefined,
            category: category.value !== ALL ? category.value : undefined,
            status: status.value !== ALL ? status.value : undefined,
            ...extra,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );

let timer = null;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => visit(), 300);
});
watch([category, status], () => visit());
onBeforeUnmount(() => clearTimeout(timer));

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
        if (PAGE_SIZES.includes(saved) && saved !== props.packages.per_page) {
            perPage.value = saved;
            visit();
        }
    } catch {
        // bỏ qua
    }
});
const first = computed(() => (props.packages.current_page - 1) * props.packages.per_page);

const hasFilter = computed(() => !!(search.value || category.value !== ALL || status.value !== ALL));
const emptyText = computed(() =>
    hasFilter.value ? `Không có ${noun.value} nào khớp với bộ lọc.` : `Chưa có ${noun.value} nào. Bấm "Thêm ${noun.value}" để bắt đầu.`,
);

const validityText = (p) => (p.validity_days ? `${p.validity_days} ngày` : 'Không hạn');
const warrantyText = (p) => (p.warranty_days ? `${p.warranty_days} ngày` : 'Không có');
const itemsText = (p) => p.items.map((i) => `${i.service_name ?? '(đã xóa)'} × ${i.quantity ?? 'không giới hạn'}`).join(', ');

/* ---------- Thêm / sửa ---------- */
const dialog = ref(false);
const editing = ref(null);

let seq = 0; // khóa để Vue theo dõi từng dòng dịch vụ khi thêm/bớt
const newRow = (service = null) => ({
    _k: ++seq,
    service_id: service?.id ?? null,
    quantity: null,
    commission_type: service?.commission_type ?? 'amount',
    commission_value: service?.commission_value ?? null,
});

const form = useForm({
    type: props.type,
    name: '',
    service_category_id: null,
    price: null,
    validity_days: null,
    warranty_days: null,
    sale_commission_type: 'amount',
    sale_commission_value: null,
    usage_commission_mode: 'per_item',
    usage_commission_type: 'amount',
    usage_commission_value: null,
    note: '',
    is_active: true,
    items: [],
});

// Chặn trùng tên ngay khi nhập (khi sửa: bỏ qua chính gói đang sửa)
const nameCheck = useUniqueCheck('name', () => form.name, {
    ignoreId: () => editing.value?.id ?? null,
    original: () => editing.value?.name ?? null,
    url: () => route('service_packages.check'),
});
const hasConflict = computed(() => ['taken', 'invalid'].includes(nameCheck.status));

// Danh mục chọn được: đang sử dụng (và danh mục hiện tại của gói đang sửa, kể cả đã ngừng)
const formCategories = computed(() =>
    props.categories.filter((c) => c.is_active || c.id === form.service_category_id).map((c) => ({ value: c.id, label: c.is_active ? c.name : `${c.name} (ngừng sử dụng)` })),
);

// Dịch vụ tra cứu: đang bán + các dịch vụ đã có trong gói đang sửa (kể cả đã ngừng bán)
const serviceMap = computed(() => {
    const map = new Map(props.services.map((s) => [s.id, s]));
    (editing.value?.items ?? []).forEach((i) => {
        if (!map.has(i.service_id)) {
            map.set(i.service_id, { id: i.service_id, name: `${i.service_name ?? 'Dịch vụ đã xóa'} (ngừng bán)`, price: i.service_price, commission_type: 'amount', commission_value: 0 });
        }
    });
    return map;
});
const optionsFor = (row) =>
    [...serviceMap.value.values()]
        .filter((s) => s.id === row.service_id || !form.items.some((r) => r.service_id === s.id))
        .map((s) => ({ value: s.id, label: `${s.name} · ${money(s.price)}` }));
const rowPrice = (row) => serviceMap.value.get(row.service_id)?.price ?? null;

function pickService(row, id) {
    row.service_id = id;
    // Mặc định hoa hồng theo định mức của chính dịch vụ (sửa được)
    const s = serviceMap.value.get(id);
    if (s) {
        row.commission_type = s.commission_type;
        row.commission_value = s.commission_value;
    }
    form.clearErrors(`items.${form.items.indexOf(row)}.service_id`);
}
const addRow = () => form.items.push(newRow());
function removeRow(i) {
    delete problems[form.items[i]._k];
    form.items.splice(i, 1);
}

// Cách tính hoa hồng khi dùng dịch vụ (chỉ liệu trình): theo từng dịch vụ hoặc một định mức chung
const modeOptions = [
    { value: 'per_item', label: 'Theo từng dịch vụ' },
    { value: 'common', label: 'Hoa hồng chung' },
];
const perItem = computed(() => isCombo.value || form.usage_commission_mode === 'per_item');
function setMode(mode) {
    if (!mode || mode === form.usage_commission_mode) return;
    form.usage_commission_mode = mode;
    form.clearErrors('usage_commission_value');
    if (mode === 'per_item') {
        // Quay về theo từng dịch vụ: điền lại định mức mặc định của từng dịch vụ
        form.items.forEach((r) => {
            const s = serviceMap.value.get(r.service_id);
            r.commission_type = s?.commission_type ?? 'amount';
            r.commission_value = s?.commission_value ?? null;
        });
    } else {
        form.usage_commission_type = 'amount';
        form.usage_commission_value = null;
    }
}
const minServicePrice = computed(() => {
    const prices = form.items.map(rowPrice).filter((p) => p !== null);
    return prices.length ? Math.min(...prices) : null;
});

// Giá gói "đang gõ": PrimeVue chỉ cập nhật v-model khi rời ô, nên lấy giá trị ngay từ sự kiện input
const livePrice = ref(null);
watch(() => form.price, (v) => (livePrice.value = v), { immediate: true });
const onPriceInput = (e) => (livePrice.value = e.value);

// Báo vượt giới hạn hoa hồng từ các ô định mức; có lỗi thì khóa nút lưu
const problems = reactive({});
const saleProblem = ref(null);
const usageProblem = ref(null);
const hasProblem = computed(
    () => !!saleProblem.value || (perItem.value ? form.items.some((r) => problems[r._k]) : !!usageProblem.value),
);

// Gợi ý: tổng giá lẻ và mức tiết kiệm (chỉ khi mọi dịch vụ đều có số lần)
const retailTotal = computed(() => {
    if (!form.items.length || form.items.some((r) => r.service_id === null || !r.quantity)) return null;
    return form.items.reduce((sum, r) => sum + (rowPrice(r) ?? 0) * r.quantity, 0);
});
const saving = computed(() => (retailTotal.value === null || livePrice.value === null ? null : retailTotal.value - livePrice.value));

function resetProblems() {
    Object.keys(problems).forEach((k) => delete problems[k]);
}

function openCreate() {
    nameCheck.reset();
    resetProblems();
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.items = isCombo.value ? [newRow()] : [newRow(), newRow()];
    dialog.value = true;
}

function openEdit(p) {
    nameCheck.reset();
    resetProblems();
    editing.value = p;
    form.clearErrors();
    form.name = p.name;
    form.service_category_id = p.category_id;
    form.price = p.price;
    form.validity_days = p.validity_days;
    form.warranty_days = p.warranty_days;
    form.sale_commission_type = p.sale_commission_type;
    form.sale_commission_value = p.sale_commission_value;
    form.usage_commission_mode = p.usage_commission_mode;
    form.usage_commission_type = p.usage_commission_type;
    form.usage_commission_value = p.usage_commission_value;
    form.note = p.note ?? '';
    form.is_active = !!p.is_active;
    form.items = p.items.map((i) => ({ _k: ++seq, service_id: i.service_id, quantity: i.quantity, commission_type: i.commission_type, commission_value: i.commission_value }));
    dialog.value = true;
}

function submit() {
    if (hasConflict.value || hasProblem.value) return;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            dialog.value = false;
            form.reset();
        },
    };

    form.transform((d) => ({
        ...d,
        sale_commission_value: d.sale_commission_value ?? 0,
        usage_commission_value: d.usage_commission_value ?? 0,
        items: d.items.map((r) => ({ service_id: r.service_id, quantity: r.quantity ?? null, commission_type: r.commission_type, commission_value: r.commission_value ?? 0 })),
    }));
    if (editing.value) {
        form.put(route('service_packages.update', editing.value.id), options);
    } else {
        form.post(route('service_packages.store'), options);
    }
}

function remove(p) {
    confirm.require({
        header: `Xóa ${noun.value}`,
        message: `Xóa ${noun.value} "${p.name}"? Gói khách đã mua trước đó vẫn giữ nguyên.`,
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Hủy',
        acceptLabel: 'Xóa',
        rejectProps: { severity: 'secondary', outlined: true },
        acceptProps: { severity: 'danger' },
        accept: () => router.delete(route('service_packages.destroy', p.id), { preserveScroll: true }),
    });
}
</script>

<template>
    <Head :title="title" />

    <AppLayout :title="title">
        <div class="app-panel app-fill">
            <div class="app-toolbar">
                <IconField class="sv-search">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="search" :placeholder="`Tìm theo tên hoặc mã ${noun}`" :aria-label="`Tìm ${noun}`" fluid />
                </IconField>
                <Select v-model="category" :options="categoryFilterOptions" optionLabel="label" optionValue="value" aria-label="Lọc theo danh mục" style="min-width: 11rem" />
                <Select v-model="status" :options="statusOptions" optionLabel="label" optionValue="value" aria-label="Lọc theo trạng thái" style="min-width: 11rem" />
                <Button v-if="can('service_packages.create')" icon="pi pi-plus" :label="`Thêm ${noun}`" @click="openCreate" />
            </div>

            <!-- Máy tính: bảng -->
            <DataTable
                v-if="isDesktop"
                :value="packages.data"
                dataKey="id"
                lazy
                paginator
                :rowsPerPageOptions="PAGE_SIZES"
                :rows="packages.per_page"
                :first="first"
                :totalRecords="packages.total"
                @page="onPage"
            >
                <template #empty>{{ emptyText }}</template>

                <Column field="code" header="Mã" style="width: 8rem" />
                <Column :header="title">
                    <template #body="{ data }">
                        <strong>{{ data.name }}</strong>
                        <Tag v-if="!data.is_active" value="Ngừng bán" severity="secondary" class="sv-tag" />
                        <div class="sv-sub">{{ itemsText(data) }}</div>
                    </template>
                </Column>
                <Column header="Danh mục">
                    <template #body="{ data }">{{ data.category || '—' }}</template>
                </Column>
                <Column header="Giá gói" headerClass="sv-col-right" bodyClass="sv-col-right" style="width: 9rem">
                    <template #body="{ data }"><span class="sv-num">{{ money(data.price) }}</span></template>
                </Column>
                <Column header="Hạn dùng" style="width: 8rem">
                    <template #body="{ data }">{{ validityText(data) }}</template>
                </Column>
                <Column header="Bảo hành" style="width: 8rem">
                    <template #body="{ data }">{{ warrantyText(data) }}</template>
                </Column>
                <Column header="HH bán" headerClass="sv-col-right" bodyClass="sv-col-right" style="width: 8rem">
                    <template #body="{ data }"><span class="sv-num sv-strong">{{ money(data.sale_commission) }}</span></template>
                </Column>
                <Column header="" style="width: 7rem">
                    <template #body="{ data }">
                        <div class="sv-row-actions">
                            <Button v-if="can('service_packages.update')" icon="pi pi-pencil" text rounded severity="secondary" :aria-label="`Sửa ${data.name}`" @click="openEdit(data)" />
                            <Button v-if="can('service_packages.delete')" icon="pi pi-trash" text rounded severity="danger" :aria-label="`Xóa ${data.name}`" @click="remove(data)" />
                        </div>
                    </template>
                </Column>
            </DataTable>

            <!-- Máy tính bảng, điện thoại: thẻ -->
            <template v-else>
                <div v-if="packages.data.length" class="sv-grid">
                    <article v-for="p in packages.data" :key="p.id" class="sv-card">
                        <header class="sv-card-top">
                            <span class="sv-code">{{ p.code }}</span>
                            <Tag v-if="!p.is_active" value="Ngừng bán" severity="secondary" />
                        </header>
                        <h3 class="sv-name">{{ p.name }}</h3>
                        <p v-if="p.category" class="sv-sub">{{ p.category }}</p>
                        <ul class="pk-lines">
                            <li v-for="i in p.items" :key="i.service_id">
                                <span>{{ i.service_name ?? '(đã xóa)' }}</span>
                                <strong class="sv-num">× {{ i.quantity ?? 'không giới hạn' }}</strong>
                            </li>
                        </ul>
                        <div class="sv-card-row"><span>Giá gói</span><strong class="sv-num">{{ money(p.price) }}</strong></div>
                        <div class="sv-card-row"><span>Hạn dùng</span><strong>{{ validityText(p) }}</strong></div>
                        <div class="sv-card-row"><span>Bảo hành</span><strong>{{ warrantyText(p) }}</strong></div>
                        <div class="sv-card-row"><span>Hoa hồng bán</span><strong class="sv-num sv-strong">{{ money(p.sale_commission) }}</strong></div>
                        <div class="sv-row-actions">
                            <Button v-if="can('service_packages.update')" label="Sửa" icon="pi pi-pencil" severity="secondary" outlined size="small" @click="openEdit(p)" />
                            <Button v-if="can('service_packages.delete')" label="Xóa" icon="pi pi-trash" severity="danger" text size="small" @click="remove(p)" />
                        </div>
                    </article>
                </div>
                <p v-else class="sv-empty">{{ emptyText }}</p>

                <Paginator :first="first" :rows="packages.per_page" :rowsPerPageOptions="PAGE_SIZES" :totalRecords="packages.total" @page="onPage" />
            </template>
        </div>

        <AppDialog v-model:visible="dialog" :header="editing ? `Sửa ${noun}` : `Thêm ${noun}`" :style="{ width: '40rem' }" :breakpoints="{ '640px': '94vw' }">
            <form novalidate @submit.prevent="submit">
                <div class="field">
                    <label for="pk-name">Tên {{ noun }} <span class="app-req" aria-hidden="true">*</span></label>
                    <InputText id="pk-name" v-model="form.name" autocomplete="off" fluid :invalid="!!form.errors.name || hasConflict" @blur="nameCheck.check()" />
                    <small v-if="form.errors.name" class="field-error">{{ form.errors.name }}</small>
                    <FieldStatus v-else :status="nameCheck.status" :message="nameCheck.message" />
                </div>

                <div class="field">
                    <FieldLabel for="pk-category">Danh mục <span class="app-req" aria-hidden="true">*</span>
                        <template #help>Dùng chung danh mục với dịch vụ. Chưa có danh mục thì tạo ở menu Dịch vụ, mục Danh mục dịch vụ trước.</template>
                    </FieldLabel>
                    <Select
                        v-model="form.service_category_id"
                        inputId="pk-category"
                        :options="formCategories"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Chọn danh mục dịch vụ"
                        aria-required="true"
                        fluid
                        :invalid="!!form.errors.service_category_id"
                    />
                    <small v-if="form.errors.service_category_id" class="field-error">{{ form.errors.service_category_id }}</small>
                </div>

                <!-- Dịch vụ trong gói -->
                <div class="field">
                    <FieldLabel for="pk-svc-0">{{ isCombo ? 'Dịch vụ' : 'Dịch vụ trong gói' }} <span class="app-req" aria-hidden="true">*</span>
                        <template #help>
                            {{ isCombo ? 'Combo gồm đúng một dịch vụ dùng nhiều lần.' : 'Liệu trình gồm từ 2 dịch vụ trở lên.' }}
                            Để trống "Số lần" nếu không giới hạn số lần (chỉ được khi gói có hạn sử dụng). Hoa hồng mặc định lấy theo định mức của dịch vụ và tính trên giá lẻ của dịch vụ.
                        </template>
                    </FieldLabel>
                    <small v-if="typeof form.errors.items === 'string'" class="field-error">{{ form.errors.items }}</small>
                </div>

                <div class="pk-items">
                    <div v-for="(row, i) in form.items" :key="row._k" class="pk-item">
                        <div class="pk-item-head">
                            <Select
                                :modelValue="row.service_id"
                                :inputId="`pk-svc-${i}`"
                                :options="optionsFor(row)"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="Chọn dịch vụ"
                                filter
                                fluid
                                :aria-label="`Dịch vụ ${i + 1}`"
                                :invalid="!!form.errors[`items.${i}.service_id`]"
                                @update:modelValue="(v) => pickService(row, v)"
                            />
                            <Button v-if="!isCombo && form.items.length > 2" type="button" icon="pi pi-times" text rounded severity="danger" :aria-label="`Bỏ dịch vụ ${i + 1}`" @click="removeRow(i)" />
                        </div>
                        <small v-if="form.errors[`items.${i}.service_id`]" class="field-error">{{ form.errors[`items.${i}.service_id`] }}</small>

                        <div class="field">
                            <label :for="`pk-qty-${i}`">Số lần sử dụng</label>
                            <InputNumber
                                v-model="row.quantity"
                                :inputId="`pk-qty-${i}`"
                                :min="1"
                                :max="9999"
                                :useGrouping="false"
                                placeholder="Không giới hạn"
                                fluid
                                :invalid="!!form.errors[`items.${i}.quantity`]"
                            />
                            <small v-if="form.errors[`items.${i}.quantity`]" class="field-error">{{ form.errors[`items.${i}.quantity`] }}</small>
                        </div>

                        <CommissionField
                            v-if="perItem"
                            :type="row.commission_type"
                            :value="row.commission_value"
                            :price="rowPrice(row)"
                            priceLabel="giá dịch vụ"
                            :error="form.errors[`items.${i}.commission_value`]"
                            :inputId="`pk-quota-${i}`"
                            @update:type="(t) => (row.commission_type = t)"
                            @update:value="(v) => (row.commission_value = v)"
                            @problem="(m) => (problems[row._k] = m)"
                        />
                    </div>
                    <Button v-if="!isCombo && form.items.length < 30" type="button" icon="pi pi-plus" label="Thêm dịch vụ" severity="secondary" outlined size="small" @click="addRow" />
                </div>

                <!-- Cách tính hoa hồng khi dùng dịch vụ (liệu trình) -->
                <div v-if="!isCombo" class="field">
                    <FieldLabel>Hoa hồng khi sử dụng
                        <template #help>Theo từng dịch vụ: mỗi dịch vụ một định mức riêng (mặc định theo dịch vụ). Hoa hồng chung: dùng dịch vụ nào cũng cùng một định mức.</template>
                    </FieldLabel>
                    <SelectButton :modelValue="form.usage_commission_mode" :options="modeOptions" optionLabel="label" optionValue="value" :allowEmpty="false" aria-label="Cách tính hoa hồng khi sử dụng" @update:modelValue="setMode" />
                    <small v-if="form.errors.usage_commission_mode" class="field-error">{{ form.errors.usage_commission_mode }}</small>
                </div>
                <CommissionField
                    v-if="!perItem"
                    :type="form.usage_commission_type"
                    :value="form.usage_commission_value"
                    :price="minServicePrice"
                    priceLabel="giá dịch vụ thấp nhất trong gói"
                    :error="form.errors.usage_commission_value"
                    inputId="pk-usage-quota"
                    @update:type="(t) => (form.usage_commission_type = t)"
                    @update:value="(v) => (form.usage_commission_value = v)"
                    @problem="(m) => (usageProblem = m)"
                />

                <!-- Giá gói -->
                <div class="field">
                    <label for="pk-price">Giá {{ noun }} <span class="app-req" aria-hidden="true">*</span></label>
                    <InputNumber
                        v-model="form.price"
                        inputId="pk-price"
                        :min="0"
                        :minFractionDigits="fractionDigits"
                        :maxFractionDigits="fractionDigits"
                        locale="vi-VN"
                        placeholder="0"
                        aria-required="true"
                        fluid
                        :invalid="!!form.errors.price"
                        @input="onPriceInput"
                    />
                    <small v-if="form.errors.price" class="field-error">{{ form.errors.price }}</small>
                    <small v-else-if="retailTotal !== null" class="pk-summary">
                        Tổng giá lẻ {{ money(retailTotal) }}<template v-if="saving !== null && saving > 0"> · khách tiết kiệm <strong class="pk-saving">{{ money(saving) }}</strong></template><template v-else-if="saving !== null && saving < 0"> · giá gói đang CAO hơn giá lẻ</template>.
                    </small>
                </div>

                <div class="pk-pair">
                    <div class="field">
                        <FieldLabel for="pk-validity">Hạn sử dụng (ngày)
                            <template #help>Tính từ ngày mua. Hết hạn thì gói bị hủy dù chưa dùng hết. Để trống nếu không có hạn (khi đó mỗi dịch vụ phải có số lần).</template>
                        </FieldLabel>
                        <InputNumber v-model="form.validity_days" inputId="pk-validity" :min="1" :max="3650" :useGrouping="false" placeholder="Không hạn" fluid :invalid="!!form.errors.validity_days" />
                        <small v-if="form.errors.validity_days" class="field-error">{{ form.errors.validity_days }}</small>
                    </div>
                    <div class="field">
                        <FieldLabel for="pk-warranty">Bảo hành (ngày)
                            <template #help>Số ngày bảo hành tính từ lúc khách dùng hết gói. Để trống nếu không bảo hành.</template>
                        </FieldLabel>
                        <InputNumber v-model="form.warranty_days" inputId="pk-warranty" :min="1" :max="3650" :useGrouping="false" placeholder="Không có" fluid :invalid="!!form.errors.warranty_days" />
                        <small v-if="form.errors.warranty_days" class="field-error">{{ form.errors.warranty_days }}</small>
                    </div>
                </div>

                <small class="pk-summary pk-before-cf">Hoa hồng cho người BÁN {{ noun }} (tính trên giá {{ noun }}):</small>
                <CommissionField
                    :type="form.sale_commission_type"
                    :value="form.sale_commission_value"
                    :price="livePrice"
                    :priceLabel="`giá ${noun}`"
                    :error="form.errors.sale_commission_value"
                    inputId="pk-sale-quota"
                    @update:type="(t) => (form.sale_commission_type = t)"
                    @update:value="(v) => (form.sale_commission_value = v)"
                    @problem="(m) => (saleProblem = m)"
                />

                <div class="field">
                    <label for="pk-note">Ghi chú</label>
                    <Textarea id="pk-note" v-model="form.note" rows="2" autoResize fluid :invalid="!!form.errors.note" />
                    <small v-if="form.errors.note" class="field-error">{{ form.errors.note }}</small>
                </div>

                <div class="field" style="flex-direction: row; align-items: center; gap: 0.5rem">
                    <Checkbox v-model="form.is_active" inputId="pk-active" binary />
                    <label for="pk-active">Đang bán</label>
                </div>
                <small v-if="form.errors.is_active" class="field-error">{{ form.errors.is_active }}</small>

                <div class="dialog-actions">
                    <Button type="button" label="Hủy" severity="secondary" outlined @click="dialog = false" />
                    <Button type="submit" :label="editing ? 'Lưu thay đổi' : `Thêm ${noun}`" :loading="form.processing" :disabled="hasConflict || hasProblem" />
                </div>
            </form>
        </AppDialog>
    </AppLayout>
</template>
