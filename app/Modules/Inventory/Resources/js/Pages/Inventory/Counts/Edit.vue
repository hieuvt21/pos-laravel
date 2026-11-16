<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import AppDialog from '@/Components/AppDialog.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import '../inventory.css';

const props = defineProps({
    document: { type: Object, required: true }, // { id, code, doc_date, note }
    lines: { type: Array, default: () => [] },
    today: { type: String, required: true },
    changed: { type: Array, default: null }, // tồn đã đổi từ lúc lưu nháp (server trả khi xác nhận)
});

const nf = new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 3 });
const qty = (n) => nf.format(n);
const signed = (n) => (n > 0 ? `+${nf.format(n)}` : nf.format(n));
const round3 = (n) => Math.round(n * 1000) / 1000;
const fold = (s) => (s ?? '').toString().toLowerCase().normalize('NFD').replace(/\p{M}/gu, '').replace(/đ/g, 'd');

const form = useForm({ doc_date: props.document.doc_date, note: props.document.note ?? '', lines: props.lines.map((l) => ({ ...l })) });

const counted = (l) => l.qty !== null && l.qty !== undefined && l.qty !== '';
const diff = (l) => (counted(l) ? round3(l.qty - l.system_qty) : null);

/* ---------- Lọc và hiển thị từng đoạn (danh sách có thể hàng nghìn dòng) ---------- */
const filter = ref('');
const show = ref('all'); // all | todo | done | diff
const limit = ref(40);
const STEP = 40;

const matches = computed(() => {
    const q = fold(filter.value.trim());
    return form.lines.filter((l) => {
        if (q && !fold(l.name).includes(q) && !fold(l.code).includes(q) && !fold(l.barcode).includes(q)) return false;
        if (show.value === 'todo') return !counted(l);
        if (show.value === 'done') return counted(l);
        if (show.value === 'diff') return counted(l) && diff(l) !== 0;
        return true;
    });
});
const visible = computed(() => matches.value.slice(0, limit.value));
watch([filter, show], () => (limit.value = STEP));

const stats = computed(() => ({
    total: form.lines.length,
    done: form.lines.filter(counted).length,
    diff: form.lines.filter((l) => counted(l) && diff(l) !== 0).length,
}));
const chips = computed(() => [
    { value: 'all', label: 'Tất cả', count: stats.value.total },
    { value: 'todo', label: 'Chưa đếm', count: stats.value.total - stats.value.done },
    { value: 'done', label: 'Đã đếm', count: stats.value.done },
    { value: 'diff', label: 'Có chênh lệch', count: stats.value.diff },
]);

// Quét mã vạch vào ô lọc rồi Enter: chỉ còn một dòng thì nhảy tới ô số đếm của dòng đó
async function onFilterEnter() {
    if (matches.value.length !== 1) return;
    await nextTick();
    const input = window.document.getElementById(`cnt-${matches.value[0].product_id}`);
    input?.focus();
    input?.select();
}

/* ---------- Thêm sản phẩm ngoài danh sách ---------- */
const addQuery = ref('');
const addResults = ref([]);
let addTimer = null;
let addToken = 0;

async function searchAdd(q) {
    const mine = ++addToken;
    try {
        const res = await fetch(route('inventory.products.search', { q }), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error(String(res.status));
        const json = await res.json();
        if (mine === addToken) addResults.value = json.data;
    } catch {
        if (mine === addToken) addResults.value = [];
    }
}
watch(addQuery, (value) => {
    clearTimeout(addTimer);
    const q = value.trim();
    if (!q) {
        addToken++;
        addResults.value = [];
        return;
    }
    addTimer = setTimeout(() => searchAdd(q), 250);
});
onBeforeUnmount(() => clearTimeout(addTimer));

function addProduct(p) {
    if (!form.lines.some((l) => l.product_id === p.id)) {
        form.lines.push({
            product_id: p.id,
            code: p.code,
            name: p.name,
            barcode: p.barcode,
            unit: p.unit,
            allow_decimal: p.allow_decimal,
            system_qty: p.on_hand,
            qty: null,
        });
    }
    addQuery.value = '';
    addResults.value = [];
    show.value = 'all';
    filter.value = p.code; // đưa dòng đó lên để nhập số đếm
}

/* ---------- Lưu, xác nhận, xóa ---------- */
const payload = (d, extra = {}) => ({
    doc_date: d.doc_date,
    note: d.note,
    lines: d.lines.map((l) => ({ product_id: l.product_id, qty: counted(l) ? l.qty : null })),
    ...extra,
});

function save() {
    form.transform((d) => payload(d)).put(route('inventory.counts.update', props.document.id), { preserveScroll: true });
}

const confirmOpen = ref(false);
const changedOpen = ref(!!props.changed?.length);
watch(
    () => props.changed,
    (value) => {
        if (value?.length) changedOpen.value = true;
    },
);

function doConfirm(acknowledge = false) {
    confirmOpen.value = false;
    changedOpen.value = false;
    form.transform((d) => payload(d, { acknowledge })).post(route('inventory.counts.confirm', props.document.id), { preserveScroll: true });
}

const deleteOpen = ref(false);
function doDelete() {
    deleteOpen.value = false;
    router.delete(route('inventory.counts.destroy', props.document.id));
}

const lineErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('lines.'))
        .map(([key, message]) => `${form.lines[Number(key.split('.')[1])]?.name ?? 'Một dòng'}: ${message}`),
);
</script>

<template>
    <Head :title="`Kiểm kho ${document.code}`" />

    <AppLayout :title="`Kiểm kho ${document.code}`">
        <div class="app-panel iv-form">
            <section class="iv-meta iv-meta-count">
                <div class="field">
                    <label for="e-date">Ngày kiểm kho</label>
                    <InputText id="e-date" v-model="form.doc_date" type="date" :max="today" fluid :invalid="!!form.errors.doc_date" />
                    <small v-if="form.errors.doc_date" class="field-error">{{ form.errors.doc_date }}</small>
                </div>
                <div class="field iv-meta-note">
                    <label for="e-note">Ghi chú</label>
                    <InputText id="e-note" v-model="form.note" fluid :invalid="!!form.errors.note" />
                    <small v-if="form.errors.note" class="field-error">{{ form.errors.note }}</small>
                </div>
            </section>

            <p v-if="form.errors.lines" class="field-error iv-top-error" role="alert">{{ form.errors.lines }}</p>
            <ul v-if="lineErrors.length" class="field-error iv-top-error" role="alert">
                <li v-for="e in lineErrors" :key="e">{{ e }}</li>
            </ul>

            <section class="iv-count-tools">
                <InputText v-model="filter" placeholder="Tìm hoặc quét mã vạch, nhấn Enter để nhập số đếm" aria-label="Tìm sản phẩm trong phiếu" fluid @keydown.enter.prevent="onFilterEnter" />
                <div class="iv-chips" role="group" aria-label="Lọc dòng kiểm kho">
                    <button v-for="chip in chips" :key="chip.value" type="button" class="iv-chip" :class="{ 'is-on': show === chip.value }" :aria-pressed="show === chip.value" @click="show = chip.value">
                        {{ chip.label }} <strong>{{ chip.count }}</strong>
                    </button>
                </div>
            </section>

            <ul v-if="visible.length" class="iv-count-list">
                <li v-for="l in visible" :key="l.product_id" class="iv-count-row">
                    <div class="iv-line-name">
                        <strong>{{ l.name }}</strong>
                        <span class="iv-sub">{{ l.code }}<template v-if="l.barcode"> · {{ l.barcode }}</template></span>
                    </div>
                    <div class="iv-count-cell">
                        <span class="iv-count-label">Tồn máy</span>
                        <span>{{ qty(l.system_qty) }} {{ l.unit }}</span>
                    </div>
                    <div class="iv-count-cell">
                        <label class="iv-count-label" :for="`cnt-${l.product_id}`">Số đếm ({{ l.unit }})</label>
                        <InputNumber
                            v-model="l.qty"
                            :inputId="`cnt-${l.product_id}`"
                            :min="0"
                            :maxFractionDigits="l.allow_decimal ? 3 : 0"
                            locale="vi-VN"
                            placeholder="Chưa đếm"
                            fluid
                        />
                    </div>
                    <div class="iv-count-cell">
                        <span class="iv-count-label">Chênh lệch</span>
                        <strong v-if="diff(l) !== null" :class="diff(l) > 0 ? 'iv-plus' : diff(l) < 0 ? 'iv-minus' : ''">{{ diff(l) === 0 ? 'Khớp' : signed(diff(l)) }}</strong>
                        <span v-else class="iv-sub">—</span>
                    </div>
                </li>
            </ul>
            <p v-else class="iv-empty">Không có dòng nào khớp bộ lọc.</p>

            <div v-if="matches.length > limit" class="iv-more">
                <Button :label="`Hiện thêm (còn ${matches.length - limit} dòng)`" severity="secondary" outlined size="small" @click="limit += STEP" />
            </div>

            <section class="iv-pick">
                <label for="e-add">Thêm sản phẩm ngoài danh sách</label>
                <InputText id="e-add" v-model="addQuery" placeholder="Gõ tên, mã hoặc mã vạch" autocomplete="off" fluid />
                <ul v-if="addResults.length" class="iv-results" role="listbox" aria-label="Kết quả tìm sản phẩm">
                    <li v-for="p in addResults" :key="p.id" role="option">
                        <button type="button" class="iv-result" @click="addProduct(p)">
                            <span>
                                <strong>{{ p.name }}</strong>
                                <span class="iv-sub">{{ p.code }}</span>
                            </span>
                            <span class="iv-sub">Tồn {{ qty(p.on_hand) }} {{ p.unit }}</span>
                        </button>
                    </li>
                </ul>
            </section>

            <footer class="iv-foot">
                <div class="iv-sum">
                    <span>Đã đếm {{ stats.done }} / {{ stats.total }} sản phẩm</span>
                    <span>{{ stats.diff }} sản phẩm lệch</span>
                </div>
                <p class="iv-sub iv-rule">Sản phẩm chưa đếm sẽ được bỏ qua. Sau khi xác nhận, phiếu không sửa được. Chênh lệch tính theo tồn hiện tại lúc xác nhận.</p>
                <div class="iv-actions iv-actions-split">
                    <Button type="button" label="Xóa nháp" icon="pi pi-trash" severity="danger" text @click="deleteOpen = true" />
                    <div class="iv-actions">
                        <Button type="button" label="Lưu nháp" icon="pi pi-save" severity="secondary" outlined :loading="form.processing" @click="save" />
                        <Button type="button" label="Xác nhận kiểm kho" icon="pi pi-check" :disabled="!stats.done" :loading="form.processing" @click="confirmOpen = true" />
                    </div>
                </div>
            </footer>
        </div>

        <AppDialog v-model:visible="confirmOpen" header="Xác nhận kiểm kho" :style="{ width: '28rem' }" :breakpoints="{ '640px': '94vw' }">
            <p>
                Sẽ đưa tồn của <strong>{{ stats.done }}</strong> sản phẩm đã đếm về đúng số đếm
                (<strong>{{ stats.diff }}</strong> sản phẩm đang lệch). {{ stats.total - stats.done }} sản phẩm chưa đếm được bỏ qua.
            </p>
            <p class="iv-sub">Sau khi xác nhận, phiếu không sửa hoặc huỷ được.</p>
            <div class="iv-actions">
                <Button type="button" label="Quay lại" severity="secondary" text @click="confirmOpen = false" />
                <Button type="button" label="Xác nhận" icon="pi pi-check" @click="doConfirm(false)" />
            </div>
        </AppDialog>

        <AppDialog v-model:visible="changedOpen" header="Tồn kho đã thay đổi" :style="{ width: '36rem' }" :breakpoints="{ '640px': '96vw' }">
            <p>Từ lúc bạn lưu nháp, tồn của các sản phẩm sau đã đổi (có bán hoặc nhập hàng). Chênh lệch sẽ tính theo tồn <strong>hiện tại</strong>.</p>
            <ul class="iv-changed">
                <li v-for="c in changed ?? []" :key="c.product_id">
                    <strong>{{ c.name }}</strong>
                    <span class="iv-sub">Tồn lúc lưu nháp {{ qty(c.system_qty) }} · hiện tại {{ qty(c.current_qty) }} · số đếm {{ qty(c.counted_qty) }}</span>
                </li>
            </ul>
            <div class="iv-actions">
                <Button type="button" label="Quay lại kiểm tra" severity="secondary" text @click="changedOpen = false" />
                <Button type="button" label="Vẫn xác nhận" icon="pi pi-check" @click="doConfirm(true)" />
            </div>
        </AppDialog>

        <AppDialog v-model:visible="deleteOpen" header="Xóa phiếu nháp" :style="{ width: '26rem' }" :breakpoints="{ '640px': '94vw' }">
            <p>Xóa phiếu nháp <strong>{{ document.code }}</strong> cùng các số đếm đã nhập? Việc này không hoàn tác được.</p>
            <div class="iv-actions">
                <Button type="button" label="Hủy" severity="secondary" text @click="deleteOpen = false" />
                <Button type="button" label="Xóa nháp" icon="pi pi-trash" severity="danger" @click="doDelete" />
            </div>
        </AppDialog>
    </AppLayout>
</template>
