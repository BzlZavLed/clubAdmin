<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import TextInput from '@/Components/TextInput.vue'
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'

const props = defineProps({
    churches: { type: Array, default: () => [] },
    clubs: { type: Array, default: () => [] },
    subRoles: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
})

const editingUserId = ref(null)

const form = useForm({
    name: '',
    email: '',
    password: '',
    profile_type: 'club_director',
    sub_role: '',
    church_id: '',
    club_id: '',
})

const isEditing = computed(() => editingUserId.value !== null)
const hasSubRoles = computed(() => props.subRoles.length > 0)

const filteredClubs = computed(() => {
    if (!form.church_id) return []
    return props.clubs.filter((club) => Number(club.church_id) === Number(form.church_id))
})

watch(
    () => form.profile_type,
    (profileType) => {
        if (profileType === 'superadmin') {
            form.church_id = ''
            form.club_id = ''
            form.sub_role = ''
        }
        if (profileType !== 'club_personal') {
            form.sub_role = ''
        }
    }
)

watch(
    () => form.church_id,
    () => {
        if (form.profile_type !== 'superadmin') {
            const found = filteredClubs.value.some((club) => Number(club.id) === Number(form.club_id))
            if (!found) form.club_id = ''
        }
    }
)

const resetForm = () => {
    editingUserId.value = null
    form.reset()
    form.profile_type = 'club_director'
}

const editUser = (user) => {
    editingUserId.value = user.id
    form.name = user.name || ''
    form.email = user.email || ''
    form.password = ''
    form.profile_type = user.profile_type || 'club_director'
    form.sub_role = user.sub_role || ''
    form.church_id = user.church_id || ''
    form.club_id = user.club_id || ''
}

const submit = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            resetForm()
            router.reload({ only: ['users'] })
        },
    }

    if (isEditing.value) {
        form.put(route('superadmin.users.update', editingUserId.value), options)
        return
    }

    form.post(route('superadmin.users.store'), options)
}

const churchNameById = (churchId) => {
    const church = props.churches.find((item) => Number(item.id) === Number(churchId))
    return church?.church_name || '-'
}

const clubNameById = (clubId) => {
    const club = props.clubs.find((item) => Number(item.id) === Number(clubId))
    return club?.club_name || '-'
}

const deactivateUser = (user) => {
    if (!confirm(`Deactivate user "${user.name}"?`)) return
    router.put(
        route('superadmin.users.deactivate', user.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => router.reload({ only: ['users'] }),
        }
    )
}

const deleteUser = (user) => {
    if (!confirm(`Delete user "${user.name}"?`)) return
    router.delete(route('superadmin.users.delete', user.id), {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['users'] }),
    })
}
</script>

<template>
    <PathfinderLayout>
        <template #title>Superadmin: Usuarios</template>

        <div class="max-w-6xl mx-auto space-y-6">
            <div class="bg-white border rounded-lg p-6 space-y-4">
                <h2 class="text-lg font-semibold">{{ isEditing ? 'Editar usuario' : 'Crear usuario' }}</h2>

                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <InputLabel for="profile_type" value="Tipo de perfil" />
                            <select id="profile_type" v-model="form.profile_type" class="mt-1 block w-full rounded-md border-gray-300" required>
                                <option value="club_director">Director de club</option>
                                <option value="club_personal">Personal de club</option>
                                <option value="superadmin">Superadmin</option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.profile_type" />
                        </div>
                        <div v-if="form.profile_type === 'club_personal'">
                            <InputLabel for="sub_role" value="Subrol" />
                            <select
                                id="sub_role"
                                v-model="form.sub_role"
                                class="mt-1 block w-full rounded-md border-gray-300"
                                :required="hasSubRoles"
                                :disabled="!hasSubRoles"
                            >
                                <option disabled value="">
                                    {{ hasSubRoles ? 'Selecciona un subrol' : 'No hay subroles disponibles' }}
                                </option>
                                <option v-for="role in props.subRoles" :key="role.id" :value="role.key">
                                    {{ role.label }}
                                </option>
                            </select>
                            <p v-if="!hasSubRoles" class="mt-2 text-sm text-amber-700">
                                Puedes crear personal de club sin subrol hasta que se configuren subroles.
                            </p>
                            <InputError class="mt-2" :message="form.errors.sub_role" />
                        </div>
                    </div>

                    <div v-if="form.profile_type !== 'superadmin'" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <InputLabel for="church_id" value="Iglesia" />
                            <select id="church_id" v-model="form.church_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                <option disabled value="">Selecciona una iglesia</option>
                                <option v-for="church in props.churches" :key="church.id" :value="church.id">
                                    {{ church.church_name }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.church_id" />
                        </div>

                        <div>
                            <InputLabel for="club_id" value="Club (opcional)" />
                            <select id="club_id" v-model="form.club_id" class="mt-1 block w-full rounded-md border-gray-300">
                                <option value="">Sin club por ahora</option>
                                <option v-for="club in filteredClubs" :key="club.id" :value="club.id">
                                    {{ club.club_name }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.club_id" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <InputLabel for="name" value="Nombre" />
                            <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>

                        <div>
                            <InputLabel for="email" value="Correo" />
                            <TextInput id="email" v-model="form.email" type="email" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.email" />
                        </div>

                        <div>
                            <InputLabel for="password" :value="isEditing ? 'Nueva contrasena (opcional)' : 'Contrasena temporal'" />
                            <TextInput id="password" v-model="form.password" type="password" class="mt-1 block w-full" :required="!isEditing" />
                            <InputError class="mt-2" :message="form.errors.password" />
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <PrimaryButton :disabled="form.processing" class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-md">
                            {{ isEditing ? 'Guardar cambios' : 'Crear usuario' }}
                        </PrimaryButton>
                        <button v-if="isEditing" type="button" @click="resetForm" class="px-4 py-2 rounded border border-gray-300 text-gray-700">
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>

            <div class="bg-white border rounded-lg p-6">
                <h2 class="text-lg font-semibold mb-3">Usuarios existentes</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-700">
                            <tr>
                                <th class="text-left px-3 py-2">Nombre</th>
                                <th class="text-left px-3 py-2">Correo</th>
                                <th class="text-left px-3 py-2">Perfil</th>
                                <th class="text-left px-3 py-2">Iglesia</th>
                                <th class="text-left px-3 py-2">Club</th>
                                <th class="text-left px-3 py-2">Estado</th>
                                <th class="text-right px-3 py-2">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="props.users.length === 0">
                                <td colspan="7" class="px-3 py-3 text-gray-500">No hay usuarios.</td>
                            </tr>
                            <tr v-for="user in props.users" :key="user.id" class="border-t">
                                <td class="px-3 py-2">{{ user.name }}</td>
                                <td class="px-3 py-2">{{ user.email }}</td>
                                <td class="px-3 py-2">{{ user.profile_type }}</td>
                                <td class="px-3 py-2">{{ churchNameById(user.church_id) }}</td>
                                <td class="px-3 py-2">{{ clubNameById(user.club_id) }}</td>
                                <td class="px-3 py-2">{{ user.status || 'active' }}</td>
                                <td class="px-3 py-2 text-right space-x-2">
                                    <button type="button" class="text-blue-600 hover:underline" @click="editUser(user)">Editar</button>
                                    <button type="button" class="text-amber-600 hover:underline" @click="deactivateUser(user)">Desactivar</button>
                                    <button type="button" class="text-red-600 hover:underline" @click="deleteUser(user)">Eliminar</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>
