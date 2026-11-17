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
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import { useConfirm } from 'primevue/useconfirm';
import AppDialog from '@/Components/AppDialog.vue';
import CommissionField from '@/Components/CommissionField.vue';
import FieldStatus from '@/Components/FieldStatus.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';
import { usePermission } from '@/composables/usePermission';
import { useUniqueCheck } from '@/composables/useUniqueCheck';
import FieldLabel from '@/Components/FieldLabel.vue';
import './services.css';

const props = defineProps({
    services: { type: Object, required: true },
    filters: { type: Object, default: () => ({ search: '', category: null, status: null }) },
    categories: { type: Array, default: () => [] }, // [{ id, name, is_active }]
});

const { can } = usePermission();
const { money, config } = useMoney();
const confirm = useConfirm();

const fractionDigits = computed(() => Number(config.value.decimals) || 0);
const nf = new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 2 });

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
const perPage = ref(props.services.per_page);

const visit = (extra = {}) =>
    router.get(
        route('services.index'),
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
        if (PAGE_SIZES.includes(saved) && saved !== props.services.per_page) {
            perPage.value = saved;
            visit();
        }
    } catch {
        // bỏ qua
    }
});
const first = computed(() => (props.services.current_page - 1) * props.services.per_page);

const hasFilter = computed(() => !!(search.value || category.value !== ALL || status.value !== ALL));
const emptyText = computed(() => (hasFilter.value ? 'Không có dịch vụ nào khớp với bộ lọc.' : 'Chưa có dịch vụ nào. Bấm "Thêm dịch vụ" để bắt đầu.'));

const quotaText = (s) => (s.commission_type === 'percent' ? `${nf.format(s.commission_value)}%` : money(s.commission_value));

/* ---------- Thêm / sửa ---------- */
const dialog = ref(false);
const editing = ref(null);
const form = useForm({ name: '', service_category_id: null, price: null, commission_type: 'amount', commission_value: null, note: '', is_active: true });

// Chặn trùng tên ngay khi nhập (khi sửa: bỏ qua chính dịch vụ đang sửa)
const nameCheck = useUniqueCheck('name', () => form.name, {
    ignoreId: () => editing.value?.id ?? null,
    original: () => editing.value?.name ?? null,
    url: () => route('services.check'),
});
const hasConflict = computed(() => ['taken', 'invalid'].includes(nameCheck.status));

// Danh mục chọn được: đang sử dụng (và danh mục hiện tại của dịch vụ đang sửa, kể cả đã ngừng)
const formCategories = computed(() =>
    props.categories.filter((c) => c.is_active || c.id === form.service_category_id).map((c) => ({ value: c.id, label: c.is_active ? c.name : `${c.name} (ngừng sử dụng)` })),
);

// Giá "đang gõ": PrimeVue chỉ cập nhật v-model khi rời ô, nên lấy giá trị ngay từ sự kiện input
// để ô Hoa hồng tính lại sau từng phím bấm.
const livePrice = ref(null);
watch(() => form.price, (v) => (livePrice.value = v), { immediate: true });
const onPriceInput = (e) => (livePrice.value = e.value);

// Định mức hoa hồng dùng ô chung CommissionField; vượt giới hạn thì khóa nút lưu
const quotaOver = ref(null);
function onTypeChange(type) {
    form.commission_type = type;
    form.clearErrors('commission_value');
}

function openCreate() {
    nameCheck.reset();
    editing.value = null;
    form.reset();
    form.clearErrors();
    dialog.value = true;
}

function openEdit(s) {
    nameCheck.reset();
    editing.value = s;
    form.clearErrors();
    form.name = s.name;
    form.service_category_id = s.service_category_id;
    form.price = s.price;
    form.commission_type = s.commission_type;
    form.commission_value = s.commission_value;
    form.note = s.note ?? '';
    form.is_active = !!s.is_active;
    dialog.value = true;
}

function submit() {
    if (hasConflict.value || quotaOver.value) return;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            dialog.value = false;
            form.reset();
        },
    };

    form.transform((d) => ({ ...d, commission_value: d.commission_value ?? 0 }));
    if (editing.value) {
        form.put(route('services.update', editing.value.id), options);
    } else {
        form.post(route('services.store'), options);
    }
}

function remove(s) {
    confirm.require({
        header: 'Xóa dịch vụ',
        message: `Xóa dịch vụ "${s.name}"? Hóa đơn đã bán trước đó vẫn giữ nguyên.`,
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Hủy',
        acceptLabel: 'Xóa',
        rejectProps: { severity: 'secondary', outlined: true },
        acceptProps: { severity: 'danger' },
        accept: () => router.delete(route('services.destroy', s.id), { preserveScroll: true }),
    });
}
</script>

<template>
    <Head title="Dịch vụ" />

    <AppLayout title="Dịch vụ">
        <div class="app-panel app-fill">
            <div class="app-toolbar">
                <IconField class="sv-search">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="search" placeholder="Tìm theo tên hoặc mã dịch vụ" aria-label="Tìm dịch vụ" fluid />
                </IconField>
                <Select v-model="category" :options="categoryFilterOptions" optionLabel="label" optionValue="value" aria-label="Lọc theo danh mục" style="min-width: 11rem" />
                <Select v-model="status" :options="statusOptions" optionLabel="label" optionValue="value" aria-label="Lọc theo trạng thái" style="min-width: 11rem" />
                <Button v-if="can('services.create')" icon="pi pi-plus" label="Thêm dịch vụ" @click="openCreate" />
            </div>

            <!-- Máy tính: bảng -->
            <DataTable
                v-if="isDesktop"
                :value="services.data"
                dataKey="id"
                lazy
                paginator
                :rowsPerPageOptions="PAGE_SIZES"
                :rows="services.per_page"
                :first="first"
                :totalRecords="services.total"
                @page="onPage"
            >
                <template #empty>{{ emptyText }}</template>

                <Column field="code" header="Mã" style="width: 8rem" />
                <Column header="Dịch vụ">
                    <template #body="{ data }">
                        <strong>{{ data.name }}</strong>
                        <Tag v-if="!data.is_active" value="Ngừng bán" severity="secondary" class="sv-tag" />
                        <div v-if="data.note" class="sv-sub">{{ data.note }}</div>
                    </template>
                </Column>
                <Column header="Danh mục">
                    <template #body="{ data }">{{ data.category || '—' }}</template>
                </Column>
                <Column header="Giá" headerClass="sv-col-right" bodyClass="sv-col-right" style="width: 9rem">
                    <template #body="{ data }"><span class="sv-num">{{ money(data.price) }}</span></template>
                </Column>
                <Column header="Định mức" headerClass="sv-col-right" bodyClass="sv-col-right" style="width: 9rem">
                    <template #body="{ data }"><span class="sv-num">{{ quotaText(data) }}</span></template>
                </Column>
                <Column header="Hoa hồng" headerClass="sv-col-right" bodyClass="sv-col-right" style="width: 9rem">
                    <template #body="{ data }"><span class="sv-num sv-strong">{{ money(data.commission) }}</span></template>
                </Column>
                <Column header="" style="width: 7rem">
                    <template #body="{ data }">
                        <div class="sv-row-actions">
                            <Button v-if="can('services.update')" icon="pi pi-pencil" text rounded severity="secondary" :aria-label="`Sửa ${data.name}`" @click="openEdit(data)" />
                            <Button v-if="can('services.delete')" icon="pi pi-trash" text rounded severity="danger" :aria-label="`Xóa ${data.name}`" @click="remove(data)" />
                        </div>
                    </template>
                </Column>
            </DataTable>

            <!-- Máy tính bảng, điện thoại: thẻ -->
            <template v-else>
                <div v-if="services.data.length" class="sv-grid">
                    <article v-for="s in services.data" :key="s.id" class="sv-card">
                        <header class="sv-card-top">
                            <span class="sv-code">{{ s.code }}</span>
                            <Tag v-if="!s.is_active" value="Ngừng bán" severity="secondary" />
                        </header>
                        <h3 class="sv-name">{{ s.name }}</h3>
                        <p v-if="s.category" class="sv-sub">{{ s.category }}</p>
                        <div class="sv-card-row"><span>Giá</span><strong class="sv-num">{{ money(s.price) }}</strong></div>
                        <div class="sv-card-row"><span>Định mức</span><strong class="sv-num">{{ quotaText(s) }}</strong></div>
                        <div class="sv-card-row"><span>Hoa hồng</span><strong class="sv-num sv-strong">{{ money(s.commission) }}</strong></div>
                        <div class="sv-row-actions">
                            <Button v-if="can('services.update')" label="Sửa" icon="pi pi-pencil" severity="secondary" outlined size="small" @click="openEdit(s)" />
                            <Button v-if="can('services.delete')" label="Xóa" icon="pi pi-trash" severity="danger" text size="small" @click="remove(s)" />
                        </div>
                    </article>
                </div>
                <p v-else class="sv-empty">{{ emptyText }}</p>

                <Paginator :first="first" :rows="services.per_page" :rowsPerPageOptions="PAGE_SIZES" :totalRecords="services.total" @page="onPage" />
            </template>
        </div>

        <AppDialog v-model:visible="dialog" :header="editing ? 'Sửa dịch vụ' : 'Thêm dịch vụ'" :style="{ width: '34rem' }" :breakpoints="{ '640px': '94vw' }">
            <form novalidate @submit.prevent="submit">
                <div class="field">
                    <label for="sv-name">Tên dịch vụ <span class="app-req" aria-hidden="true">*</span></label>
                    <InputText id="sv-name" v-model="form.name" autocomplete="off" fluid :invalid="!!form.errors.name || hasConflict" @blur="nameCheck.check()" />
                    <small v-if="form.errors.name" class="field-error">{{ form.errors.name }}</small>
                    <FieldStatus v-else :status="nameCheck.status" :message="nameCheck.message" />
                </div>

                <div class="field">
                    <FieldLabel for="sv-category">Danh mục <span class="app-req" aria-hidden="true">*</span>
                        <template #help>Bắt buộc chọn một danh mục dịch vụ. Chưa có danh mục thì tạo ở menu Dịch vụ, mục Danh mục dịch vụ trước.</template>
                    </FieldLabel>
                    <Select
                        v-model="form.service_category_id"
                        inputId="sv-category"
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

                <div class="field">
                    <label for="sv-price">Giá dịch vụ <span class="app-req" aria-hidden="true">*</span></label>
                    <InputNumber
                        v-model="form.price"
                        inputId="sv-price"
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
                </div>

                <CommissionField
                    :type="form.commission_type"
                    :value="form.commission_value"
                    :price="livePrice"
                    priceLabel="giá dịch vụ"
                    :error="form.errors.commission_value"
                    inputId="sv-quota"
                    @update:type="onTypeChange"
                    @update:value="(v) => (form.commission_value = v)"
                    @problem="(m) => (quotaOver = m)"
                />

                <div class="field">
                    <label for="sv-note">Ghi chú</label>
                    <Textarea id="sv-note" v-model="form.note" rows="2" autoResize fluid :invalid="!!form.errors.note" />
                    <small v-if="form.errors.note" class="field-error">{{ form.errors.note }}</small>
                </div>

                <div class="field" style="flex-direction: row; align-items: center; gap: 0.5rem">
                    <Checkbox v-model="form.is_active" inputId="sv-active" binary />
                    <label for="sv-active">Đang bán</label>
                </div>
                <small v-if="form.errors.is_active" class="field-error">{{ form.errors.is_active }}</small>

                <div class="dialog-actions">
                    <Button type="button" label="Hủy" severity="secondary" outlined @click="dialog = false" />
                    <Button type="submit" :label="editing ? 'Lưu thay đổi' : 'Thêm dịch vụ'" :loading="form.processing" :disabled="hasConflict || !!quotaOver" />
                </div>
            </form>
        </AppDialog>
    </AppLayout>
</template>
