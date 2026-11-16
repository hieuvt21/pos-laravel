<script setup>
import { ref, watch } from 'vue';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Paginator from 'primevue/paginator';
import Tag from 'primevue/tag';
import AppDialog from '@/Components/AppDialog.vue';
import { useMoney } from '@/composables/useMoney';
import '../inventory.css';

// Thẻ kho: lịch sử nhập xuất của một sản phẩm, mới nhất trước, kèm tồn sau mỗi dòng.
const props = defineProps({
    visible: { type: Boolean, default: false },
    product: { type: Object, default: null }, // { id, name }
    canCost: { type: Boolean, default: false },
});
const emit = defineEmits(['update:visible']);

const { money } = useMoney();
const nf = new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 3 });
const qty = (n) => nf.format(n);
const signed = (n) => (n > 0 ? `+${nf.format(n)}` : nf.format(n));

const data = ref(null);
const loading = ref(false);
const error = ref('');
let token = 0; // bỏ qua kết quả của lần tải cũ khi người dùng đổi sản phẩm/trang nhanh

async function load(page = 1) {
    if (!props.product) return;
    const mine = ++token;
    loading.value = true;
    error.value = '';
    try {
        const res = await fetch(route('inventory.card', { productId: props.product.id, page }), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error(String(res.status));
        const json = await res.json();
        if (mine === token) data.value = json;
    } catch {
        if (mine === token) error.value = 'Không tải được thẻ kho. Bấm "Tải lại" để thử lần nữa.';
    } finally {
        if (mine === token) loading.value = false;
    }
}

watch(
    () => [props.visible, props.product?.id],
    ([open]) => {
        if (open) {
            data.value = null;
            load(1);
        }
    },
    { immediate: true },
);

const typeSeverity = { receipt: 'success', return: 'info', issue: 'warn', count: 'secondary' };
const onPage = (e) => load(e.page + 1);
</script>

<template>
    <AppDialog
        :visible="visible"
        :header="product ? `Thẻ kho: ${product.name}` : 'Thẻ kho'"
        :style="{ width: '52rem' }"
        :breakpoints="{ '1024px': '92vw', '640px': '96vw' }"
        @update:visible="(v) => emit('update:visible', v)"
    >
        <p v-if="data" class="iv-now">
            Tồn hiện tại:
            <strong>{{ qty(data.product.on_hand) }}</strong>
            {{ data.product.unit }}
        </p>

        <p v-if="error" class="field-error" role="alert">
            {{ error }}
            <button type="button" class="iv-retry" @click="load(1)">Tải lại</button>
        </p>

        <DataTable v-else :value="data?.movements.data ?? []" :loading="loading" dataKey="id" scrollable scrollHeight="55vh" size="small">
            <template #empty>{{ loading ? 'Đang tải...' : 'Sản phẩm này chưa có phát sinh nhập xuất.' }}</template>

            <Column header="Thời gian" style="min-width: 9rem">
                <template #body="{ data: m }">{{ m.at }}</template>
            </Column>
            <Column header="Nghiệp vụ" style="min-width: 12rem">
                <template #body="{ data: m }">
                    <div class="iv-kind">
                        <Tag :value="m.type_label" :severity="typeSeverity[m.type] ?? 'secondary'" />
                        <span v-if="m.reason_label && m.reason_label !== m.type_label" class="iv-sub">{{ m.reason_label }}</span>
                        <span v-if="m.document" class="iv-sub">{{ m.document }}</span>
                        <span v-if="m.supplier" class="iv-sub">NCC: {{ m.supplier }}</span>
                    </div>
                </template>
            </Column>
            <Column header="Số lượng" headerClass="iv-col-right" bodyClass="iv-col-right" style="min-width: 7rem">
                <template #body="{ data: m }">
                    <strong :class="m.qty > 0 ? 'iv-plus' : 'iv-minus'">{{ signed(m.qty) }}</strong>
                </template>
            </Column>
            <Column header="Tồn sau" headerClass="iv-col-right" bodyClass="iv-col-right" style="min-width: 6.5rem">
                <template #body="{ data: m }">{{ qty(m.balance_after) }}</template>
            </Column>
            <Column v-if="canCost" header="Giá vốn" headerClass="iv-col-right" bodyClass="iv-col-right" style="min-width: 8rem">
                <template #body="{ data: m }">{{ money(m.unit_cost) }}</template>
            </Column>
            <Column header="Người lập" style="min-width: 8rem">
                <template #body="{ data: m }">{{ m.by || '—' }}</template>
            </Column>
        </DataTable>

        <Paginator
            v-if="data && data.movements.total > data.movements.per_page"
            :first="(data.movements.current_page - 1) * data.movements.per_page"
            :rows="data.movements.per_page"
            :totalRecords="data.movements.total"
            @page="onPage"
        />
    </AppDialog>
</template>
