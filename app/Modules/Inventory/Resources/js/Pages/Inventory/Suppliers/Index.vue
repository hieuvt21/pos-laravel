<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import Tag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import { useConfirm } from 'primevue/useconfirm';
import AppDialog from '@/Components/AppDialog.vue';
import FieldStatus from '@/Components/FieldStatus.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useUniqueCheck } from '@/composables/useUniqueCheck';
import '../inventory.css';

const props = defineProps({
    suppliers: { type: Array, default: () => [] },
});

const confirm = useConfirm();

const media = window.matchMedia('(min-width: 1024px)');
const isDesktop = ref(media.matches);
const onMedia = (e) => (isDesktop.value = e.matches);
media.addEventListener('change', onMedia);
onBeforeUnmount(() => media.removeEventListener('change', onMedia));

/* ---------- Tìm trong danh sách (danh sách nhỏ nên lọc ngay trên trình duyệt) ---------- */
const search = ref('');
const fold = (s) => (s ?? '').toString().toLowerCase().normalize('NFD').replace(/\p{M}/gu, '').replace(/đ/g, 'd');
const rows = computed(() => {
    const q = fold(search.value.trim());
    if (!q) return props.suppliers;
    return props.suppliers.filter((s) => [s.name, s.code, s.phone, s.address].some((v) => fold(v).includes(q)));
});

/* ---------- Thêm / sửa ---------- */
const dialog = ref(false);
const editing = ref(null);
const form = useForm({ name: '', phone: '', address: '', note: '', is_active: true });

// Chặn trùng tên ngay khi nhập (khi sửa: bỏ qua chính nhà cung cấp đang sửa)
const nameCheck = useUniqueCheck('name', () => form.name, {
    ignoreId: () => editing.value?.id ?? null,
    original: () => editing.value?.name ?? null,
    url: () => route('inventory.suppliers.check'),
});
const hasConflict = computed(() => ['taken', 'invalid'].includes(nameCheck.status));

function openCreate() {
    nameCheck.reset();
    editing.value = null;
    form.reset();
    form.clearErrors();
    dialog.value = true;
}

function openEdit(s) {
    nameCheck.reset();
    editing.value = s;
    form.clearErrors();
    form.name = s.name;
    form.phone = s.phone ?? '';
    form.address = s.address ?? '';
    form.note = s.note ?? '';
    form.is_active = !!s.is_active;
    dialog.value = true;
}

function submit() {
    if (hasConflict.value) return;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            dialog.value = false;
            form.reset();
        },
    };

    if (editing.value) {
        form.put(route('inventory.suppliers.update', editing.value.id), options);
    } else {
        form.post(route('inventory.suppliers.store'), options);
    }
}

function remove(s) {
    confirm.require({
        header: 'Xóa nhà cung cấp',
        message: `Xóa nhà cung cấp "${s.name}"? Thao tác này không thể hoàn tác.`,
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Hủy',
        acceptLabel: 'Xóa',
        rejectProps: { severity: 'secondary', outlined: true },
        acceptProps: { severity: 'danger' },
        accept: () => router.delete(route('inventory.suppliers.destroy', s.id), { preserveScroll: true }),
    });
}

const emptyText = computed(() => (search.value ? 'Không có nhà cung cấp nào khớp.' : 'Chưa có nhà cung cấp nào. Bấm "Thêm nhà cung cấp" để bắt đầu.'));
</script>

<template>
    <Head title="Nhà cung cấp" />

    <AppLayout title="Nhà cung cấp">
        <div class="app-panel app-fill">
            <div class="app-toolbar">
                <IconField class="iv-search">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="search" placeholder="Tìm theo tên, mã, điện thoại hoặc địa chỉ" aria-label="Tìm nhà cung cấp" fluid />
                </IconField>
                <p class="app-toolbar-note">{{ suppliers.length }} nhà cung cấp</p>
                <Button icon="pi pi-plus" label="Thêm nhà cung cấp" @click="openCreate" />
            </div>

            <DataTable v-if="isDesktop" :value="rows" dataKey="id">
                <template #empty>{{ emptyText }}</template>

                <Column field="code" header="Mã" style="width: 8rem" />
                <Column header="Tên">
                    <template #body="{ data }">
                        <strong>{{ data.name }}</strong>
                        <Tag v-if="!data.is_active" value="Ngừng sử dụng" severity="secondary" class="iv-inactive" />
                        <div v-if="data.note" class="iv-sub">{{ data.note }}</div>
                    </template>
                </Column>
                <Column header="Điện thoại" style="width: 11rem">
                    <template #body="{ data }">{{ data.phone || '—' }}</template>
                </Column>
                <Column header="Địa chỉ">
                    <template #body="{ data }">{{ data.address || '—' }}</template>
                </Column>
                <Column header="Phiếu nhập" headerClass="iv-col-right" bodyClass="iv-col-right" style="width: 8rem">
                    <template #body="{ data }">{{ data.documents_count }}</template>
                </Column>
                <Column header="Nhập gần nhất" style="width: 10rem">
                    <template #body="{ data }">{{ data.last_doc_date || '—' }}</template>
                </Column>
                <Column header="" style="width: 7rem">
                    <template #body="{ data }">
                        <div class="iv-row-actions">
                            <Button icon="pi pi-pencil" text rounded severity="secondary" :aria-label="`Sửa ${data.name}`" @click="openEdit(data)" />
                            <Button icon="pi pi-trash" text rounded severity="danger" :aria-label="`Xóa ${data.name}`" @click="remove(data)" />
                        </div>
                    </template>
                </Column>
            </DataTable>

            <template v-else>
                <div v-if="rows.length" class="iv-grid">
                    <article v-for="s in rows" :key="s.id" class="iv-card">
                        <header class="iv-card-top">
                            <span class="iv-code">{{ s.code }}</span>
                            <Tag v-if="!s.is_active" value="Ngừng sử dụng" severity="secondary" />
                        </header>
                        <h3 class="iv-name">{{ s.name }}</h3>
                        <p v-if="s.phone" class="iv-sub">{{ s.phone }}</p>
                        <p v-if="s.address" class="iv-sub">{{ s.address }}</p>
                        <div class="iv-card-qty"><span>Phiếu nhập</span><strong>{{ s.documents_count }}</strong></div>
                        <div class="iv-card-qty"><span>Nhập gần nhất</span><strong>{{ s.last_doc_date || '—' }}</strong></div>
                        <div class="iv-row-actions">
                            <Button label="Sửa" icon="pi pi-pencil" severity="secondary" outlined size="small" @click="openEdit(s)" />
                            <Button label="Xóa" icon="pi pi-trash" severity="danger" text size="small" @click="remove(s)" />
                        </div>
                    </article>
                </div>
                <p v-else class="iv-empty">{{ emptyText }}</p>
            </template>
        </div>

        <AppDialog
            v-model:visible="dialog"
            :header="editing ? 'Sửa nhà cung cấp' : 'Thêm nhà cung cấp'"
            :style="{ width: '28rem' }"
            :breakpoints="{ '640px': '94vw' }"
        >
            <form novalidate @submit.prevent="submit">
                <div class="field">
                    <label for="s-name">Tên <span class="app-req" aria-hidden="true">*</span></label>
                    <InputText
                        id="s-name"
                        v-model="form.name"
                        autocomplete="off"
                        fluid
                        :invalid="!!form.errors.name || hasConflict"
                        @blur="nameCheck.check()"
                    />
                    <small v-if="form.errors.name" class="field-error">{{ form.errors.name }}</small>
                    <FieldStatus v-else :status="nameCheck.status" :message="nameCheck.message" />
                </div>

                <div class="field">
                    <label for="s-phone">Điện thoại</label>
                    <InputText id="s-phone" v-model="form.phone" inputmode="tel" autocomplete="off" fluid :invalid="!!form.errors.phone" />
                    <small v-if="form.errors.phone" class="field-error">{{ form.errors.phone }}</small>
                </div>

                <div class="field">
                    <label for="s-address">Địa chỉ</label>
                    <InputText id="s-address" v-model="form.address" autocomplete="off" fluid :invalid="!!form.errors.address" />
                    <small v-if="form.errors.address" class="field-error">{{ form.errors.address }}</small>
                </div>

                <div class="field">
                    <label for="s-note">Ghi chú</label>
                    <Textarea id="s-note" v-model="form.note" rows="2" autoResize fluid :invalid="!!form.errors.note" />
                    <small v-if="form.errors.note" class="field-error">{{ form.errors.note }}</small>
                </div>

                <div class="field" style="flex-direction: row; align-items: center; gap: 0.5rem">
                    <Checkbox v-model="form.is_active" inputId="s-active" binary />
                    <label for="s-active">Đang sử dụng (hiện trong danh sách chọn khi nhập kho)</label>
                </div>
                <small v-if="form.errors.is_active" class="field-error">{{ form.errors.is_active }}</small>

                <div class="dialog-actions">
                    <Button type="button" label="Hủy" severity="secondary" outlined @click="dialog = false" />
                    <Button type="submit" :label="editing ? 'Lưu thay đổi' : 'Thêm nhà cung cấp'" :loading="form.processing" :disabled="hasConflict" />
                </div>
            </form>
        </AppDialog>
    </AppLayout>
</template>
