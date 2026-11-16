<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Tag from 'primevue/tag';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';
import '../inventory.css';

const props = defineProps({
    document: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    canCost: { type: Boolean, default: false },
    total_value: { type: Number, default: null },
});

const { money } = useMoney();
const nf = new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 3 });
const qty = (n) => (n === null || n === undefined ? '—' : nf.format(n));
const signed = (n) => (n === null || n === undefined ? '—' : n > 0 ? `+${nf.format(n)}` : nf.format(n));

const isCount = computed(() => props.document.type === 'count');
const TYPE_SEVERITY = { receipt: 'success', issue: 'warn', count: 'secondary' };
const costLabel = computed(() => (props.document.type === 'issue' ? 'Giá vốn' : 'Đơn giá nhập'));
const showTotal = computed(() => props.canCost && props.total_value !== null && !isCount.value && props.lines.some((l) => l.line_total != null));
</script>

<template>
    <Head :title="`Phiếu ${document.code}`" />

    <AppLayout :title="`Phiếu ${document.code}`">
        <div class="app-panel iv-form">
            <header class="iv-doc-head">
                <div>
                    <h2 class="iv-doc-code">{{ document.code }}</h2>
                    <Tag :value="document.type_label" :severity="TYPE_SEVERITY[document.type] ?? 'secondary'" />
                    <Tag v-if="document.status === 'draft'" value="Nháp" severity="warn" class="iv-inactive" />
                </div>
                <Button label="Về danh sách" icon="pi pi-arrow-left" severity="secondary" text @click="router.visit(route('inventory.documents.index'))" />
            </header>

            <dl class="iv-facts">
                <div><dt>Ngày phiếu</dt><dd>{{ document.doc_date }}</dd></div>
                <div><dt>Lý do</dt><dd>{{ document.reason_label || '—' }}</dd></div>
                <div v-if="document.supplier"><dt>Nhà cung cấp</dt><dd>{{ document.supplier.name }}<template v-if="document.supplier.phone"> · {{ document.supplier.phone }}</template></dd></div>
                <div><dt>Người lập</dt><dd>{{ document.by || '—' }}</dd></div>
                <div><dt>Lập lúc</dt><dd>{{ document.created_at }}</dd></div>
                <div v-if="document.note" class="iv-fact-wide"><dt>Ghi chú</dt><dd>{{ document.note }}</dd></div>
            </dl>

            <DataTable :value="lines" dataKey="id" scrollable size="small">
                <template #empty>Phiếu không có dòng nào.</template>

                <Column field="code" header="Mã" style="min-width: 7rem" />
                <Column field="name" header="Sản phẩm" style="min-width: 12rem" />
                <Column v-if="isCount" header="Tồn máy" headerClass="iv-col-right" bodyClass="iv-col-right" style="min-width: 7rem">
                    <template #body="{ data }">{{ qty(data.system_qty) }}</template>
                </Column>
                <Column :header="isCount ? 'Số đếm' : 'Số lượng'" headerClass="iv-col-right" bodyClass="iv-col-right" style="min-width: 7rem">
                    <template #body="{ data }">{{ qty(data.qty) }} {{ data.unit }}</template>
                </Column>
                <Column v-if="isCount" header="Chênh lệch" headerClass="iv-col-right" bodyClass="iv-col-right" style="min-width: 7rem">
                    <template #body="{ data }">{{ signed(data.diff_qty) }}</template>
                </Column>
                <Column v-if="canCost && !isCount" :header="costLabel" headerClass="iv-col-right" bodyClass="iv-col-right" style="min-width: 8rem">
                    <template #body="{ data }">{{ data.unit_cost != null ? money(data.unit_cost) : 'Giữ giá vốn' }}</template>
                </Column>
                <Column v-if="canCost && !isCount" header="Thành tiền" headerClass="iv-col-right" bodyClass="iv-col-right" style="min-width: 8rem">
                    <template #body="{ data }">{{ data.line_total != null ? money(data.line_total) : '—' }}</template>
                </Column>
            </DataTable>

            <p v-if="showTotal" class="iv-sum">
                Tổng giá trị:
                <strong>{{ money(total_value) }}</strong>
            </p>
        </div>
    </AppLayout>
</template>
