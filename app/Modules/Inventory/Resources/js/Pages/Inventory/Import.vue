<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Select from 'primevue/select';
import AppLayout from '@/Layouts/AppLayout.vue';
import './inventory.css';

const props = defineProps({
    result: { type: Object, default: null }, // { created, errors: [{row, message}], more_errors }
    maxRows: { type: Number, default: 2000 },
    reasons: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
});

const form = useForm({ file: null, reason: 'opening', supplier_id: null });

function onFile(e) {
    form.file = e.target.files?.[0] ?? null;
}
function submit() {
    form.post(route('inventory.import.store'), { forceFormData: true, preserveScroll: true });
}
</script>

<template>
    <Head title="Nhập kho từ Excel" />

    <AppLayout title="Nhập kho từ Excel">
        <div class="app-panel iv-form">
            <section>
                <h2 class="iv-h2">Nhập nhiều sản phẩm một lần</h2>
                <ul class="iv-help">
                    <li>Dùng file mẫu: cột <strong>Mã sản phẩm hoặc mã vạch</strong>, <strong>Số lượng</strong> và <strong>Đơn giá nhập</strong> (không bắt buộc, để trống thì giữ giá vốn).</li>
                    <li>Sản phẩm phải có sẵn và đã bật "Quản lý tồn kho". Mỗi sản phẩm một dòng, tối đa {{ maxRows.toLocaleString('vi-VN') }} dòng.</li>
                    <li>Số lẻ chỉ nhận với đơn vị cho phép (như kg). Nhận file .xlsx hoặc .csv, tối đa 5 MB.</li>
                    <li>Cả file là <strong>một phiếu nhập</strong>. Có dòng lỗi thì không nhập gì cả, bạn sửa file rồi nhập lại.</li>
                </ul>
                <Button as="a" :href="route('inventory.import.template')" label="Tải file mẫu" icon="pi pi-download" severity="secondary" outlined />
            </section>

            <form novalidate class="iv-new" @submit.prevent="submit">
                <div class="field">
                    <label for="i-file">File Excel hoặc CSV</label>
                    <input id="i-file" class="iv-file" type="file" accept=".xlsx,.csv" @change="onFile" />
                    <small v-if="form.errors.file" class="field-error" role="alert">{{ form.errors.file }}</small>
                </div>
                <div class="field">
                    <label for="i-reason">Lý do nhập</label>
                    <Select v-model="form.reason" inputId="i-reason" :options="reasons" optionLabel="label" optionValue="value" fluid :invalid="!!form.errors.reason" />
                    <small v-if="form.errors.reason" class="field-error">{{ form.errors.reason }}</small>
                </div>
                <div class="field">
                    <label for="i-supplier">Nhà cung cấp (không bắt buộc)</label>
                    <Select
                        v-model="form.supplier_id"
                        inputId="i-supplier"
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
                <div class="iv-actions">
                    <Button type="button" label="Hủy" severity="secondary" text @click="router.visit(route('inventory.index'))" />
                    <Button type="submit" label="Nhập vào kho" icon="pi pi-upload" :loading="form.processing" :disabled="!form.file" />
                </div>
            </form>

            <section v-if="result?.errors?.length" aria-label="Các dòng lỗi">
                <h2 class="iv-h2">Các dòng lỗi trong file</h2>
                <table class="iv-errors">
                    <thead>
                        <tr><th scope="col">Dòng</th><th scope="col">Lỗi</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in result.errors" :key="`${e.row}-${e.message}`">
                            <td>{{ e.row || '—' }}</td>
                            <td>{{ e.message }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="result.more_errors" class="iv-sub">và {{ result.more_errors }} lỗi khác. Sửa các lỗi trên rồi nhập lại để xem tiếp.</p>
            </section>
        </div>
    </AppLayout>
</template>
