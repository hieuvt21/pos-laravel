<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Password from 'primevue/password';
import BrandMark from '@/Components/BrandMark.vue';
import { useBranding } from '@/composables/useBranding';

// Trang bắt buộc đổi mật khẩu ở lần đăng nhập đầu (hoặc sau khi quản trị viên đặt lại).
const { branding } = useBranding();
const newRef = ref(null);

const form = useForm({ password: '', password_confirmation: '' });

const focusInput = (comp) => comp.value?.$el?.querySelector('input')?.focus();

const submit = () => {
    form.put(route('password.update'), {
        onError: () => {
            form.reset('password', 'password_confirmation');
            focusInput(newRef);
        },
    });
};

const logout = () => router.post(route('logout'));
</script>

<template>
    <Head title="Đổi mật khẩu" />

    <div class="app-auth">
        <div class="app-auth-card glass">
            <div class="app-auth-brand">
                <BrandMark :logo="branding.logo" :name="branding.name" large />
                <span class="app-brand-name">{{ branding.name }}</span>
            </div>

            <h1 class="app-auth-title">Đổi mật khẩu</h1>
            <p class="app-auth-sub">
                Đây là lần đăng nhập đầu tiên (hoặc mật khẩu vừa được đặt lại). Hãy đặt mật khẩu của riêng bạn để tiếp tục, không cần nhập mật khẩu cũ.
            </p>

            <form novalidate @submit.prevent="submit">
                <div class="field">
                    <label for="fc-new">Mật khẩu mới <span class="app-req" aria-hidden="true">*</span></label>
                    <Password
                        ref="newRef"
                        v-model="form.password"
                        inputId="fc-new"
                        :feedback="false"
                        toggleMask
                        autocomplete="new-password"
                        autofocus
                        fluid
                        :invalid="!!form.errors.password"
                    />
                    <small v-if="form.errors.password" class="field-error" role="alert">{{ form.errors.password }}</small>
                    <small v-else class="field-hint">Tối thiểu 6 ký tự, khác mật khẩu được cấp.</small>
                </div>

                <div class="field">
                    <label for="fc-confirm">Nhập lại mật khẩu mới <span class="app-req" aria-hidden="true">*</span></label>
                    <Password
                        v-model="form.password_confirmation"
                        inputId="fc-confirm"
                        :feedback="false"
                        toggleMask
                        autocomplete="new-password"
                        fluid
                        :invalid="!!form.errors.password_confirmation"
                    />
                    <small v-if="form.errors.password_confirmation" class="field-error" role="alert">{{ form.errors.password_confirmation }}</small>
                </div>

                <Button type="submit" label="Đổi mật khẩu" icon="pi pi-key" :loading="form.processing" fluid />
                <Button type="button" label="Đăng xuất" icon="pi pi-sign-out" severity="secondary" text fluid style="margin-top: 0.5rem" @click="logout" />
            </form>
        </div>
    </div>
</template>
