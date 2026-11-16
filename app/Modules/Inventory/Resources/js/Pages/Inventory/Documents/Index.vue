<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import Paginator from 'primevue/paginator';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import AppLayout from '@/Layouts/AppLayout.vue';
import { usePermission } from '@/composables/usePermission';
import '../inventory.css';

const props = defineProps({
    documents: { type: Object, required: true },
    filters: { type: Object, default: () => ({ search: '', type: null, from: null, to: null }) },
    types: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
});

const { can } = usePermission();

const media = window.matchMedia('(min-width: 1024px)');
const isDesktop = ref(media.matches);
const onMedia = (e) => (isDesktop.value = e.matches);
media.addEventListener('change', onMedia);
onBeforeUnmount(() => media.removeEventListener('change', onMedia));

const ALL = 'all';
const search = ref(props.filters.search ?? '');
const type = ref(props.filters.type ?? ALL);
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');
const supplier = ref(props.filters.supplier ?? ALL);
const typeOptions = computed(() => [{ value: ALL, label: 'Tất cả loại phiếu' }, ...props.types]);
const supplierOptions = computed(() => [{ value: ALL, label: 'Mọi nhà cung cấp' }, ...props.suppliers.map((s) => ({ value: s.id, label: s.name }))]);

const PAGE_KEY = 'pos.perPage';
const PAGE_SIZES = [10, 15, 30, 50, 100];
const perPage = ref(props.documents.per_page);

const visit = (extra = {}) =>
    router.get(
        route('inventory.documents.index'),
        {
            per_page: perPage.value,
            search: search.value || undefined,
            type: type.value !== ALL ? type.value : undefined,
            from: from.value || undefined,
            to: to.value || undefined,
            supplier: supplier.value !== ALL ? supplier.value : undefined,
            ...extra,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );

let timer = null;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => visit(), 300);
});
watch([type, from, to, supplier], () => visit());
onBeforeUnmount(() => clearTimeout(timer));

function onPage(e) {
    const changed = e.rows !== perPage.value;
    perPage.value = e.rows;
    if (changed) {
        try {
            localStorage.setItem(PAGE_KEY, String(e.rows));
        } catch {
            // bỏ qua
        }
    }
    visit({ page: changed ? undefined : e.page + 1 });
}
onMounted(() => {
    try {
        const saved = Number(localStorage.getItem(PAGE_KEY));
        if (PAGE_SIZES.includes(saved) && saved !== props.documents.per_page) {
            perPage.value = saved;
            visit();
        }
    } catch {
        // bỏ qua
    }
});
const first = computed(() => (props.documents.current_page - 1) * props.documents.per_page);

const TYPE_SEVERITY = { receipt: 'success', issue: 'warn', count: 'secondary' };
// Phiếu kiểm kho còn nháp: mở trang nhập số đếm (người có quyền kiểm kho)
const open = (d) => router.visit(d.status === 'draft' && can('inventory.count') ? route('inventory.counts.edit', d.id) : route('inventory.documents.show', d.id));
const hasFilter = computed(() => !!(search.value || type.value !== ALL || supplier.value !== ALL || from.value || to.value));
const emptyText = computed(() => (hasFilter.value ? 'Không có phiếu nào khớp với bộ lọc.' : 'Chưa có phiếu kho nào. Lập phiếu nhập kho để bắt đầu.'));
</script>

<template>
    <Head title="Phiếu kho" />

    <AppLayout title="Phiếu kho">
        <div class="app-panel app-fill">
            <div class="app-toolbar">
                <IconField class="iv-search">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="search" placeholder="Tìm theo mã phiếu" aria-label="Tìm phiếu theo mã" fluid />
                </IconField>
                <Select v-model="type" :options="typeOptions" optionLabel="label" optionValue="value" aria-label="Lọc theo loại phiếu" style="min-width: 11rem" />
                <Select
                    v-if="suppliers.length"
                    v-model="supplier"
                    :options="supplierOptions"
                    optionLabel="label"
                    optionValue="value"
                    filter
                    aria-label="Lọc theo nhà cung cấp"
                    style="min-width: 12rem"
                />
                <label class="iv-date">
                    Từ
                    <InputText v-model="from" type="date" :max="to || undefined" aria-label="Từ ngày" />
                </label>
                <label class="iv-date">
                    Đến
                    <InputText v-model="to" type="date" :min="from || undefined" aria-label="Đến ngày" />
                </label>
                <Button v-if="can('inventory.receive')" icon="pi pi-download" label="Nhập kho" @click="router.visit(route('inventory.receipts.create'))" />
                <Button
                    v-if="can('inventory.count')"
                    icon="pi pi-check-square"
                    label="Kiểm kho"
                    severity="secondary"
                    outlined
                    @click="router.visit(route('inventory.counts.create'))"
                />
                <Button
                    v-if="can('inventory.issue')"
                    icon="pi pi-upload"
                    label="Xuất kho"
                    severity="secondary"
                    outlined
                    @click="router.visit(route('inventory.issues.create'))"
                />
            </div>

            <DataTable
                v-if="isDesktop"
                :value="documents.data"
                dataKey="id"
                lazy
                paginator
                :rowsPerPageOptions="PAGE_SIZES"
                :rows="documents.per_page"
                :first="first"
                :totalRecords="documents.total"
                @page="onPage"
            >
                <template #empty>{{ emptyText }}</template>

                <Column header="Mã phiếu" style="width: 11rem">
                    <template #body="{ data }">
                        <button type="button" class="iv-link" @click="open(data)">{{ data.code }}</button>
                        <Tag v-if="data.status === 'draft'" value="Nháp" severity="warn" class="iv-inactive" />
                    </template>
                </Column>
                <Column header="Loại" style="width: 10rem">
                    <template #body="{ data }">
                        <Tag :value="data.type_label" :severity="TYPE_SEVERITY[data.type] ?? 'secondary'" />
                    </template>
                </Column>
                <Column field="doc_date" header="Ngày phiếu" style="width: 9rem" />
                <Column field="reason_label" header="Lý do" />
                <Column header="Nhà cung cấp">
                    <template #body="{ data }">{{ data.supplier || '—' }}</template>
                </Column>
                <Column header="Số mặt hàng" headerClass="iv-col-right" bodyClass="iv-col-right" style="width: 9rem">
                    <template #body="{ data }">{{ data.lines_count }}</template>
                </Column>
                <Column header="Người lập">
                    <template #body="{ data }">{{ data.by || '—' }}</template>
                </Column>
                <Column header="" style="width: 4rem">
                    <template #body="{ data }">
                        <Button icon="pi pi-eye" text rounded severity="secondary" :aria-label="`Xem phiếu ${data.code}`" @click="open(data)" />
                    </template>
                </Column>
            </DataTable>

            <template v-else>
                <div v-if="documents.data.length" class="iv-grid">
                    <article v-for="d in documents.data" :key="d.id" class="iv-card" @click="open(d)">
                        <header class="iv-card-top">
                            <span class="iv-code">{{ d.code }}</span>
                            <span>
                                <Tag v-if="d.status === 'draft'" value="Nháp" severity="warn" class="iv-inactive" />
                                <Tag :value="d.type_label" :severity="TYPE_SEVERITY[d.type] ?? 'secondary'" />
                            </span>
                        </header>
                        <div class="iv-card-qty"><span>Ngày phiếu</span><strong>{{ d.doc_date }}</strong></div>
                        <div class="iv-card-qty"><span>Lý do</span><strong>{{ d.reason_label || '—' }}</strong></div>
                        <div v-if="d.supplier" class="iv-card-qty"><span>Nhà cung cấp</span><strong>{{ d.supplier }}</strong></div>
                        <div class="iv-card-qty"><span>Số mặt hàng</span><strong>{{ d.lines_count }}</strong></div>
                        <p v-if="d.by" class="iv-sub">Người lập: {{ d.by }}</p>
                        <Button label="Xem phiếu" icon="pi pi-eye" severity="secondary" outlined size="small" @click.stop="open(d)" />
                    </article>
                </div>
                <p v-else class="iv-empty">{{ emptyText }}</p>

                <Paginator :first="first" :rows="documents.per_page" :rowsPerPageOptions="PAGE_SIZES" :totalRecords="documents.total" @page="onPage" />
            </template>
        </div>
    </AppLayout>
</template>
