<script setup>
import { ref, computed } from 'vue';
import { useForm } from 'vee-validate';
import DBService from '@/Service/Utils/DBService.js';

const { handleSubmit, resetForm } = useForm();

const loading           = ref(false);
const successMsg        = ref('');
const errorMsg          = ref('');
const formData          = ref({ old_password: '', new_password: '', confirm_password: '' });
const showOld           = ref(false);
const showNew           = ref(false);
const showConfirm       = ref(false);

const passwordsMatch = computed(() => {
    if (!formData.value.confirm_password) return null;
    return formData.value.new_password === formData.value.confirm_password;
});

const onSubmit = handleSubmit(() => {
    successMsg.value = '';
    errorMsg.value   = '';

    if (!passwordsMatch.value) {
        errorMsg.value = 'New password and confirm password do not match.';
        return;
    }

    loading.value = true;
    DBService.postData('/api/change-password', formData.value).then((data) => {
        loading.value = false;
        if (data.success) {
            formData.value = { old_password: '', new_password: '', confirm_password: '' };
            resetForm();
            successMsg.value = data.message || 'Password updated successfully.';
        } else {
            errorMsg.value = data.message || 'Failed to update password.';
        }
    });
});
</script>

<template>
    
    <Content>
        <div class="cp-wrap">
            <div class="cp-card">
                <!-- Icon header -->
                <div class="cp-icon-wrap">
                    <i class="bi bi-shield-lock-fill cp-icon"></i>
                </div>
                <h5 class="cp-title">Update Password</h5>
                <p class="cp-sub">Choose a strong password to keep your account secure.</p>

                <div v-if="successMsg" class="alert alert-success d-flex align-items-center gap-2 py-2 px-3 mb-4" style="font-size:.875rem">
                    <i class="bi bi-check-circle-fill flex-shrink-0"></i> {{ successMsg }}
                </div>
                <div v-if="errorMsg" class="alert alert-danger d-flex align-items-center gap-2 py-2 px-3 mb-4" style="font-size:.875rem">
                    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i> {{ errorMsg }}
                </div>

                <form @submit.prevent="onSubmit">
                    <div class="cp-fields">
                        <!-- Current password -->
                        <div class="cp-field-group">
                            <label class="cp-label">Current Password <span class="text-danger">*</span></label>
                            <div class="cp-input-wrap">
                                <FormInput
                                    :type="showOld ? 'text' : 'password'"
                                    name="old_password"
                                    :req="true"
                                    v-model="formData.old_password"
                                    placeholder="Enter current password"
                                />
                                <button type="button" class="cp-eye" @click="showOld = !showOld" :title="showOld ? 'Hide' : 'Show'">
                                    <i :class="showOld ? 'bi bi-eye-slash' : 'bi bi-eye'"></i>
                                </button>
                            </div>
                        </div>

                        <div class="cp-divider"></div>

                        <!-- New password -->
                        <div class="cp-field-group">
                            <label class="cp-label">New Password <span class="text-danger">*</span></label>
                            <div class="cp-input-wrap">
                                <FormInput
                                    :type="showNew ? 'text' : 'password'"
                                    name="new_password"
                                    :req="true"
                                    v-model="formData.new_password"
                                    placeholder="Enter new password"
                                />
                                <button type="button" class="cp-eye" @click="showNew = !showNew" :title="showNew ? 'Hide' : 'Show'">
                                    <i :class="showNew ? 'bi bi-eye-slash' : 'bi bi-eye'"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Confirm password -->
                        <div class="cp-field-group">
                            <label class="cp-label">Confirm New Password <span class="text-danger">*</span></label>
                            <div class="cp-input-wrap">
                                <FormInput
                                    :type="showConfirm ? 'text' : 'password'"
                                    name="confirm_new_password"
                                    :req="true"
                                    v-model="formData.confirm_password"
                                    placeholder="Re-enter new password"
                                />
                                <button type="button" class="cp-eye" @click="showConfirm = !showConfirm" :title="showConfirm ? 'Hide' : 'Show'">
                                    <i :class="showConfirm ? 'bi bi-eye-slash' : 'bi bi-eye'"></i>
                                </button>
                            </div>
                            <div v-if="passwordsMatch === true" class="cp-match cp-match-ok">
                                <i class="bi bi-check-circle-fill"></i> Passwords match
                            </div>
                            <div v-else-if="passwordsMatch === false" class="cp-match cp-match-fail">
                                <i class="bi bi-x-circle-fill"></i> Passwords do not match
                            </div>
                        </div>
                    </div>

                    <button
                        type="submit"
                        class="btn btn-primary cp-submit"
                        :disabled="loading || passwordsMatch === false"
                    >
                        <span v-if="loading" class="spinner-border spinner-border-sm me-2"></span>
                        <i v-else class="bi bi-shield-check me-2"></i>
                        {{ loading ? 'Updating…' : 'Update Password' }}
                    </button>
                </form>
            </div>
        </div>
    </Content>
</template>

<style scoped>
.cp-wrap {
    display: flex;
    justify-content: center;
    padding: 40px 16px 60px;
}

.cp-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 40px 40px 36px;
    width: 100%;
    max-width: 440px;
    box-shadow: 0 4px 24px rgba(0,0,0,.06);
}

.cp-icon-wrap {
    width: 56px;
    height: 56px;
    background: #eff6ff;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 18px;
}

.cp-icon {
    font-size: 1.6rem;
    color: #3b82f6;
}

.cp-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 4px;
}

.cp-sub {
    font-size: .875rem;
    color: #64748b;
    margin-bottom: 28px;
}

.cp-fields {
    display: flex;
    flex-direction: column;
    gap: 18px;
    margin-bottom: 28px;
}

.cp-divider {
    border-top: 1px dashed #e2e8f0;
    margin: 2px 0;
}

.cp-field-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.cp-label {
    font-size: .8rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 2px;
}

.cp-input-wrap {
    position: relative;
}

.cp-eye {
    position: absolute;
    top: 50%;
    right: 10px;
    transform: translateY(-50%);
    background: none;
    border: none;
    padding: 0 2px;
    color: #94a3b8;
    cursor: pointer;
    font-size: 1rem;
    line-height: 1;
    z-index: 5;
    transition: color .15s;
}
.cp-eye:hover { color: #475569; }

.cp-match {
    font-size: .78rem;
    margin-top: 4px;
    display: flex;
    align-items: center;
    gap: 5px;
}
.cp-match-ok   { color: #16a34a; }
.cp-match-fail { color: #dc2626; }

.cp-submit {
    width: 100%;
    padding: 10px;
    font-size: .9rem;
    font-weight: 600;
    border-radius: 8px;
}

@media (prefers-color-scheme: dark) {
    .cp-card {
        background: #1e293b;
        border-color: #334155;
    }
    .cp-title { color: #f1f5f9; }
    .cp-sub   { color: #94a3b8; }
    .cp-label { color: #cbd5e1; }
    .cp-icon-wrap { background: #1e3a5f; }
    .cp-divider { border-color: #334155; }
}
</style>
