<script setup>
import { useForm } from '@inertiajs/vue3'
import { Head, Link } from '@inertiajs/vue3'
import { defineProps, ref, watch } from 'vue'
import { useToast } from 'vue-toastification'
const clubLoadError = ref(false)

const props = defineProps({
    churches: Array,
});
const clubs = ref([])

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    church_name: '',
    church_id: '',
    club_id: ''
})

const submit = () => {
    form.post('/register-parent', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
const toast = useToast()
watch(
    () => form.church_id,
    async (newChurchId) => {
        const selected = props.churches.find(c => c.id === Number(newChurchId))
        form.church_name = selected ? selected.church_name : ''
        if (!newChurchId) {
            clubs.value = []
            clubLoadError.value = false
            return
        }
        if (newChurchId) {
            try {
                const response = await axios.get(`/churches/${newChurchId}/clubs`)
                console.log('Fetched clubs:', response.data)
                clubs.value = response.data
                if (clubs.value.length === 0) {
                    clubLoadError.value = true
                    toast.error('No se encontraron clubes para la iglesia seleccionada.')
                } else {
                    clubLoadError.value = false
                }
            } catch (err) {
                console.error('Error fetching clubs:', err)
                toast.error('No se pudieron cargar los clubes.')
                clubs.value = []
                clubLoadError.value = true
            }
        } else {
            clubs.value = []
        }
    }
)
</script>

<template>
<Head title="Registro de padres" />

<div class="min-h-screen flex items-center justify-center bg-gray-100 px-4">
    <div class="max-w-md w-full bg-white p-6 rounded shadow">
        <h1 class="text-2xl font-bold mb-4 text-center">Registro de padres</h1>

        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700">Nombre completo</label>
                <input v-model="form.name" type="text" id="name" required class="w-full mt-1 p-2 border rounded" />
                <p v-if="form.errors.name" class="text-sm text-red-600 mt-1">{{ form.errors.name }}</p>
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700">Correo electrónico</label>
                <input v-model="form.email" type="email" id="email" required class="w-full mt-1 p-2 border rounded" />
                <p v-if="form.errors.email" class="text-sm text-red-600 mt-1">{{ form.errors.email }}</p>
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">Contraseña</label>
                <input v-model="form.password" type="password" id="password" required class="w-full mt-1 p-2 border rounded" />
                <p v-if="form.errors.password" class="text-sm text-red-600 mt-1">{{ form.errors.password }}</p>
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Confirmar contraseña</label>
                <input v-model="form.password_confirmation" type="password" id="password_confirmation" required class="w-full mt-1 p-2 border rounded" />
            </div>
            <div class="mb-4">
                <label for="church_name" class="block text-sm font-medium text-gray-700">Seleccionar iglesia</label>
                <select id="church_id" v-model="form.church_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-red-600 focus:border-red-600" required>
                    <option disabled value="">Selecciona una iglesia</option>
                    <option v-for="church in churches" :key="church.id" :value="church.id">
                        {{ church.church_name }}
                    </option>
                </select>
                <span v-if="form.errors.church_name" class="text-red-500 text-sm">{{ form.errors.church_name }}</span>
            </div>
            <div class="mb-4">
                <label for="club_id" class="block text-sm font-medium text-gray-700">Seleccionar club</label>
                <select v-model="form.club_id" id="club_id" class="w-full border rounded p-2" required>
                    <option disabled value="">-- Selecciona un club --</option>
                    <option v-for="club in clubs" :key="club.id" :value="club.id">
                        {{ club.club_name }} ({{ club.club_type }})
                    </option>
                </select>
                <span v-if="form.errors.club_id" class="text-red-500 text-sm">{{ form.errors.club_id }}</span>
            </div>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded" :disabled="form.processing || clubLoadError">
                Registrarse
            </button>
        </form>

        <p class="mt-4 text-center text-sm text-gray-600">
            ¿Ya tienes una cuenta?
            <Link href="/login" class="text-blue-600 hover:underline">Iniciar sesión</Link>
        </p>
    </div>
</div>
</template>
