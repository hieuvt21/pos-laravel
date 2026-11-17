<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useMoney } from '@/composables/useMoney';
import FieldLabel from '@/Components/FieldLabel.vue';
import '../inventory.css';

// Dùng chung cho phiếu nhập kho (kind = receipt) và phiếu xuất kho (kind = issue).
const props = defineProps({
    kind: { type: String, required: true },
    title: { type: String, required: true },
    reasons: { type: Array, default: () => [] },
    defaultReason: { type: String, default: 'other' },
    today: { type: String, required: true },
    canCost: { type: Boolean, default: false },
    suppliers: { type: Array, default: () => [] }, // chỉ phiếu nhập
});

const { money } = useMoney();
const isReceipt = props.kind === 'receipt';
const nf = new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 3 });
const qty = (n) => nf.format(n);

const form = useForm({ doc_date: props.today, reason: props.defaultReason, supplier_id: null, note: '', lines: [] });

/* ---------- Tìm sản phẩm: gõ tên/mã hoặc quét mã vạch (máy quét gõ mã rồi Enter) ---------- */
const query = ref('');
const results = ref([]);
const searching = ref(false);
const searchInput = ref(null);
let timer = null;
let token = 0;

async function runSearch(q) {
    const mine = ++token;
    searching.value = true;
    try {
        const res = await fetch(route('inventory.products.search', { q }), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error(String(res.status));
        const json = await res.json();
        if (mine === token) results.value = json.data;
        return json.data;
    } catch {
        if (mine === token) results.value = [];
        return [];
    } finally {
        if (mine === token) searching.value = false;
    }
}

watch(query, (value) => {
    clearTimeout(timer);
    const q = value.trim();
    if (!q) {
        token++;
        results.value = [];
        searching.value = false;
        return;
    }
    timer = setTimeout(() => runSearch(q), 250);
});
onBeforeUnmount(() => clearTimeout(timer));

async function onEnter() {
    const q = query.value.trim();
    if (!q) return;
    clearTimeout(timer);
    const items = await runSearch(q);
    const exact = items[0] && (items[0].barcode === q || items[0].code.toLowerCase() === q.toLowerCase());
    if (items.length === 1 || exact) add(items[0]);
}

const round3 = (n) => Math.round(n * 1000) / 1000;

function add(p) {
    const existing = form.lines.find((l) => l.product_id === p.id);
    if (existing) {
        existing.qty = round3((existing.qty ?? 0) + 1); // quét lại thì cộng thêm 1
    } else {
        form.lines.push({
            product_id: p.id,
            code: p.code,
            name: p.name,
            unit: p.unit,
            allow_decimal: p.allow_decimal,
            on_hand: p.on_hand,
            qty: 1,
            unit_cost: isReceipt && props.canCost ? p.cost_price : null,
        });
    }
    query.value = '';
    results.value = [];
    nextTick(() => searchInput.value?.$el?.focus());
}

function remove(index) {
    form.lines.splice(index, 1);
}

/* ---------- Tổng và gửi phiếu ---------- */
const totalValue = computed(() => form.lines.reduce((sum, l) => sum + (l.unit_cost != null ? (l.qty ?? 0) * l.unit_cost : 0), 0));
const hasCost = computed(() => isReceipt && form.lines.some((l) => l.unit_cost != null));

const lineError = (i, field) => form.errors[`lines.${i}.${field}`];
const topError = computed(() => form.errors.lines || Object.entries(form.errors).find(([k]) => k.startsWith('lines.') && k.endsWith('product_id'))?.[1]);

function submit() {
    form
        .transform((d) => ({
            ...d,
            supplier_id: isReceipt ? d.supplier_id : undefined,
            lines: d.lines.map((l) => ({ product_id: l.product_id, qty: l.qty, ...(isReceipt ? { unit_cost: l.unit_cost } : {}) })),
        }))
        .post(route(isReceipt ? 'inventory.receipts.store' : 'inventory.issues.store'), { preserveScroll: true });
}
</script>

<template>
    <Head :title="title" />

    <AppLayout :title="title">
        <form class="app-panel iv-form" novalidate @submit.prevent="submit">
            <div v-if="isReceipt" class="iv-import-link">
                <Button type="button" label="Nhập nhiều sản phẩm từ Excel" icon="pi pi-file-excel" text size="small" @click="router.visit(route('inventory.import.index'))" />
            </div>
            <section class="iv-meta" :class="{ 'iv-meta-4': isReceipt }">
                <div class="field">
                    <label for="d-date">Ngày phiếu</label>
                    <InputText id="d-date" v-model="form.doc_date" type="date" :max="today" fluid :invalid="!!form.errors.doc_date" />
                    <small v-if="form.errors.doc_date" class="field-error">{{ form.errors.doc_date }}</small>
                </div>
                <div class="field">
                    <label for="d-reason">Lý do</label>
                    <Select
                        v-model="form.reason"
                        inputId="d-reason"
                        :options="reasons"
                        optionLabel="label"
                        optionValue="value"
                        fluid
                        :invalid="!!form.errors.reason"
                    />
                    <small v-if="form.errors.reason" class="field-error">{{ form.errors.reason }}</small>
                </div>
                <div v-if="isReceipt" class="field">
                    <FieldLabel for="d-supplier">Nhà cung cấp
                        <template #help>Không bắt buộc. Chọn nhà cung cấp để biết hàng nhập từ đâu. Chưa có nhà cung cấp thì thêm ở menu Kho, mục Nhà cung cấp.</template>
                    </FieldLabel>
                    <Select
                        v-model="form.supplier_id"
                        inputId="d-supplier"
                        :options="suppliers"
                        optionLabel="name"
                        optionValue="id"
                        placeholder="Không chọn"
                        filter
                        showClear
                        fluid
                        :invalid="!!form.errors.supplier_id"
                    />
                    <small v-if="form.errors.supplier_id" class="field-error">{{ form.errors.supplier_id }}</small>
                </div>
                <div class="field iv-meta-note">
                    <label for="d-note">Ghi chú</label>
                    <Textarea id="d-note" v-model="form.note" rows="1" autoResize fluid :invalid="!!form.errors.note" />
                    <small v-if="form.errors.note" class="field-error">{{ form.errors.note }}</small>
                </div>
            </section>

            <section class="iv-pick">
                <label for="d-search">Thêm sản phẩm</label>
                <InputText
                    id="d-search"
                    ref="searchInput"
                    v-model="query"
                    placeholder="Gõ tên, mã hoặc quét mã vạch rồi nhấn Enter"
                    autocomplete="off"
                    fluid
                    @keydown.enter.prevent="onEnter"
                />
                <ul v-if="results.length" class="iv-results" role="listbox" aria-label="Kết quả tìm sản phẩm">
                    <li v-for="p in results" :key="p.id" role="option">
                        <button type="button" class="iv-result" @click="add(p)">
                            <span>
                                <strong>{{ p.name }}</strong>
                                <Tag v-if="!p.is_active" value="Ngừng bán" severity="secondary" class="iv-inactive" />
                                <span class="iv-sub">{{ p.code }}<template v-if="p.barcode"> · {{ p.barcode }}</template></span>
                            </span>
                            <span class="iv-sub">Tồn {{ qty(p.on_hand) }} {{ p.unit }}</span>
                        </button>
                    </li>
                </ul>
                <p v-else-if="query.trim() && !searching" class="iv-sub iv-noresult">
                    Không thấy sản phẩm nào đang quản lý tồn kho khớp "{{ query.trim() }}".
                </p>
            </section>

            <p v-if="topError" class="field-error iv-top-error" role="alert">{{ topError }}</p>

            <p v-if="!form.lines.length" class="iv-empty">Chưa có sản phẩm nào trong phiếu. Tìm và thêm sản phẩm ở trên.</p>

            <ul v-else class="iv-lines">
                <li v-for="(l, i) in form.lines" :key="l.product_id" class="iv-line">
                    <div class="iv-line-name">
                        <strong>{{ l.name }}</strong>
                        <span class="iv-sub">{{ l.code }} · Tồn {{ qty(l.on_hand) }} {{ l.unit }}</span>
                    </div>
                    <div class="iv-line-field">
                        <label :for="`q-${i}`">Số lượng ({{ l.unit }})</label>
                        <InputNumber
                            v-model="l.qty"
                            :inputId="`q-${i}`"
                            :min="0"
                            :maxFractionDigits="l.allow_decimal ? 3 : 0"
                            locale="vi-VN"
                            fluid
                            :invalid="!!lineError(i, 'qty')"
                        />
                        <small v-if="lineError(i, 'qty')" class="field-error">{{ lineError(i, 'qty') }}</small>
                    </div>
                    <div v-if="isReceipt" class="iv-line-field">
                        <label :for="`c-${i}`">Đơn giá nhập</label>
                        <InputNumber
                            v-model="l.unit_cost"
                            :inputId="`c-${i}`"
                            :min="0"
                            :maxFractionDigits="2"
                            locale="vi-VN"
                            placeholder="Giữ giá vốn"
                            fluid
                            :invalid="!!lineError(i, 'unit_cost')"
                        />
                        <small v-if="lineError(i, 'unit_cost')" class="field-error">{{ lineError(i, 'unit_cost') }}</small>
                    </div>
                    <Button
                        type="button"
                        icon="pi pi-trash"
                        text
                        rounded
                        severity="danger"
                        :aria-label="`Bỏ ${l.name} khỏi phiếu`"
                        @click="remove(i)"
                    />
                </li>
            </ul>

            <footer class="iv-foot">
                <div class="iv-sum">
                    <span>{{ form.lines.length }} mặt hàng</span>
                    <span v-if="hasCost">
                        Giá trị nhập:
                        <strong>{{ money(totalValue) }}</strong>
                    </span>
                </div>
                <p class="iv-sub iv-rule">Phiếu đã lập không sửa hoặc huỷ được. Nếu lập sai, hãy lập phiếu ngược lại hoặc kiểm kho.</p>
                <div class="iv-actions">
                    <Button type="button" label="Hủy" severity="secondary" text @click="router.visit(route('inventory.documents.index'))" />
                    <Button type="submit" :label="isReceipt ? 'Lập phiếu nhập' : 'Lập phiếu xuất'" icon="pi pi-check" :loading="form.processing" :disabled="!form.lines.length" />
                </div>
            </footer>
        </form>
    </AppLayout>
</template>
