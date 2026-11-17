<script setup>
import { computed, ref, watch } from 'vue';
import Button from 'primevue/button';
import InputGroup from 'primevue/inputgroup';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { useMoney } from '@/composables/useMoney';
import FieldLabel from '@/Components/FieldLabel.vue';

/**
 * Định mức hoa hồng nhân viên: một ô nhập số có hai nút ₫ / % gắn liền, và ô Hoa hồng (chỉ đọc) trên cùng một dòng.
 * - ₫: hoa hồng bằng đúng số tiền định mức. %: hoa hồng bằng % của giá bán (tối đa 100).
 * - Hoa hồng tính lại sau từng phím bấm; không bao giờ vượt giá. Quy tắc giống `Core\Support\Commission`.
 * Dùng chung cho Dịch vụ và Sản phẩm. Cha giữ `type`, `value` và truyền `price` (giá đang gõ ở form).
 */
const props = defineProps({
    type: { type: String, default: 'amount' }, // 'amount' | 'percent'
    value: { type: Number, default: null }, // định mức
    price: { type: Number, default: null }, // giá dùng để tính hoa hồng (giá đang gõ)
    priceLabel: { type: String, default: 'giá' }, // dùng trong thông báo, ví dụ "giá dịch vụ", "giá bán lẻ"
    error: { type: String, default: '' }, // lỗi từ server
    inputId: { type: String, default: 'commission-quota' },
});
const emit = defineEmits(['update:type', 'update:value', 'problem']);

const { money, config } = useMoney();
const fractionDigits = computed(() => Number(config.value.decimals) || 0);
const nf = new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 2 });

const isPercent = computed(() => props.type === 'percent');

// Định mức "đang gõ": PrimeVue chỉ cập nhật v-model khi rời ô, nên lấy giá trị ngay từ sự kiện input
const liveQuota = ref(props.value);
watch(() => props.value, (v) => (liveQuota.value = v));
const onInput = (e) => (liveQuota.value = e.value);

const basePrice = computed(() => Number(props.price) || 0);
const quota = computed(() => Number(liveQuota.value) || 0);

// Giới hạn ô định mức: % tối đa 100, tiền tối đa bằng giá
const quotaMax = computed(() => (isPercent.value ? 100 : (props.price ?? undefined)));

const commission = computed(() => {
    const raw = isPercent.value ? (basePrice.value * quota.value) / 100 : quota.value;
    const factor = 10 ** fractionDigits.value;
    return Math.round(Math.min(Math.max(raw, 0), basePrice.value) * factor) / factor;
});

const hint = computed(() => {
    if (!isPercent.value) return 'Hoa hồng bằng đúng số tiền định mức.';
    return `Hoa hồng = ${nf.format(quota.value)}% của ${props.priceLabel} ${money(basePrice.value)}.`;
});

// Báo ngay khi vượt giới hạn (server cũng kiểm tra lại khi lưu)
const problem = computed(() => {
    if (isPercent.value) return quota.value > 100 ? 'Phần trăm hoa hồng không được vượt quá 100%.' : null;
    return quota.value > basePrice.value ? `Hoa hồng không được lớn hơn ${props.priceLabel}.` : null;
});
watch(problem, (v) => emit('problem', v), { immediate: true });

const message = computed(() => props.error || problem.value);

// Chuyển ₫ / %: xóa số đã nhập để tránh hiểu nhầm giá trị cũ
function setType(type) {
    if (type === props.type) return;
    emit('update:type', type);
    emit('update:value', null);
    liveQuota.value = null;
}
</script>

<template>
    <div class="cf">
        <div class="cf-pair">
            <div class="field">
                <FieldLabel :for="inputId">
                    Định mức hoa hồng
                    <template #help>
                        Chọn ₫ để nhập số tiền cố định, chọn % để tính theo {{ priceLabel }} (tối đa 100%). Hoa hồng tự tính, không sửa được. {{ hint }}
                    </template>
                </FieldLabel>
                <InputGroup>
                    <InputNumber
                        :modelValue="value"
                        :inputId="inputId"
                        :min="0"
                        :max="quotaMax"
                        :minFractionDigits="0"
                        :maxFractionDigits="isPercent ? 2 : fractionDigits"
                        locale="vi-VN"
                        placeholder="0"
                        fluid
                        :invalid="!!message"
                        @update:modelValue="(v) => emit('update:value', v)"
                        @input="onInput"
                    />
                    <Button
                        type="button"
                        label="₫"
                        aria-label="Định mức tính bằng tiền"
                        :aria-pressed="!isPercent"
                        :severity="isPercent ? 'secondary' : 'primary'"
                        :outlined="isPercent"
                        @click="setType('amount')"
                    />
                    <Button
                        type="button"
                        label="%"
                        aria-label="Định mức tính theo phần trăm giá bán"
                        :aria-pressed="isPercent"
                        :severity="isPercent ? 'primary' : 'secondary'"
                        :outlined="!isPercent"
                        @click="setType('percent')"
                    />
                </InputGroup>
            </div>

            <div class="field">
                <FieldLabel :for="`${inputId}-result`">Hoa hồng</FieldLabel>
                <InputText :id="`${inputId}-result`" class="cf-readonly" :modelValue="money(commission)" readonly aria-readonly="true" fluid />
            </div>
        </div>
        <small v-if="message" class="field-error cf-note" role="alert">{{ message }}</small>
    </div>
</template>

<style>
/* Định mức (ô nhập kèm nút ₫ / %) và Hoa hồng (chỉ đọc) nằm cùng một dòng */
.cf-pair { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr); gap: 0 0.75rem; align-items: start; }
.cf-pair .p-inputgroup { flex-wrap: nowrap; }
.cf-pair .p-inputgroup .p-inputnumber { flex: 1 1 auto; min-width: 0; }
.cf-pair .p-inputgroup .p-button { flex: 0 0 auto; min-width: 2.4rem; padding-inline: 0.6rem; }
.cf-note { display: block; margin: -0.35rem 0 0.9rem; }
.cf-readonly { background: var(--app-primary-soft) !important; font-weight: 700; cursor: default; }
</style>
