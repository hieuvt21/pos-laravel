<script setup>
import { computed } from 'vue';
import Button from 'primevue/button';
import Drawer from 'primevue/drawer';
import { useMoney } from '@/composables/useMoney';

const props = defineProps({
    product: { type: Object, default: null },
    priceLists: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
    canCreate: { type: Boolean, default: false },
});
const emit = defineEmits(['edit', 'remove', 'duplicate', 'toggle']);
const visible = defineModel('visible', { type: Boolean, default: false });

const { money } = useMoney();

const defaultListId = computed(() => props.priceLists.find((l) => l.is_default)?.id);
const retail = computed(() => {
    const row = props.product?.prices.find((x) => x.price_list_id === defaultListId.value);
    return row ? Number(row.price) : null;
});

// Mọi loại giá có giá trị, bảng giá mặc định đứng đầu
const priceRows = computed(() =>
    props.priceLists
        .map((l) => ({ l, row: props.product?.prices.find((x) => x.price_list_id === l.id) }))
        .filter(({ row }) => row && Number(row.price) > 0)
        .map(({ l, row }) => ({
            id: l.id,
            name: l.name,
            price: Number(row.price),
            from: !l.is_default && props.product.qty_pricing && row.min_qty ? Number(row.min_qty) : null,
        })),
);

// Giá vốn chỉ có khi server cho phép (người có quyền sửa sản phẩm)
const cost = computed(() => (props.product?.cost_price ? Number(props.product.cost_price) : null));
const profit = computed(() => (cost.value !== null && retail.value ? retail.value - cost.value : null));
const margin = computed(() => (profit.value !== null && retail.value ? Math.round((profit.value / retail.value) * 1000) / 10 : null));

const stockText = computed(() => (props.product?.track_stock ? Number(props.product.stock ?? 0).toLocaleString('vi-VN') : '-'));

function copyBarcode() {
    const text = props.product?.barcode || props.product?.code;
    if (text && navigator.clipboard) navigator.clipboard.writeText(text).catch(() => {});
}
</script>

<template>
    <Drawer v-model:visible="visible" position="right" header="Thông tin sản phẩm" :style="{ width: 'min(27rem, 100vw)' }">
        <div v-if="product" class="pd">
            <div class="pd-head">
                <img v-if="product.image_url" :src="product.image_url" alt="" class="pd-img" />
                <span v-else class="pd-img pd-img--empty pi pi-image" aria-hidden="true" />
                <div class="pd-id">
                    <h3>{{ product.name }}</h3>
                    <span class="pd-code">{{ product.code }}</span>
                    <span class="pd-status" :class="product.is_active ? 'is-on' : 'is-off'">
                        <i class="pd-dot" aria-hidden="true" />{{ product.is_active ? 'Đang bán' : 'Ngừng bán' }}
                    </span>
                </div>
            </div>

            <div class="pd-stats">
                <div>
                    <span>Giá bán lẻ</span>
                    <strong class="pd-money">{{ retail !== null ? money(retail) : '—' }}</strong>
                </div>
                <div>
                    <span>Tồn kho</span>
                    <strong>{{ stockText }}</strong>
                </div>
                <div v-if="canEdit">
                    <span>Lãi gộp</span>
                    <strong>{{ margin !== null ? `${margin}%` : '—' }}</strong>
                </div>
            </div>

            <h4 class="pd-title">Bảng giá <span v-if="product.qty_pricing" class="pd-flag">Giá theo số lượng</span></h4>
            <ul v-if="priceRows.length" class="pd-prices">
                <li v-for="r in priceRows" :key="r.id">
                    <span>{{ r.name }}<small v-if="r.from"> · từ {{ r.from }} {{ product.unit }}</small></span>
                    <strong>{{ money(r.price) }}</strong>
                </li>
            </ul>
            <p v-else class="pd-none">Chưa có giá bán.</p>

            <div v-if="canEdit && cost !== null" class="pd-cost">
                <span>Giá vốn</span><strong>{{ money(cost) }}</strong>
                <template v-if="profit !== null"><span>Lãi mỗi {{ product.unit || 'đơn vị' }}</span><strong>{{ money(profit) }}</strong></template>
            </div>

            <dl class="pd-info">
                <div>
                    <dt><i class="pi pi-barcode" aria-hidden="true" />Mã vạch</dt>
                    <dd>
                        <template v-if="product.barcode">
                            {{ product.barcode }}
                            <button type="button" class="pd-copy" aria-label="Sao chép mã vạch" title="Sao chép" @click="copyBarcode"><i class="pi pi-copy" aria-hidden="true" /></button>
                        </template>
                        <span v-else class="pd-muted">Chưa có (quét bằng mã sản phẩm)</span>
                    </dd>
                </div>
                <div>
                    <dt><i class="pi pi-tag" aria-hidden="true" />Danh mục</dt>
                    <dd>{{ product.category || '-' }}</dd>
                </div>
                <div>
                    <dt><i class="pi pi-box" aria-hidden="true" />Đơn vị tính</dt>
                    <dd>{{ product.unit || '-' }}</dd>
                </div>
                <div>
                    <dt><i class="pi pi-percentage" aria-hidden="true" />Thuế suất</dt>
                    <dd>{{ Number(product.tax_rate) > 0 ? `${Number(product.tax_rate)}%` : 'Không chịu thuế' }}</dd>
                </div>
                <div v-if="product.description">
                    <dt><i class="pi pi-comment" aria-hidden="true" />Ghi chú</dt>
                    <dd>{{ product.description }}</dd>
                </div>
                <div>
                    <dt><i class="pi pi-clock" aria-hidden="true" />Ngày tạo</dt>
                    <dd>{{ product.created_at }}</dd>
                </div>
                <div>
                    <dt><i class="pi pi-history" aria-hidden="true" />Sửa gần nhất</dt>
                    <dd>{{ product.updated_at }}</dd>
                </div>
            </dl>

            <div v-if="canEdit || canCreate" class="pd-quick">
                <Button v-if="canEdit" :label="product.is_active ? 'Ngừng bán' : 'Bán lại'" :icon="product.is_active ? 'pi pi-pause' : 'pi pi-play'" severity="secondary" outlined size="small" @click="emit('toggle', product)" />
                <Button v-if="canCreate" label="Sao chép sản phẩm" icon="pi pi-clone" severity="secondary" outlined size="small" @click="emit('duplicate', product)" />
            </div>
        </div>

        <template #footer>
            <div v-if="product" class="pd-actions">
                <Button v-if="canEdit" label="Sửa" icon="pi pi-pencil" severity="secondary" outlined @click="emit('edit', product)" />
                <Button v-if="canDelete" label="Xóa" icon="pi pi-trash" severity="danger" text @click="emit('remove', product)" />
            </div>
        </template>
    </Drawer>
</template>

<style>
.pd { display: flex; flex-direction: column; gap: 1rem; }
.pd-head { display: flex; align-items: center; gap: 1rem; }
.pd-img { flex: none; width: 5rem; height: 5rem; border-radius: 50%; object-fit: cover; display: grid; place-items: center; box-shadow: 0 6px 14px color-mix(in srgb, var(--p-primary-700) 24%, transparent); }
.pd-img--empty { background: color-mix(in srgb, var(--app-primary) 8%, #fff); color: var(--app-muted); font-size: 1.6rem; }
.pd-id { min-width: 0; display: flex; flex-direction: column; align-items: flex-start; gap: 0.3rem; }
.pd-id h3 { margin: 0; font-size: 1.2rem; font-weight: 700; line-height: 1.25; overflow-wrap: anywhere; }
.pd-code { padding: 0.1rem 0.6rem; border-radius: 999px; background: var(--app-primary-soft); color: var(--app-primary); font-size: 0.78rem; font-weight: 600; }
.pd-status { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.84rem; }
.pd-dot { width: 0.7rem; height: 0.7rem; border-radius: 50%; background: currentColor; }
.pd-status.is-on { color: #15803d; }
.pd-status.is-off { color: #dc2626; }
.pd-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(6rem, 1fr)); gap: 0.5rem; }
.pd-stats > div { display: flex; flex-direction: column; gap: 0.2rem; padding: 0.6rem 0.7rem; border-radius: 0.8rem; background: rgba(255, 255, 255, 0.6); }
.pd-stats span { color: var(--app-muted); font-size: 0.74rem; }
.pd-stats strong { font-size: 0.95rem; }
.pd-money { color: var(--app-primary); font-variant-numeric: tabular-nums; }
.pd-title { display: flex; align-items: center; gap: 0.5rem; margin: 0.2rem 0 0; font-size: 0.95rem; font-weight: 600; }
.pd-flag { padding: 0.1rem 0.5rem; border-radius: 999px; background: color-mix(in srgb, #0284c7 14%, transparent); color: #0369a1; font-size: 0.72rem; font-weight: 600; }
.pd-prices { list-style: none; margin: 0; padding: 0; }
.pd-prices li { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; padding: 0.5rem 0; border-top: 1px solid var(--app-line); font-size: 0.9rem; }
.pd-prices small { color: var(--app-muted); }
.pd-prices strong { font-variant-numeric: tabular-nums; }
.pd-none { margin: 0; padding: 1rem; border: 2px dashed var(--app-line); border-radius: 0.8rem; text-align: center; color: var(--app-muted); font-size: 0.88rem; }
.pd-cost { display: grid; grid-template-columns: 1fr auto; gap: 0.3rem 1rem; padding: 0.7rem 0.8rem; border-radius: 0.8rem; background: var(--app-primary-soft); font-size: 0.88rem; }
.pd-cost span { color: var(--app-muted); }
.pd-cost strong { text-align: right; font-variant-numeric: tabular-nums; }
.pd-info { display: flex; flex-direction: column; gap: 0.55rem; margin: 0; font-size: 0.9rem; }
.pd-info > div { display: flex; gap: 0.7rem; }
.pd-info dt { flex: none; display: flex; align-items: flex-start; gap: 0.45rem; width: 8rem; color: var(--app-muted); }
.pd-info dt i { margin-top: 0.15rem; width: 1rem; color: var(--p-primary-600); font-size: 0.85rem; text-align: center; }
.pd-info dd { margin: 0; min-width: 0; overflow-wrap: anywhere; white-space: pre-line; }
.pd-muted { color: var(--app-muted); }
.pd-copy { margin-left: 0.3rem; min-width: 2rem; min-height: 2rem; border: 0; border-radius: 0.5rem; background: transparent; color: var(--app-primary); cursor: pointer; }
.pd-copy:focus-visible { outline: 2px solid var(--app-primary); }
.pd-quick { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.pd-actions { display: flex; justify-content: space-between; gap: 0.5rem; width: 100%; }
</style>
