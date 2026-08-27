<script setup>
import { ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import axios from 'axios'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import PasswordInput from '@/Components/PasswordInput.vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    auth_user: { type: Object, required: true },
    account_church_name: { type: String, default: '' },
    mustVerifyEmail: Boolean,
    status: { type: String, default: '' },
    related_churches: { type: Array, default: () => [] },
})

const { tr } = useLocale()
const currentPasswordInput = ref(null)
const newPasswordInput = ref(null)
const deletionStage = ref(0)
const deletionBusy = ref(false)
const deletionError = ref('')
const deletedChildrenCount = ref(0)
const accountDeletionToken = ref('')
const familyDeletion = ref({ current_password: '', confirmation: '' })
const accountDeletion = ref({ current_password: '', confirmation: '' })

const profileForm = useForm({
    name: props.auth_user.name || '',
    email: props.auth_user.email || '',
})

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
})

const saveProfile = () => {
    profileForm.patch(route('profile.update'), {
        preserveScroll: true,
    })
}

const savePassword = () => {
    passwordForm.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
        onError: () => {
            if (passwordForm.errors.current_password) {
                passwordForm.reset('current_password')
                currentPasswordInput.value?.focus()
            } else if (passwordForm.errors.password) {
                passwordForm.reset('password', 'password_confirmation')
                newPasswordInput.value?.focus()
            }
        },
    })
}

const formatDate = (value) => {
    if (!value) return '—'
    return new Intl.DateTimeFormat(document.documentElement.lang === 'es' ? 'es-US' : 'en-US', {
        year: 'numeric', month: 'short', day: 'numeric', timeZone: 'UTC',
    }).format(new Date(`${value}T00:00:00Z`))
}

const openFamilyDeletion = () => {
    deletionError.value = ''
    deletionStage.value = 1
}

const closeDeletion = () => {
    if (deletionBusy.value) return
    deletionStage.value = 0
    deletionError.value = ''
    familyDeletion.value = { current_password: '', confirmation: '' }
    accountDeletion.value = { current_password: '', confirmation: '' }

    if (accountDeletionToken.value) window.location.reload()
}

const validationMessage = (error) => {
    const errors = error.response?.data?.errors
    return errors ? Object.values(errors).flat()[0] : (error.response?.data?.message || tr('No se pudo completar la eliminación.', 'The deletion could not be completed.'))
}

const deleteFamilyData = async () => {
    deletionBusy.value = true
    deletionError.value = ''
    try {
        const { data } = await axios.delete(route('parent.profile.family-data.destroy'), { data: familyDeletion.value })
        deletedChildrenCount.value = data.children_deleted
        accountDeletionToken.value = data.account_deletion_token
        familyDeletion.value = { current_password: '', confirmation: '' }
        deletionStage.value = 2
    } catch (error) {
        deletionError.value = validationMessage(error)
    } finally {
        deletionBusy.value = false
    }
}

const deleteParentAccount = async () => {
    deletionBusy.value = true
    deletionError.value = ''
    try {
        const { data } = await axios.delete(route('parent.profile.account.destroy'), {
            data: {
                ...accountDeletion.value,
                deletion_token: accountDeletionToken.value,
            },
        })
        window.location.assign(data.redirect_url)
    } catch (error) {
        deletionError.value = validationMessage(error)
    } finally {
        deletionBusy.value = false
    }
}
</script>

<template>
    <Head :title="tr('Mi perfil', 'My profile')" />

    <PathfinderLayout>
        <template #title>{{ tr('Mi perfil', 'My profile') }}</template>

        <div class="space-y-6">
            <section class="rounded-xl border border-blue-200 bg-blue-50 p-5 text-sm text-blue-950 shadow-sm">
                <h2 class="font-semibold">{{ tr('Mantén tus datos familiares consistentes', 'Keep your family information consistent') }}</h2>
                <p class="mt-1">
                    {{ tr(
                        'Tu nombre y correo se usan para encontrar y vincular a tus hijos. Si cambias el correo, deberás verificar la nueva dirección antes de usar nuevamente las funciones de contraseña y vínculos automáticos.',
                        'Your name and email are used to find and link your children. If you change the email, you must verify the new address before using password self-service and automatic linking again.'
                    ) }}
                </p>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="border-b border-gray-200 pb-4">
                    <h2 class="text-lg font-semibold text-gray-950">{{ tr('Relaciones con otras iglesias', 'Relationships with other churches') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">{{ tr('Iglesias y clubes fuera de la iglesia de tu cuenta donde tienes hijos vinculados.', 'Churches and clubs outside your account church where you have linked children.') }}</p>
                </div>

                <div v-if="related_churches.length" class="mt-4 overflow-hidden rounded-lg border border-gray-200">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                                <tr>
                                    <th class="px-4 py-3">{{ tr('Iglesia relacionada', 'Related church') }}</th>
                                    <th class="px-4 py-3">{{ tr('Club', 'Club') }}</th>
                                    <th class="px-4 py-3">{{ tr('Tipo', 'Type') }}</th>
                                    <th class="px-4 py-3 text-center">{{ tr('Hijos', 'Children') }}</th>
                                    <th class="px-4 py-3">{{ tr('Desde', 'Since') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white text-gray-800">
                                <tr v-for="relationship in related_churches" :key="`${relationship.church_name}-${relationship.club_name}`">
                                    <td class="px-4 py-3 font-medium">{{ relationship.church_name || '—' }}</td>
                                    <td class="px-4 py-3">{{ relationship.club_name || '—' }}</td>
                                    <td class="px-4 py-3 capitalize">{{ relationship.club_type || '—' }}</td>
                                    <td class="px-4 py-3 text-center">{{ relationship.children_count }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ formatDate(relationship.related_since) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <p v-else class="mt-4 rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-600">
                    {{ tr('No tienes hijos vinculados a clubes de otras iglesias.', 'You do not have children linked to clubs in other churches.') }}
                </p>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="border-b border-gray-200 pb-4">
                    <h2 class="text-lg font-semibold text-gray-950">{{ tr('Información de la cuenta', 'Account information') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">{{ tr('Actualiza el nombre y correo asociados con tu cuenta de padre.', 'Update the name and email associated with your parent account.') }}</p>
                </div>

                <form class="mt-5 max-w-2xl space-y-5" @submit.prevent="saveProfile">
                    <div>
                        <label for="parent-profile-name" class="block text-sm font-medium text-gray-800">{{ tr('Nombre completo', 'Full name') }}</label>
                        <input id="parent-profile-name" v-model="profileForm.name" type="text" autocomplete="name" required class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                        <p v-if="profileForm.errors.name" class="mt-1 text-sm text-red-600">{{ profileForm.errors.name }}</p>
                    </div>

                    <div>
                        <label for="parent-profile-email" class="block text-sm font-medium text-gray-800">{{ tr('Correo electrónico', 'Email address') }}</label>
                        <input id="parent-profile-email" v-model="profileForm.email" type="email" autocomplete="email" required class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                        <p v-if="profileForm.errors.email" class="mt-1 text-sm text-red-600">{{ profileForm.errors.email }}</p>
                        <p v-if="mustVerifyEmail && !auth_user.email_verified_at" class="mt-2 text-xs font-medium text-amber-700">{{ tr('Este correo todavía no está verificado.', 'This email is not verified yet.') }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-800">{{ tr('Iglesia de la cuenta', 'Account church') }}</label>
                        <div class="mt-1 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-gray-800">{{ account_church_name || '—' }}</div>
                        <p class="mt-1 text-xs text-gray-500">{{ tr('La iglesia de origen no se cambia desde el perfil.', 'The originating church cannot be changed from the profile.') }}</p>
                    </div>

                    <div class="flex items-center gap-3">
                        <button type="submit" :disabled="profileForm.processing" class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-60">
                            {{ profileForm.processing ? tr('Guardando...', 'Saving...') : tr('Guardar perfil', 'Save profile') }}
                        </button>
                        <span v-if="profileForm.recentlySuccessful" class="text-sm font-medium text-emerald-700">{{ tr('Perfil actualizado.', 'Profile updated.') }}</span>
                    </div>
                </form>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="border-b border-gray-200 pb-4">
                    <h2 class="text-lg font-semibold text-gray-950">{{ tr('Cambiar contraseña', 'Change password') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">{{ tr('Usa tu contraseña actual para confirmar el cambio.', 'Use your current password to confirm the change.') }}</p>
                </div>

                <form class="mt-5 max-w-2xl space-y-5" @submit.prevent="savePassword">
                    <div>
                        <label for="parent-current-password" class="block text-sm font-medium text-gray-800">{{ tr('Contraseña actual', 'Current password') }}</label>
                        <PasswordInput id="parent-current-password" ref="currentPasswordInput" v-model="passwordForm.current_password" autocomplete="current-password" required input-class="rounded-lg" />
                        <p v-if="passwordForm.errors.current_password" class="mt-1 text-sm text-red-600">{{ passwordForm.errors.current_password }}</p>
                    </div>
                    <div>
                        <label for="parent-new-password" class="block text-sm font-medium text-gray-800">{{ tr('Nueva contraseña', 'New password') }}</label>
                        <PasswordInput id="parent-new-password" ref="newPasswordInput" v-model="passwordForm.password" autocomplete="new-password" required input-class="rounded-lg" />
                        <p v-if="passwordForm.errors.password" class="mt-1 text-sm text-red-600">{{ passwordForm.errors.password }}</p>
                    </div>
                    <div>
                        <label for="parent-confirm-password" class="block text-sm font-medium text-gray-800">{{ tr('Confirmar nueva contraseña', 'Confirm new password') }}</label>
                        <PasswordInput id="parent-confirm-password" v-model="passwordForm.password_confirmation" autocomplete="new-password" required input-class="rounded-lg" />
                        <p v-if="passwordForm.errors.password_confirmation" class="mt-1 text-sm text-red-600">{{ passwordForm.errors.password_confirmation }}</p>
                    </div>

                    <div class="flex items-center gap-3">
                        <button type="submit" :disabled="passwordForm.processing" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-60">
                            {{ passwordForm.processing ? tr('Actualizando...', 'Updating...') : tr('Actualizar contraseña', 'Update password') }}
                        </button>
                        <span v-if="passwordForm.recentlySuccessful" class="text-sm font-medium text-emerald-700">{{ tr('Contraseña actualizada.', 'Password updated.') }}</span>
                    </div>
                </form>
            </section>

            <section class="rounded-xl border-2 border-red-300 bg-red-50 p-5 shadow-sm sm:p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="max-w-3xl">
                        <h2 class="text-lg font-bold text-red-900">{{ tr('Zona de eliminación permanente', 'Permanent deletion zone') }}</h2>
                        <p class="mt-2 text-sm text-red-800">
                            {{ tr(
                                'Puedes eliminar permanentemente todos los datos de tus hijos. Después podrás elegir si también eliminas tu cuenta. Esta acción no se puede deshacer.',
                                'You can permanently delete all of your children’s data. Afterward, you can choose whether to delete your account too. This action cannot be undone.'
                            ) }}
                        </p>
                        <p class="mt-2 text-xs text-red-700">
                            {{ tr(
                                'Los movimientos financieros del club se conservarán por integridad contable, pero quedarán sin el nombre, correo, notas ni vínculo de tu familia.',
                                'Club financial transactions are retained for accounting integrity, but your family name, email, notes, and links are removed from them.'
                            ) }}
                        </p>
                    </div>
                    <button type="button" class="shrink-0 rounded-lg bg-red-700 px-4 py-2 text-sm font-bold text-white hover:bg-red-800" @click="openFamilyDeletion">
                        {{ tr('Eliminar datos familiares', 'Delete family data') }}
                    </button>
                </div>
            </section>
        </div>

        <div v-if="deletionStage" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" role="dialog" aria-modal="true">
            <div class="w-full max-w-xl rounded-xl bg-white shadow-2xl">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h2 class="text-xl font-bold text-red-800">
                        {{ deletionStage === 1 ? tr('Eliminar todos los datos de tus hijos', 'Delete all children’s data') : tr('Datos de hijos eliminados', 'Children’s data deleted') }}
                    </h2>
                </div>

                <form v-if="deletionStage === 1" class="space-y-4 p-6" @submit.prevent="deleteFamilyData">
                    <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                        <strong>{{ tr('Irrecuperable:', 'Unrecoverable:') }}</strong>
                        {{ tr('se eliminarán registros, solicitudes, firmas, evidencias, archivos, ubicaciones y vínculos de todos tus hijos.', 'records, applications, signatures, evidence, files, locations, and links for all your children will be deleted.') }}
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-800">{{ tr('Contraseña actual', 'Current password') }}</label>
                        <PasswordInput v-model="familyDeletion.current_password" autocomplete="current-password" required input-class="rounded-lg" />
                    </div>
                    <div>
                        <label for="delete-family-confirmation" class="block text-sm font-medium text-gray-800">
                            {{ tr('Escribe DELETE MY CHILDREN para confirmar', 'Type DELETE MY CHILDREN to confirm') }}
                        </label>
                        <input id="delete-family-confirmation" v-model="familyDeletion.confirmation" type="text" autocomplete="off" required class="mt-1 block w-full rounded-lg border-gray-300 font-mono shadow-sm focus:border-red-500 focus:ring-red-500" />
                    </div>
                    <p v-if="deletionError" class="rounded-lg bg-red-100 px-3 py-2 text-sm font-medium text-red-800">{{ deletionError }}</p>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700" @click="closeDeletion">{{ tr('Cancelar', 'Cancel') }}</button>
                        <button type="submit" :disabled="deletionBusy || familyDeletion.confirmation !== 'DELETE MY CHILDREN'" class="rounded-lg bg-red-700 px-4 py-2 text-sm font-bold text-white hover:bg-red-800 disabled:cursor-not-allowed disabled:opacity-40">
                            {{ deletionBusy ? tr('Eliminando...', 'Deleting...') : tr('Eliminar para siempre', 'Delete forever') }}
                        </button>
                    </div>
                </form>

                <form v-else class="space-y-4 p-6" @submit.prevent="deleteParentAccount">
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                        {{ tr(`Se eliminaron permanentemente ${deletedChildrenCount} registro(s) de hijos. Puedes conservar tu cuenta vacía y cerrar esta ventana.`, `${deletedChildrenCount} child record(s) were permanently deleted. You may keep your empty account and close this window.`) }}
                    </div>
                    <div class="border-t border-gray-200 pt-4">
                        <h3 class="font-bold text-red-900">{{ tr('¿Eliminar también tu cuenta?', 'Delete your account too?') }}</h3>
                        <p class="mt-1 text-sm text-gray-700">{{ tr('Esto cerrará tu sesión y eliminará permanentemente la cuenta. No se puede recuperar.', 'This signs you out and permanently deletes the account. It cannot be recovered.') }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-800">{{ tr('Contraseña actual', 'Current password') }}</label>
                        <PasswordInput v-model="accountDeletion.current_password" autocomplete="current-password" required input-class="rounded-lg" />
                    </div>
                    <div>
                        <label for="delete-account-confirmation" class="block text-sm font-medium text-gray-800">{{ tr('Escribe DELETE MY ACCOUNT para confirmar', 'Type DELETE MY ACCOUNT to confirm') }}</label>
                        <input id="delete-account-confirmation" v-model="accountDeletion.confirmation" type="text" autocomplete="off" required class="mt-1 block w-full rounded-lg border-gray-300 font-mono shadow-sm focus:border-red-500 focus:ring-red-500" />
                    </div>
                    <p v-if="deletionError" class="rounded-lg bg-red-100 px-3 py-2 text-sm font-medium text-red-800">{{ deletionError }}</p>
                    <div class="flex flex-col-reverse justify-end gap-3 pt-2 sm:flex-row">
                        <button type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700" @click="closeDeletion">{{ tr('Conservar mi cuenta', 'Keep my account') }}</button>
                        <button type="submit" :disabled="deletionBusy || accountDeletion.confirmation !== 'DELETE MY ACCOUNT'" class="rounded-lg bg-red-900 px-4 py-2 text-sm font-bold text-white hover:bg-black disabled:cursor-not-allowed disabled:opacity-40">
                            {{ deletionBusy ? tr('Eliminando cuenta...', 'Deleting account...') : tr('Eliminar también mi cuenta', 'Delete my account too') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </PathfinderLayout>
</template>
