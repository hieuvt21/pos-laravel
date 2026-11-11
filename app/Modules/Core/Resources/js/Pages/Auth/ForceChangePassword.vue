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
const currentRef = ref(null);

const form = useForm({ current_password: '', password: '', password_confirmation: '' });

const focusInput = (comp) => comp.value?.$el?.querySelector('input')?.focus();

const submit = () => {
    form.put(route('password.update'), {
        onError: () => {
            if (form.errors.current_password) {
                form.reset('current_password');
                focusInput(currentRef);
            } else {
                form.reset('password', 'password_confirmation');
                focusInput(newRef);
            }
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
                Đây là lần đăng nhập đầu tiên (hoặc mật khẩu vừa được đặt lại). Hãy đặt mật khẩu của riêng bạn để tiếp tục.
            </p>

            <form novalidate @submit.prevent="submit">
                <div class="field">
                    <label for="fc-current">Mật khẩu hiện tại</label>
                    <Password
                        ref="currentRef"
                        v-model="form.current_password"
                        inputId="fc-current"
                        :feedback="false"
                        toggleMask
                        autocomplete="current-password"
                        autofocus
                        fluid
                        :invalid="!!form.errors.current_password"
                    />
                    <small v-if="form.errors.current_password" class="field-error" role="alert">{{ form.errors.current_password }}</small>
                </div>

                <div class="field">
                    <label for="fc-new">Mật khẩu mới</label>
                    <Password
                        ref="newRef"
                        v-model="form.password"
                        inputId="fc-new"
                        :feedback="false"
                        toggleMask
                        autocomplete="new-password"
                        fluid
                        :invalid="!!form.errors.password"
                    />
                    <small v-if="form.errors.password" class="field-error" role="alert">{{ form.errors.password }}</small>
                    <small v-else class="field-hint">Tối thiểu 8 ký tự, khác mật khẩu hiện tại.</small>
                </div>

                <div class="field">
                    <label for="fc-confirm">Nhập lại mật khẩu mới</label>
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
