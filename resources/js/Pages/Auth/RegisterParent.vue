<script setup>
import axios from 'axios'
import LocaleSwitcher from '@/Components/LocaleSwitcher.vue'
import { useLocale } from '@/Composables/useLocale'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useToast } from 'vue-toastification'

const toast = useToast()
const clubs = ref([])
const resolvedChurch = ref(null)
const resolvingInvite = ref(false)
const inviteResolved = ref(false)
const { tr } = useLocale()

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    invite_code: '',
    church_name: '',
    church_id: '',
    club_id: '',
})

const resolveInvite = async () => {
    form.clearErrors('invite_code')
    inviteResolved.value = false
    resolvedChurch.value = null
    clubs.value = []
    form.church_id = ''
    form.church_name = ''
    form.club_id = ''

    if (!form.invite_code) {
        form.setError('invite_code', tr('Ingresa el código de invitación de tu iglesia.', 'Enter your church invitation code.'))
        return
    }

    resolvingInvite.value = true
    try {
        const { data } = await axios.post(route('parent.register.resolve-invite'), {
            invite_code: form.invite_code,
        })

        resolvedChurch.value = data.church
        clubs.value = data.clubs || []
        form.church_id = data.church?.id || ''
        form.church_name = data.church?.church_name || ''
        inviteResolved.value = true

        if (!clubs.value.length) {
            toast.error(tr('La iglesia fue encontrada, pero no tiene clubes activos disponibles.', 'The church was found, but it has no active clubs available.'))
        }
    } catch (error) {
        const message = error.response?.data?.message || tr('Código inválido o expirado.', 'Invalid or expired code.')
        form.setError('invite_code', message)
        toast.error(message)
    } finally {
        resolvingInvite.value = false
    }
}

const submit = () => {
    form.post('/register-parent', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <Head :title="tr('Registro de padres', 'Parent registration')" />

    <div class="min-h-screen flex items-center justify-center bg-gray-100 px-4">
        <div class="max-w-md w-full bg-white p-6 rounded shadow">
            <h1 class="text-2xl font-bold mb-4 text-center">{{ tr('Registro de padres', 'Parent registration') }}</h1>
            <div class="mb-4">
                <LocaleSwitcher :compact="true" />
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <div class="rounded border border-amber-200 bg-amber-50 p-3">
                    <label for="invite_code" class="block text-sm font-medium text-gray-700">{{ tr('Código de invitación de la iglesia', 'Church invitation code') }}</label>
                    <div class="mt-1 flex gap-2">
                        <input
                            v-model="form.invite_code"
                            type="text"
                            id="invite_code"
                            required
                            class="w-full p-2 border rounded uppercase"
                            :placeholder="tr('Ej. ABC123', 'Ex. ABC123')"
                        />
                        <button
                            type="button"
                            class="px-3 py-2 text-sm bg-gray-800 text-white rounded hover:bg-gray-900 disabled:opacity-60"
                            :disabled="resolvingInvite"
                            @click="resolveInvite"
                        >
                            {{ resolvingInvite ? tr('Validando...', 'Validating...') : tr('Validar', 'Validate') }}
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-gray-600">{{ tr('Este código vincula tu cuenta con la iglesia correcta y limita la lista de clubes disponibles.', 'This code links your account to the correct church and limits the available club list.') }}</p>
                    <p v-if="form.errors.invite_code" class="text-sm text-red-600 mt-1">{{ form.errors.invite_code }}</p>
                </div>

                <div v-if="resolvedChurch" class="rounded border border-emerald-200 bg-emerald-50 p-3 text-sm">
                    <div class="font-semibold text-emerald-900">{{ resolvedChurch.church_name }}</div>
                    <div class="text-emerald-800">
                        {{ resolvedChurch.district_name || tr('Distrito no definido', 'District not defined') }}
                        <span v-if="resolvedChurch.association_name"> • {{ resolvedChurch.association_name }}</span>
                        <span v-if="resolvedChurch.union_name"> • {{ resolvedChurch.union_name }}</span>
                    </div>
                    <div class="text-xs text-emerald-700">
                        {{ tr('Sistema', 'System') }}: {{ resolvedChurch.evaluation_system === 'carpetas' ? tr('Carpetas', 'Folders') : tr('Honores', 'Honors') }}
                    </div>
                </div>

                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700">{{ tr('Nombre completo', 'Full name') }}</label>
                    <input v-model="form.name" type="text" id="name" required class="w-full mt-1 p-2 border rounded" />
                    <p v-if="form.errors.name" class="text-sm text-red-600 mt-1">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700">{{ tr('Correo electrónico', 'Email') }}</label>
                    <input v-model="form.email" type="email" id="email" required class="w-full mt-1 p-2 border rounded" />
                    <p v-if="form.errors.email" class="text-sm text-red-600 mt-1">{{ form.errors.email }}</p>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">{{ tr('Contraseña', 'Password') }}</label>
                    <input v-model="form.password" type="password" id="password" required class="w-full mt-1 p-2 border rounded" />
                    <p v-if="form.errors.password" class="text-sm text-red-600 mt-1">{{ form.errors.password }}</p>
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-700">{{ tr('Confirmar contraseña', 'Confirm password') }}</label>
                    <input v-model="form.password_confirmation" type="password" id="password_confirmation" required class="w-full mt-1 p-2 border rounded" />
                </div>

                <div class="mb-4">
                    <label for="club_id" class="block text-sm font-medium text-gray-700">{{ tr('Seleccionar club', 'Select club') }}</label>
                    <select
                        v-model="form.club_id"
                        id="club_id"
                        class="w-full border rounded p-2"
                        required
                        :disabled="!inviteResolved || !clubs.length"
                    >
                        <option disabled value="">-- {{ tr('Selecciona un club', 'Select a club') }} --</option>
                        <option v-for="club in clubs" :key="club.id" :value="club.id">
                            {{ club.club_name }} ({{ club.club_type }}) - {{ club.evaluation_system === 'carpetas' ? tr('Carpetas', 'Folders') : tr('Honores', 'Honors') }}
                        </option>
                    </select>
                    <span v-if="form.errors.club_id" class="text-red-500 text-sm">{{ form.errors.club_id }}</span>
                </div>

                <button
                    type="submit"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded disabled:opacity-60"
                    :disabled="form.processing || !inviteResolved || !form.club_id"
                >
                    {{ tr('Registrarse', 'Register') }}
                </button>
            </form>

            <p class="mt-4 text-center text-sm text-gray-600">
                {{ tr('¿Ya tienes una cuenta?', 'Already have an account?') }}
                <Link href="/login" class="text-blue-600 hover:underline">{{ tr('Iniciar sesión', 'Log in') }}</Link>
            </p>
        </div>
    </div>
</template>
