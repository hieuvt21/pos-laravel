<script setup>
import { ref } from 'vue';
import Popover from 'primevue/popover';

/**
 * Biểu tượng ? đặt cạnh tiêu đề; bấm vào thì hiện nội dung chú thích trong một khung nổi
 * (bấm ra ngoài hoặc nhấn Esc để đóng). Dùng thay cho các dòng chú thích nhỏ dưới ô nhập.
 * Dùng trực tiếp cạnh tiêu đề/nhãn dạng công tắc, hoặc qua <FieldLabel> cho nhãn của ô nhập.
 */
defineProps({
    label: { type: String, default: 'Xem chú thích' }, // tên của nút cho trình đọc màn hình
});

const popover = ref(null);
const open = ref(false);

function toggle(event) {
    popover.value?.toggle(event);
}
</script>

<template>
    <span class="help-tip">
        <button type="button" class="help-tip-btn" :aria-label="label" aria-haspopup="true" :aria-expanded="open" @click.stop.prevent="toggle">
            <i class="pi pi-question-circle" aria-hidden="true" />
        </button>
        <Popover ref="popover" class="help-tip-pop" @show="open = true" @hide="open = false">
            <div class="help-tip-body"><slot /></div>
        </Popover>
    </span>
</template>

<style>
.help-tip { display: inline-flex; align-items: center; vertical-align: middle; }
/* Nút 24px (đủ lớn để chạm) nhưng cao ngoài chỉ 1rem nhờ margin âm, nên không làm dòng tiêu đề cao thêm */
.help-tip-btn { display: inline-flex; align-items: center; justify-content: center; width: 1.5rem; height: 1.5rem; margin: -0.25rem 0; padding: 0; border: 0; border-radius: 999px; background: transparent; color: var(--app-muted); font-size: 1rem; line-height: 1; cursor: pointer; }
.help-tip-btn:hover, .help-tip-btn[aria-expanded="true"] { color: var(--app-primary); background: var(--app-primary-soft); }
.help-tip-btn:focus-visible { outline: 2px solid var(--app-primary); outline-offset: 1px; }
.help-tip-body { max-width: 19rem; font-size: 0.88rem; font-weight: 400; line-height: 1.5; text-transform: none; }
</style>
