<template>
    <PathfinderLayout>
        <template #title>Crear iglesia</template>

        <div class="p-4 max-w-lg mx-auto">
            <form @submit.prevent="submitChurch">
                <div class="mb-4">
                    <label class="block mb-1">Nombre de la iglesia *</label>
                    <input v-model="form.church_name" type="text" class="border p-2 w-full" required />
                </div>
                <div class="mb-4">
                    <label class="block mb-1">Dirección</label>
                    <input v-model="form.address" type="text" class="border p-2 w-full" />
                </div>
                <div class="mb-4">
                    <label class="block mb-1">Etnia</label>
                    <input v-model="form.ethnicity" type="text" class="border p-2 w-full" />
                </div>
                <div class="mb-4">
                    <label class="block mb-1">Teléfono</label>
                    <input v-model="form.phone_number" type="text" class="border p-2 w-full" />
                </div>
                <div class="mb-4">
                    <label class="block mb-1">Correo electrónico</label>
                    <input v-model="form.email" type="email" class="border p-2 w-full" />
                </div>
                <div class="mb-4">
                    <label class="block mb-1">Nombre del pastor</label>
                    <input v-model="form.pastor_name" type="text" class="border p-2 w-full" />
                </div>
                <div class="mb-4">
                    <label class="block mb-1">Correo del pastor</label>
                    <input v-model="form.pastor_email" type="email" class="border p-2 w-full" />
                </div>
                <div class="mb-4">
                    <label class="block mb-1">Conferencia</label>
                    <input v-model="form.conference" type="text" class="border p-2 w-full" />
                </div>
                <Link :href="route('register')" class="text-sm text-yellow-600 hover:underline">
                Registrar personal
                </Link><br>

                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Guardar</button>

            </form>
        </div>

        <div class="p-4 max-w-5xl mx-auto">
            <h2 class="text-lg font-semibold text-gray-800 mb-3">Iglesias registradas</h2>
            <div class="overflow-x-auto bg-white border rounded-lg shadow-sm">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-700">
                        <tr>
                            <th class="text-left px-4 py-2">Nombre</th>
                            <th class="text-left px-4 py-2">Correo</th>
                            <th class="text-left px-4 py-2">Teléfono</th>
                            <th class="text-left px-4 py-2">Conferencia</th>
                            <th class="text-left px-4 py-2">Código de invitación</th>
                            <th class="text-right px-4 py-2">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loadingChurches">
                            <td colspan="6" class="px-4 py-3 text-gray-500">Cargando iglesias...</td>
                        </tr>
                        <tr v-else-if="churches.length === 0">
                            <td colspan="6" class="px-4 py-3 text-gray-500">No se encontraron iglesias.</td>
                        </tr>
                        <tr v-for="church in churches" :key="church.id" class="border-t">
                            <td class="px-4 py-2">
                                <input v-if="editingId === church.id" v-model="editForm.church_name" type="text"
                                    class="border p-1 w-full" />
                                <span v-else>{{ church.church_name }}</span>
                            </td>
                            <td class="px-4 py-2">
                                <input v-if="editingId === church.id" v-model="editForm.email" type="email"
                                    class="border p-1 w-full" />
                                <span v-else>{{ church.email }}</span>
                            </td>
                            <td class="px-4 py-2">
                                <input v-if="editingId === church.id" v-model="editForm.phone_number" type="text"
                                    class="border p-1 w-full" />
                                <span v-else>{{ church.phone_number || '-' }}</span>
                            </td>
                            <td class="px-4 py-2">
                                <input v-if="editingId === church.id" v-model="editForm.conference" type="text"
                                    class="border p-1 w-full" />
                                <span v-else>{{ church.conference || '-' }}</span>
                            </td>
                            <td class="px-4 py-2">
                                <span v-if="church.invite_code" class="font-mono text-xs">
                                    {{ church.invite_code }}
                                </span>
                                <span v-else class="text-gray-400 text-xs">Sin código</span>
                            </td>
                            <td class="px-4 py-2 text-right space-x-2">
                                <button type="button" class="text-indigo-600 hover:underline"
                                    @click="regenerateInviteCode(church.id)">
                                    {{ church.invite_code ? 'Regenerar' : 'Generar' }}
                                </button>
                                <button v-if="editingId !== church.id" type="button"
                                    class="text-blue-600 hover:underline" @click="startEdit(church)">
                                    Editar
                                </button>
                                <button v-if="editingId === church.id" type="button"
                                    class="text-green-600 hover:underline" @click="saveEdit(church.id)">
                                    Guardar
                                </button>
                                <button v-if="editingId === church.id" type="button"
                                    class="text-gray-600 hover:underline" @click="cancelEdit">
                                    Cancelar
                                </button>
                                <button type="button" class="text-red-600 hover:underline"
                                    @click="deleteChurch(church.id)">
                                    Eliminar
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </PathfinderLayout>
</template>
<script setup>
import { onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import { Link } from '@inertiajs/vue3'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'

const form = reactive({
    church_name: '',
    address: '',
    ethnicity: '',
    phone_number: '',
    email: '',
    pastor_name: '',
    pastor_email: '',
    conference: ''
})

const churches = ref([])
const loadingChurches = ref(false)
const editingId = ref(null)
const editForm = reactive({
    church_name: '',
    email: '',
    phone_number: '',
    conference: '',
    address: '',
    ethnicity: '',
    pastor_name: '',
    pastor_email: ''
})

const fetchChurches = async () => {
    loadingChurches.value = true
    try {
        const response = await axios.get('/super-admin/churches')
        churches.value = response.data
    } catch (err) {
        console.error(err)
    } finally {
        loadingChurches.value = false
    }
}

const submitChurch = async () => {
    try {
        await axios.post('/churches', form)
        alert('Iglesia creada correctamente.')

        // Reset form fields
        form.church_name = ''
        form.address = ''
        form.ethnicity = ''
        form.phone_number = ''
        form.email = ''
        form.pastor_name = ''
        form.pastor_email = ''
        form.conference = ''
        await fetchChurches()
    } catch (err) {
        alert('Error al crear la iglesia. Revisa el formulario.')
        console.error(err)
    }
}

const startEdit = (church) => {
    editingId.value = church.id
    editForm.church_name = church.church_name || ''
    editForm.email = church.email || ''
    editForm.phone_number = church.phone_number || ''
    editForm.conference = church.conference || ''
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
            church_name: editForm.church_name,
            email: editForm.email,
            phone_number: editForm.phone_number,
            conference: editForm.conference,
            address: editForm.address,
            ethnicity: editForm.ethnicity,
            pastor_name: editForm.pastor_name,
            pastor_email: editForm.pastor_email
        })
        editingId.value = null
        await fetchChurches()
        alert('Iglesia actualizada correctamente.')
    } catch (err) {
        alert('Error al actualizar la iglesia. Revisa el formulario.')
        console.error(err)
    }
}

const deleteChurch = async (churchId) => {
    if (!confirm('¿Seguro que deseas eliminar esta iglesia?')) {
        return
    }
    try {
        await axios.delete(`/churches/${churchId}`)
        await fetchChurches()
        alert('Iglesia eliminada correctamente.')
    } catch (err) {
        alert('Error al eliminar la iglesia.')
        console.error(err)
    }
}

const regenerateInviteCode = async (churchId) => {
    try {
        const response = await axios.post(`/super-admin/churches/${churchId}/invite-code`)
        const updated = response.data.code
        const idx = churches.value.findIndex((church) => church.id === churchId)
        if (idx !== -1) {
            churches.value[idx].invite_code = updated
        }
        alert('Código de invitación actualizado.')
    } catch (err) {
        alert('Error al generar el código de invitación.')
        console.error(err)
    }
}

onMounted(() => {
    fetchChurches()
})
</script>
