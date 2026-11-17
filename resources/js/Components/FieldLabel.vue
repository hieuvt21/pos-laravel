<script setup>
import HelpTip from './HelpTip.vue';

/**
 * Nhãn của một ô nhập kèm biểu tượng ? (nếu có nội dung chú thích ở slot "help").
 *   <FieldLabel for="p-code">Mã sản phẩm<template #help>Bỏ trống để tự đánh số.</template></FieldLabel>
 * Biểu tượng nằm ngoài thẻ <label> để tên của ô nhập (đọc bởi trình đọc màn hình) không bị lẫn chữ "Xem chú thích".
 */
defineProps({
    for: { type: String, default: undefined },
});
</script>

<template>
    <div class="field-label">
        <label :for="$props.for"><slot /></label>
        <HelpTip v-if="$slots.help"><slot name="help" /></HelpTip>
    </div>
</template>

<style>
.field-label { display: flex; align-items: center; gap: 0.15rem; min-width: 0; }
</style>
