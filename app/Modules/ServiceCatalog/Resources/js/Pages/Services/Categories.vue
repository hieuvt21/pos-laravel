<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import InputText from 'primevue/inputtext';
import Tag from 'primevue/tag';
import { useConfirm } from 'primevue/useconfirm';
import AppDialog from '@/Components/AppDialog.vue';
import FieldStatus from '@/Components/FieldStatus.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useUniqueCheck } from '@/composables/useUniqueCheck';
import './services.css';

defineProps({
    categories: { type: Array, default: () => [] }, // [{ id, name, description, is_active, services_count }]
});

const confirm = useConfirm();

const media = window.matchMedia('(min-width: 1024px)');
const isDesktop = ref(media.matches);
const onMedia = (e) => (isDesktop.value = e.matches);
media.addEventListener('change', onMedia);
onBeforeUnmount(() => media.removeEventListener('change', onMedia));

const dialog = ref(false);
const editing = ref(null);
const form = useForm({ name: '', description: '', is_active: true });

const nameCheck = useUniqueCheck('name', () => form.name, {
    ignoreId: () => editing.value?.id ?? null,
    original: () => editing.value?.name ?? null,
    url: () => route('service_categories.check'),
});
const hasConflict = computed(() => ['taken', 'invalid'].includes(nameCheck.status));

function openCreate() {
    nameCheck.reset();
    editing.value = null;
    form.reset();
    form.clearErrors();
    dialog.value = true;
}

function openEdit(c) {
    nameCheck.reset();
    editing.value = c;
    form.clearErrors();
    form.name = c.name;
    form.description = c.description ?? '';
    form.is_active = !!c.is_active;
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
        form.put(route('service_categories.update', editing.value.id), options);
    } else {
        form.post(route('service_categories.store'), options);
    }
}

function remove(c) {
    confirm.require({
        header: 'Xóa danh mục dịch vụ',
        message: `Xóa danh mục "${c.name}"? Thao tác này không thể hoàn tác.`,
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Hủy',
        acceptLabel: 'Xóa',
        rejectProps: { severity: 'secondary', outlined: true },
        acceptProps: { severity: 'danger' },
        accept: () => router.delete(route('service_categories.destroy', c.id), { preserveScroll: true }),
    });
}
</script>

<template>
    <Head title="Danh mục dịch vụ" />

    <AppLayout title="Danh mục dịch vụ">
        <div class="app-panel app-fill">
            <div class="app-toolbar">
                <p class="app-toolbar-note">{{ categories.length }} danh mục</p>
                <Button icon="pi pi-plus" label="Thêm danh mục" @click="openCreate" />
            </div>

            <DataTable v-if="isDesktop" :value="categories" dataKey="id">
                <template #empty>Chưa có danh mục dịch vụ nào. Bấm "Thêm danh mục" để bắt đầu.</template>

                <Column header="Tên danh mục">
                    <template #body="{ data }">
                        <strong>{{ data.name }}</strong>
                        <Tag v-if="!data.is_active" value="Ngừng sử dụng" severity="secondary" class="sv-tag" />
                    </template>
                </Column>
                <Column header="Mô tả">
                    <template #body="{ data }">{{ data.description || '—' }}</template>
                </Column>
                <Column header="Số dịch vụ" headerClass="sv-col-right" bodyClass="sv-col-right" style="width: 9rem">
                    <template #body="{ data }">{{ data.services_count }}</template>
                </Column>
                <Column header="" style="width: 7rem">
                    <template #body="{ data }">
                        <div class="sv-row-actions">
                            <Button icon="pi pi-pencil" text rounded severity="secondary" :aria-label="`Sửa ${data.name}`" @click="openEdit(data)" />
                            <Button icon="pi pi-trash" text rounded severity="danger" :aria-label="`Xóa ${data.name}`" @click="remove(data)" />
                        </div>
                    </template>
                </Column>
            </DataTable>

            <template v-else>
                <div v-if="categories.length" class="sv-grid">
                    <article v-for="c in categories" :key="c.id" class="sv-card">
                        <header class="sv-card-top">
                            <h3 class="sv-name">{{ c.name }}</h3>
                            <Tag v-if="!c.is_active" value="Ngừng sử dụng" severity="secondary" />
                        </header>
                        <p v-if="c.description" class="sv-sub">{{ c.description }}</p>
                        <div class="sv-card-row"><span>Số dịch vụ</span><strong>{{ c.services_count }}</strong></div>
                        <div class="sv-row-actions">
                            <Button label="Sửa" icon="pi pi-pencil" severity="secondary" outlined size="small" @click="openEdit(c)" />
                            <Button label="Xóa" icon="pi pi-trash" severity="danger" text size="small" @click="remove(c)" />
                        </div>
                    </article>
                </div>
                <p v-else class="sv-empty">Chưa có danh mục dịch vụ nào. Bấm "Thêm danh mục" để bắt đầu.</p>
            </template>
        </div>

        <AppDialog v-model:visible="dialog" :header="editing ? 'Sửa danh mục dịch vụ' : 'Thêm danh mục dịch vụ'" :style="{ width: '26rem' }" :breakpoints="{ '640px': '94vw' }">
            <form novalidate @submit.prevent="submit">
                <div class="field">
                    <label for="c-name">Tên danh mục <span class="app-req" aria-hidden="true">*</span></label>
                    <InputText id="c-name" v-model="form.name" autocomplete="off" fluid :invalid="!!form.errors.name || hasConflict" @blur="nameCheck.check()" />
                    <small v-if="form.errors.name" class="field-error">{{ form.errors.name }}</small>
                    <FieldStatus v-else :status="nameCheck.status" :message="nameCheck.message" />
                </div>

                <div class="field">
                    <label for="c-desc">Mô tả</label>
                    <InputText id="c-desc" v-model="form.description" autocomplete="off" fluid :invalid="!!form.errors.description" />
                    <small v-if="form.errors.description" class="field-error">{{ form.errors.description }}</small>
                </div>

                <div class="field" style="flex-direction: row; align-items: center; gap: 0.5rem">
                    <Checkbox v-model="form.is_active" inputId="c-active" binary />
                    <label for="c-active">Đang sử dụng (hiện trong danh sách chọn khi thêm dịch vụ)</label>
                </div>
                <small v-if="form.errors.is_active" class="field-error">{{ form.errors.is_active }}</small>

                <div class="dialog-actions">
                    <Button type="button" label="Hủy" severity="secondary" outlined @click="dialog = false" />
                    <Button type="submit" :label="editing ? 'Lưu thay đổi' : 'Thêm danh mục'" :loading="form.processing" :disabled="hasConflict" />
                </div>
            </form>
        </AppDialog>
    </AppLayout>
</template>
