<script setup>
import { ref, watch } from 'vue'
import PasswordInput from '@/Components/PasswordInput.vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    show: Boolean,
    userId: {
        type: [Number, String],
        required: true
    },
    force: {
        type: Boolean,
        default: false
    },
    selfService: {
        type: Boolean,
        default: false,
    },
    updateUrl: {
        type: String,
        default: '',
    },
    requireConfirmation: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['close', 'updated'])

const newPassword = ref('')
const currentPassword = ref('')
const passwordConfirmation = ref('')
const currentPasswordError = ref('')
const newPasswordError = ref('')
const passwordConfirmationError = ref('')
const generalError = ref('')
const { tr } = useLocale()

const clearErrors = () => {
    currentPasswordError.value = ''
    newPasswordError.value = ''
    passwordConfirmationError.value = ''
    generalError.value = ''
}

const updatePassword = async () => {
    clearErrors()

    try {
        const requestConfig = {
            headers: { Accept: 'application/json' },
        }

        if (props.selfService) {
            await axios.put(route('password.update'), {
                current_password: currentPassword.value,
                password: newPassword.value,
                password_confirmation: passwordConfirmation.value,
            }, requestConfig)
        } else if (props.updateUrl) {
            await axios.put(props.updateUrl, {
                password: newPassword.value,
                password_confirmation: passwordConfirmation.value,
            }, requestConfig)
        } else {
            await axios.put(`/users/${props.userId}/password`, {
                password: newPassword.value
            }, requestConfig)
        }
        emit('updated')
        emit('close')
        newPassword.value = ''
        currentPassword.value = ''
        passwordConfirmation.value = ''
    } catch (err) {
        console.error('Password update error:', err)
        const errors = err?.response?.data?.errors || {}
        currentPasswordError.value = errors.current_password?.[0] || ''
        newPasswordError.value = errors.password?.[0] || ''
        passwordConfirmationError.value = errors.password_confirmation?.[0] || ''

        if (!currentPasswordError.value && !newPasswordError.value && !passwordConfirmationError.value) {
            generalError.value = err?.response?.data?.message
                || tr('No se pudo actualizar la contraseña.', 'Failed to update password.')
        }
    }
}

watch(() => props.show, (val) => {
    if (val) {
        clearErrors()
        newPassword.value = ''
        currentPassword.value = ''
        passwordConfirmation.value = ''
    }
})
</script>

<template>
    <div v-if="show" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white p-6 rounded shadow-lg w-full max-w-sm">
            <h2 class="text-lg font-semibold mb-4">{{ tr('Actualizar contraseña', 'Update password') }}</h2>
            <p v-if="force" class="mb-4 rounded bg-amber-50 px-3 py-2 text-sm text-amber-800">
                You are using a temporary password. Please create a new password to continue.
            </p>
            <form @submit.prevent="updatePassword">
                <div v-if="selfService" class="mb-4">
                    <label class="block mb-1 text-sm">{{ tr('Contraseña actual', 'Current password') }}</label>
                    <PasswordInput v-model="currentPassword" required autocomplete="current-password" />
                    <p v-if="currentPasswordError" class="mt-1 text-xs text-red-600">{{ currentPasswordError }}</p>
                </div>
                <div class="mb-4">
                    <label class="block mb-1 text-sm">{{ tr('Nueva contraseña', 'New password') }}</label>
                    <PasswordInput v-model="newPassword" required autocomplete="new-password" />
                    <p v-if="newPasswordError" class="mt-1 text-xs text-red-600">{{ newPasswordError }}</p>
                </div>
                <div v-if="selfService || requireConfirmation" class="mb-4">
                    <label class="block mb-1 text-sm">{{ tr('Confirmar nueva contraseña', 'Confirm new password') }}</label>
                    <PasswordInput v-model="passwordConfirmation" required autocomplete="new-password" />
                    <p v-if="passwordConfirmationError" class="mt-1 text-xs text-red-600">{{ passwordConfirmationError }}</p>
                </div>
                <p v-if="generalError" class="mb-4 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{{ generalError }}</p>
                <div class="flex justify-end gap-2">
                    <button v-if="!force" type="button" @click="$emit('close')"
                        class="bg-gray-300 text-gray-800 px-3 py-1 rounded hover:bg-gray-400 text-sm">
                        {{ tr('Cancelar', 'Cancel') }}
                    </button>
                    <button type="submit"
                        class="bg-blue-600 text-white px-3 py-1 rounded hover:bg-blue-700 text-sm">
                        {{ tr('Confirmar cambios', 'Confirm changes') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
