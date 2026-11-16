<script setup>
import { computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import AppLayout from '@/Layouts/AppLayout.vue';
import '../inventory.css';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    today: { type: String, required: true },
    drafts: { type: Array, default: () => [] },
});

const ALL = 'all'; // PrimeVue Select không hiện lựa chọn có giá trị null
const form = useForm({ category_id: ALL, doc_date: props.today, note: '' });
const options = computed(() => [{ value: ALL, label: 'Tất cả sản phẩm đang quản lý tồn kho' }, ...props.categories.map((c) => ({ value: c.id, label: `Danh mục: ${c.name}` }))]);

function submit() {
    form
        .transform((d) => ({ ...d, category_id: d.category_id === ALL ? null : d.category_id }))
        .post(route('inventory.counts.store'));
}
</script>

<template>
    <Head title="Kiểm kho" />

    <AppLayout title="Kiểm kho">
        <div class="app-panel iv-form">
            <section v-if="drafts.length" class="iv-drafts" aria-label="Phiếu kiểm kho đang nháp">
                <h2 class="iv-h2">Phiếu đang nháp</h2>
                <ul class="iv-draft-list">
                    <li v-for="d in drafts" :key="d.id" class="iv-draft">
                        <div>
                            <strong>{{ d.code }}</strong>
                            <span class="iv-sub"> · {{ d.doc_date }}<template v-if="d.by"> · {{ d.by }}</template></span>
                            <div class="iv-sub">Đã đếm {{ d.counted_count }} / {{ d.lines_count }} sản phẩm</div>
                        </div>
                        <Button label="Tiếp tục" icon="pi pi-arrow-right" iconPos="right" size="small" @click="router.visit(route('inventory.counts.edit', d.id))" />
                    </li>
                </ul>
            </section>

            <form novalidate class="iv-new" @submit.prevent="submit">
                <h2 class="iv-h2">Tạo phiếu kiểm kho mới</h2>
                <p class="iv-sub iv-lead">
                    Hệ thống lấy sẵn danh sách sản phẩm kèm tồn trong máy. Bạn đếm hàng thật, nhập số đếm, rồi xác nhận để đưa tồn về đúng số thật.
                </p>

                <div class="field">
                    <label for="k-scope">Phạm vi kiểm</label>
                    <Select v-model="form.category_id" inputId="k-scope" :options="options" optionLabel="label" optionValue="value" fluid :invalid="!!form.errors.category_id" />
                    <small v-if="form.errors.category_id" class="field-error">{{ form.errors.category_id }}</small>
                </div>
                <div class="field">
                    <label for="k-date">Ngày kiểm kho</label>
                    <InputText id="k-date" v-model="form.doc_date" type="date" :max="today" fluid :invalid="!!form.errors.doc_date" />
                    <small v-if="form.errors.doc_date" class="field-error">{{ form.errors.doc_date }}</small>
                </div>
                <div class="field">
                    <label for="k-note">Ghi chú</label>
                    <InputText id="k-note" v-model="form.note" placeholder="Ví dụ: Kiểm kho cuối tháng" fluid :invalid="!!form.errors.note" />
                    <small v-if="form.errors.note" class="field-error">{{ form.errors.note }}</small>
                </div>

                <div class="iv-actions">
                    <Button type="submit" label="Bắt đầu kiểm kho" icon="pi pi-play" :loading="form.processing" />
                </div>
            </form>
        </div>
    </AppLayout>
</template>
