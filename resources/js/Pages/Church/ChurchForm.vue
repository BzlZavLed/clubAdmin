<template>
    <PathfinderLayout>
        <template #title>{{ tr('Crear iglesia', 'Create church') }}</template>

        <div class="mx-auto max-w-6xl space-y-4 px-3 sm:px-4 lg:px-0">
            <section class="rounded-lg border bg-white p-4 shadow-sm sm:p-6">
                <h2 class="text-lg font-semibold text-gray-900">{{ tr('Crear iglesia', 'Create church') }}</h2>

                <form @submit.prevent="submitChurch" class="mt-4 space-y-4">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ tr('Distrito', 'District') }}</label>
                            <select v-model="form.district_id" class="mt-1 w-full rounded-md border border-gray-300 p-3 text-base sm:p-2 sm:text-sm">
                                <option value="">{{ tr('Sin distrito', 'No district') }}</option>
                                <option v-for="district in districts" :key="district.id" :value="district.id">
                                    {{ districtLabel(district) }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ tr('Nombre de la iglesia *', 'Church name *') }}</label>
                            <input v-model="form.church_name" type="text" class="mt-1 w-full rounded-md border border-gray-300 p-3 text-base sm:p-2 sm:text-sm" required />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ tr('Dirección', 'Address') }}</label>
                            <input v-model="form.address" type="text" class="mt-1 w-full rounded-md border border-gray-300 p-3 text-base sm:p-2 sm:text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ tr('Etnia', 'Ethnicity') }}</label>
                            <input v-model="form.ethnicity" type="text" class="mt-1 w-full rounded-md border border-gray-300 p-3 text-base sm:p-2 sm:text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ tr('Teléfono', 'Phone') }}</label>
                            <input v-model="form.phone_number" type="text" class="mt-1 w-full rounded-md border border-gray-300 p-3 text-base sm:p-2 sm:text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ tr('Correo electrónico', 'Email') }}</label>
                            <input v-model="form.email" type="email" class="mt-1 w-full rounded-md border border-gray-300 p-3 text-base sm:p-2 sm:text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ tr('Nombre del pastor', 'Pastor name') }}</label>
                            <input v-model="form.pastor_name" type="text" class="mt-1 w-full rounded-md border border-gray-300 p-3 text-base sm:p-2 sm:text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ tr('Correo del pastor', 'Pastor email') }}</label>
                            <input v-model="form.pastor_email" type="email" class="mt-1 w-full rounded-md border border-gray-300 p-3 text-base sm:p-2 sm:text-sm" />
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <Link :href="route('register')" class="text-sm font-medium text-yellow-700 hover:underline">
                            {{ tr('Registrar personal', 'Register staff') }}
                        </Link>
                        <button type="submit" class="w-full rounded bg-blue-600 px-4 py-3 text-sm font-medium text-white hover:bg-blue-700 sm:w-auto sm:py-2">
                            {{ tr('Guardar', 'Save') }}
                        </button>
                    </div>
                </form>
            </section>

            <section class="rounded-lg border bg-white p-4 shadow-sm sm:p-6">
                <h2 class="mb-3 text-lg font-semibold text-gray-800">{{ tr('Iglesias registradas', 'Registered churches') }}</h2>

                <div v-if="loadingChurches" class="rounded border border-dashed border-gray-200 bg-gray-50 p-4 text-sm text-gray-500">
                    {{ tr('Cargando iglesias...', 'Loading churches...') }}
                </div>
                <div v-else-if="churches.length === 0" class="rounded border border-dashed border-gray-200 bg-gray-50 p-4 text-sm text-gray-500">
                    {{ tr('No se encontraron iglesias.', 'No churches found.') }}
                </div>

                <div v-else class="space-y-3 md:hidden">
                    <article v-for="church in churches" :key="`mobile-${church.id}`" class="rounded-lg border border-gray-200 p-4">
                        <div v-if="editingId === church.id" class="space-y-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-600">{{ tr('Nombre', 'Name') }}</label>
                                <input v-model="editForm.church_name" type="text" class="mt-1 w-full rounded border border-gray-300 p-3 text-base" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600">{{ tr('Distrito', 'District') }}</label>
                                <select v-model="editForm.district_id" class="mt-1 w-full rounded border border-gray-300 p-3 text-base">
                                    <option value="">{{ tr('Sin distrito', 'No district') }}</option>
                                    <option v-for="district in districts" :key="district.id" :value="district.id">
                                        {{ districtLabel(district) }}
                                    </option>
                                </select>
                            </div>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600">{{ tr('Correo', 'Email') }}</label>
                                    <input v-model="editForm.email" type="email" class="mt-1 w-full rounded border border-gray-300 p-3 text-base" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600">{{ tr('Teléfono', 'Phone') }}</label>
                                    <input v-model="editForm.phone_number" type="text" class="mt-1 w-full rounded border border-gray-300 p-3 text-base" />
                                </div>
                            </div>
                        </div>

                        <div v-else>
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="break-words font-semibold text-gray-900">{{ church.church_name }}</h3>
                                    <p class="mt-1 break-words text-sm text-gray-600">{{ church.district_name || tr('Sin distrito', 'No district') }}</p>
                                    <p class="break-words text-xs text-gray-500">
                                        {{ church.association_name || '-' }} • {{ church.union_name || '-' }}
                                    </p>
                                </div>
                                <span class="shrink-0 rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700">
                                    ID {{ church.id }}
                                </span>
                            </div>

                            <dl class="mt-3 space-y-2 text-sm">
                                <div class="rounded bg-gray-50 p-2">
                                    <dt class="text-xs text-gray-500">{{ tr('Correo', 'Email') }}</dt>
                                    <dd class="break-words text-gray-900">{{ church.email || '-' }}</dd>
                                </div>
                                <div class="rounded bg-gray-50 p-2">
                                    <dt class="text-xs text-gray-500">{{ tr('Teléfono', 'Phone') }}</dt>
                                    <dd class="break-words text-gray-900">{{ church.phone_number || '-' }}</dd>
                                </div>
                                <div class="rounded bg-gray-50 p-2">
                                    <dt class="text-xs text-gray-500">{{ tr('Código de invitación', 'Invitation code') }}</dt>
                                    <dd class="break-all font-mono text-xs text-gray-900">
                                        {{ church.invite_code || tr('Sin código', 'No code') }}
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <button type="button" class="rounded border border-indigo-200 px-3 py-2 text-sm font-medium text-indigo-700" @click="regenerateInviteCode(church.id)">
                                {{ church.invite_code ? tr('Regenerar', 'Regenerate') : tr('Generar', 'Generate') }}
                            </button>
                            <button v-if="editingId !== church.id" type="button" class="rounded border border-blue-200 px-3 py-2 text-sm font-medium text-blue-700" @click="startEdit(church)">
                                {{ tr('Editar', 'Edit') }}
                            </button>
                            <button v-if="editingId === church.id" type="button" class="rounded border border-green-200 px-3 py-2 text-sm font-medium text-green-700" @click="saveEdit(church.id)">
                                {{ tr('Guardar', 'Save') }}
                            </button>
                            <button v-if="editingId === church.id" type="button" class="rounded border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700" @click="cancelEdit">
                                {{ tr('Cancelar', 'Cancel') }}
                            </button>
                            <button type="button" class="rounded border border-red-200 px-3 py-2 text-sm font-medium text-red-700 sm:col-span-2" @click="deleteChurch(church.id)">
                                {{ tr('Eliminar', 'Delete') }}
                            </button>
                        </div>
                    </article>
                </div>

                <div v-if="!loadingChurches && churches.length" class="hidden overflow-x-auto md:block">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-700">
                            <tr>
                                <th class="px-4 py-2 text-left">{{ tr('Nombre', 'Name') }}</th>
                                <th class="px-4 py-2 text-left">{{ tr('Distrito', 'District') }}</th>
                                <th class="px-4 py-2 text-left">{{ tr('Asociación', 'Association') }}</th>
                                <th class="px-4 py-2 text-left">{{ tr('Unión', 'Union') }}</th>
                                <th class="px-4 py-2 text-left">{{ tr('Correo', 'Email') }}</th>
                                <th class="px-4 py-2 text-left">{{ tr('Teléfono', 'Phone') }}</th>
                                <th class="px-4 py-2 text-left">{{ tr('Código de invitación', 'Invitation code') }}</th>
                                <th class="px-4 py-2 text-right">{{ tr('Acciones', 'Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="church in churches" :key="church.id" class="border-t">
                                <td class="px-4 py-2">
                                    <input v-if="editingId === church.id" v-model="editForm.church_name" type="text"
                                        class="w-full rounded border border-gray-300 p-2" />
                                    <span v-else>{{ church.church_name }}</span>
                                </td>
                                <td class="px-4 py-2">
                                    <select v-if="editingId === church.id" v-model="editForm.district_id" class="w-full rounded border border-gray-300 p-2">
                                        <option value="">{{ tr('Sin distrito', 'No district') }}</option>
                                        <option v-for="district in districts" :key="district.id" :value="district.id">
                                            {{ districtLabel(district) }}
                                        </option>
                                    </select>
                                    <span v-else>{{ church.district_name || '-' }}</span>
                                </td>
                                <td class="px-4 py-2">{{ church.association_name || '-' }}</td>
                                <td class="px-4 py-2">{{ church.union_name || '-' }}</td>
                                <td class="px-4 py-2">
                                    <input v-if="editingId === church.id" v-model="editForm.email" type="email"
                                        class="w-full rounded border border-gray-300 p-2" />
                                    <span v-else>{{ church.email }}</span>
                                </td>
                                <td class="px-4 py-2">
                                    <input v-if="editingId === church.id" v-model="editForm.phone_number" type="text"
                                        class="w-full rounded border border-gray-300 p-2" />
                                    <span v-else>{{ church.phone_number || '-' }}</span>
                                </td>
                                <td class="px-4 py-2">
                                    <span v-if="church.invite_code" class="font-mono text-xs">
                                        {{ church.invite_code }}
                                    </span>
                                    <span v-else class="text-xs text-gray-400">{{ tr('Sin código', 'No code') }}</span>
                                </td>
                                <td class="space-x-2 px-4 py-2 text-right">
                                    <button type="button" class="text-indigo-600 hover:underline" @click="regenerateInviteCode(church.id)">
                                        {{ church.invite_code ? tr('Regenerar', 'Regenerate') : tr('Generar', 'Generate') }}
                                    </button>
                                    <button v-if="editingId !== church.id" type="button" class="text-blue-600 hover:underline" @click="startEdit(church)">
                                        {{ tr('Editar', 'Edit') }}
                                    </button>
                                    <button v-if="editingId === church.id" type="button" class="text-green-600 hover:underline" @click="saveEdit(church.id)">
                                        {{ tr('Guardar', 'Save') }}
                                    </button>
                                    <button v-if="editingId === church.id" type="button" class="text-gray-600 hover:underline" @click="cancelEdit">
                                        {{ tr('Cancelar', 'Cancel') }}
                                    </button>
                                    <button type="button" class="text-red-600 hover:underline" @click="deleteChurch(church.id)">
                                        {{ tr('Eliminar', 'Delete') }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </PathfinderLayout>
</template>
<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import { Link, usePage } from '@inertiajs/vue3'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useLocale } from '@/Composables/useLocale'

const page = usePage()
const { tr } = useLocale()
const currentUser = computed(() => page.props.auth?.user ?? null)
const isSuperAdmin = computed(() => currentUser.value?.profile_type === 'superadmin')
const districts = computed(() => page.props.districts ?? [])

const form = reactive({
    district_id: '',
    church_name: '',
    address: '',
    ethnicity: '',
    phone_number: '',
    email: '',
    pastor_name: '',
    pastor_email: ''
})

const churches = ref([])
const loadingChurches = ref(false)
const editingId = ref(null)
const editForm = reactive({
    district_id: '',
    church_name: '',
    email: '',
    phone_number: '',
    address: '',
    ethnicity: '',
    pastor_name: '',
    pastor_email: ''
})

const districtLabel = (district) => {
    const parts = [
        district.name,
        district.association?.name,
        district.association?.union?.name,
    ].filter(Boolean)

    return parts.join(' - ')
}

const fetchChurches = async () => {
    loadingChurches.value = true
    try {
        const endpoint = isSuperAdmin.value ? '/super-admin/churches' : '/churches'
        const response = await axios.get(endpoint)
        churches.value = response.data
    } catch (err) {
        console.error(err)
    } finally {
        loadingChurches.value = false
    }
}

const submitChurch = async () => {
    try {
        await axios.post('/churches', {
            ...form,
            district_id: form.district_id || null,
        })
        alert(tr('Iglesia creada correctamente.', 'Church created successfully.'))

        // Reset form fields
        form.district_id = ''
        form.church_name = ''
        form.address = ''
        form.ethnicity = ''
        form.phone_number = ''
        form.email = ''
        form.pastor_name = ''
        form.pastor_email = ''
        await fetchChurches()
    } catch (err) {
        alert(tr('Error al crear la iglesia. Revisa el formulario.', 'Error creating the church. Check the form.'))
        console.error(err)
    }
}

const startEdit = (church) => {
    editingId.value = church.id
    editForm.district_id = church.district_id || ''
    editForm.church_name = church.church_name || ''
    editForm.email = church.email || ''
    editForm.phone_number = church.phone_number || ''
    editForm.address = church.address || ''
    editForm.ethnicity = church.ethnicity || ''
    editForm.pastor_name = church.pastor_name || ''
    editForm.pastor_email = church.pastor_email || ''
}

const cancelEdit = () => {
    editingId.value = null
}

const saveEdit = async (churchId) => {
    try {
        await axios.put(`/churches/${churchId}`, {
            district_id: editForm.district_id || null,
            church_name: editForm.church_name,
            email: editForm.email,
            phone_number: editForm.phone_number,
            address: editForm.address,
            ethnicity: editForm.ethnicity,
            pastor_name: editForm.pastor_name,
            pastor_email: editForm.pastor_email
        })
        editingId.value = null
        await fetchChurches()
        alert(tr('Iglesia actualizada correctamente.', 'Church updated successfully.'))
    } catch (err) {
        alert(tr('Error al actualizar la iglesia. Revisa el formulario.', 'Error updating the church. Check the form.'))
        console.error(err)
    }
}

const deleteChurch = async (churchId) => {
    if (!confirm(tr('¿Seguro que deseas eliminar esta iglesia?', 'Are you sure you want to delete this church?'))) {
        return
    }
    try {
        await axios.delete(`/churches/${churchId}`)
        await fetchChurches()
        alert(tr('Iglesia eliminada correctamente.', 'Church deleted successfully.'))
    } catch (err) {
        alert(tr('Error al eliminar la iglesia.', 'Error deleting the church.'))
        console.error(err)
    }
}

const regenerateInviteCode = async (churchId) => {
    try {
        const endpoint = isSuperAdmin.value
            ? `/super-admin/churches/${churchId}/invite-code`
            : `/churches/${churchId}/invite-code`
        const response = await axios.post(endpoint)
        const updated = response.data.code
        const idx = churches.value.findIndex((church) => church.id === churchId)
        if (idx !== -1) {
            churches.value[idx].invite_code = updated
        }
        alert(tr('Código de invitación actualizado.', 'Invitation code updated.'))
    } catch (err) {
        alert(tr('Error al generar el código de invitación.', 'Error generating the invitation code.'))
        console.error(err)
    }
}

onMounted(() => {
    fetchChurches()
})
</script>
