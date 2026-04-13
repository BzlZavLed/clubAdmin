<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import TextInput from '@/Components/TextInput.vue'
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    churches: { type: Array, default: () => [] },
    clubs: { type: Array, default: () => [] },
    districts: { type: Array, default: () => [] },
    associations: { type: Array, default: () => [] },
    unions: { type: Array, default: () => [] },
    subRoles: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
})

const editingUserId = ref(null)
const { tr } = useLocale()

const form = useForm({
    name: '',
    email: '',
    password: '',
    profile_type: 'club_director',
    sub_role: '',
    church_id: '',
    club_id: '',
    district_id: '',
    association_id: '',
    union_id: '',
})

const isEditing = computed(() => editingUserId.value !== null)
const hasSubRoles = computed(() => props.subRoles.length > 0)

const churchScopedProfiles = ['club_director', 'club_personal']
const districtScopedProfiles = ['district_pastor', 'district_secretary']

const isChurchScoped = computed(() => churchScopedProfiles.includes(form.profile_type))
const isDistrictScoped = computed(() => districtScopedProfiles.includes(form.profile_type))
const isAssociationScoped = computed(() => form.profile_type === 'association_youth_director')
const isUnionScoped = computed(() => form.profile_type === 'union_youth_director')

const filteredClubs = computed(() => {
    if (!form.church_id) return []
    return props.clubs.filter((club) => Number(club.church_id) === Number(form.church_id))
})

const districtLabel = (district) => {
    const parts = [
        district.name,
        district.association?.name,
        district.association?.union?.name,
    ].filter(Boolean)

    return parts.join(' - ')
}

const associationLabel = (association) => {
    const parts = [
        association.name,
        association.union?.name,
    ].filter(Boolean)

    return parts.join(' - ')
}

watch(
    () => form.profile_type,
    (profileType) => {
        if (profileType !== 'club_personal') {
            form.sub_role = ''
        }

        if (!churchScopedProfiles.includes(profileType)) {
            form.church_id = ''
            form.club_id = ''
        }

        if (!districtScopedProfiles.includes(profileType)) {
            form.district_id = ''
        }

        if (profileType !== 'association_youth_director') {
            form.association_id = ''
        }

        if (profileType !== 'union_youth_director') {
            form.union_id = ''
        }
    }
)

watch(
    () => form.church_id,
    () => {
        if (isChurchScoped.value) {
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
    form.church_id = user.scope_type === 'church' || user.scope_type === 'club' ? (user.church_id || '') : ''
    form.club_id = user.scope_type === 'club' ? (user.club_id || '') : ''
    form.district_id = user.scope_type === 'district' ? (user.scope_id || '') : ''
    form.association_id = user.scope_type === 'association' ? (user.scope_id || '') : ''
    form.union_id = user.scope_type === 'union' ? (user.scope_id || '') : ''
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

const scopeLabel = (user) => {
    if (user.scope_type === 'global') return 'Global'

    if (user.scope_type === 'club') {
        return `${churchNameById(user.church_id)} / ${clubNameById(user.club_id)}`
    }

    if (user.scope_type === 'church') {
        return churchNameById(user.church_id)
    }

    if (user.scope_type === 'district') {
        const district = props.districts.find((item) => Number(item.id) === Number(user.scope_id))
        return district ? districtLabel(district) : `Distrito #${user.scope_id}`
    }

    if (user.scope_type === 'association') {
        const association = props.associations.find((item) => Number(item.id) === Number(user.scope_id))
        return association ? associationLabel(association) : `${tr('Asociacion', 'Association')} #${user.scope_id}`
    }

    if (user.scope_type === 'union') {
        const union = props.unions.find((item) => Number(item.id) === Number(user.scope_id))
        return union?.name || `${tr('Union', 'Union')} #${user.scope_id}`
    }

    return '-'
}

const deactivateUser = (user) => {
    if (!confirm(tr(`Desactivar usuario "${user.name}"?`, `Deactivate user "${user.name}"?`))) return
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
    if (!confirm(tr(`Eliminar usuario "${user.name}"?`, `Delete user "${user.name}"?`))) return
    router.delete(route('superadmin.users.delete', user.id), {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['users'] }),
    })
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Superadmin: Usuarios', 'Superadmin: Users') }}</template>

        <div class="max-w-6xl mx-auto space-y-6">
            <div class="bg-white border rounded-lg p-6 space-y-4">
                <h2 class="text-lg font-semibold">{{ isEditing ? tr('Editar usuario', 'Edit user') : tr('Crear usuario', 'Create user') }}</h2>

                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <InputLabel for="profile_type" :value="tr('Tipo de perfil', 'Profile type')" />
                            <select id="profile_type" v-model="form.profile_type" class="mt-1 block w-full rounded-md border-gray-300" required>
                                <option value="club_director">{{ tr('Director de club', 'Club director') }}</option>
                                <option value="club_personal">{{ tr('Personal de club', 'Club staff') }}</option>
                                <option value="district_pastor">{{ tr('Pastor distrital', 'District pastor') }}</option>
                                <option value="district_secretary">{{ tr('Secretario distrital', 'District secretary') }}</option>
                                <option value="association_youth_director">{{ tr('Dir. de jóvenes asociación', 'Association youth director') }}</option>
                                <option value="union_youth_director">{{ tr('Dir. de jóvenes unión', 'Union youth director') }}</option>
                                <option value="superadmin">Superadmin</option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.profile_type" />
                        </div>
                        <div v-if="form.profile_type === 'club_personal'">
                            <InputLabel for="sub_role" :value="tr('Subrol', 'Sub-role')" />
                            <select
                                id="sub_role"
                                v-model="form.sub_role"
                                class="mt-1 block w-full rounded-md border-gray-300"
                                :required="hasSubRoles"
                                :disabled="!hasSubRoles"
                            >
                                <option disabled value="">
                                    {{ hasSubRoles ? tr('Selecciona un subrol', 'Select a sub-role') : tr('No hay subroles disponibles', 'No sub-roles available') }}
                                </option>
                                <option v-for="role in props.subRoles" :key="role.id" :value="role.key">
                                    {{ role.label }}
                                </option>
                            </select>
                            <p v-if="!hasSubRoles" class="mt-2 text-sm text-amber-700">
                                {{ tr('Puedes crear personal de club sin subrol hasta que se configuren subroles.', 'You can create club staff without a sub-role until sub-roles are configured.') }}
                            </p>
                            <InputError class="mt-2" :message="form.errors.sub_role" />
                        </div>
                    </div>

                    <div v-if="isChurchScoped" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <InputLabel for="church_id" :value="tr('Iglesia', 'Church')" />
                            <select id="church_id" v-model="form.church_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                <option disabled value="">{{ tr('Selecciona una iglesia', 'Select a church') }}</option>
                                <option v-for="church in props.churches" :key="church.id" :value="church.id">
                                    {{ church.church_name }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.church_id" />
                        </div>

                        <div>
                            <InputLabel for="club_id" :value="tr('Club (opcional)', 'Club (optional)')" />
                            <select id="club_id" v-model="form.club_id" class="mt-1 block w-full rounded-md border-gray-300">
                                <option value="">{{ tr('Sin club por ahora', 'No club for now') }}</option>
                                <option v-for="club in filteredClubs" :key="club.id" :value="club.id">
                                    {{ club.club_name }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.club_id" />
                        </div>
                    </div>

                    <div v-if="isDistrictScoped" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <InputLabel for="district_id" :value="tr('Distrito', 'District')" />
                            <select id="district_id" v-model="form.district_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                <option disabled value="">{{ tr('Selecciona un distrito', 'Select a district') }}</option>
                                <option v-for="district in props.districts" :key="district.id" :value="district.id">
                                    {{ districtLabel(district) }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.district_id" />
                        </div>
                    </div>

                    <div v-if="isAssociationScoped" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <InputLabel for="association_id" :value="tr('Asociación', 'Association')" />
                            <select id="association_id" v-model="form.association_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                <option disabled value="">{{ tr('Selecciona una asociación', 'Select an association') }}</option>
                                <option v-for="association in props.associations" :key="association.id" :value="association.id">
                                    {{ associationLabel(association) }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.association_id" />
                        </div>
                    </div>

                    <div v-if="isUnionScoped" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <InputLabel for="union_id" :value="tr('Unión', 'Union')" />
                            <select id="union_id" v-model="form.union_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                <option disabled value="">{{ tr('Selecciona una unión', 'Select a union') }}</option>
                                <option v-for="union in props.unions" :key="union.id" :value="union.id">
                                    {{ union.name }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.union_id" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <InputLabel for="name" :value="tr('Nombre', 'Name')" />
                            <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>

                        <div>
                            <InputLabel for="email" :value="tr('Correo', 'Email')" />
                            <TextInput id="email" v-model="form.email" type="email" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.email" />
                        </div>

                        <div>
                            <InputLabel for="password" :value="isEditing ? tr('Nueva contrasena (opcional)', 'New password (optional)') : tr('Contrasena temporal', 'Temporary password')" />
                            <TextInput id="password" v-model="form.password" type="password" class="mt-1 block w-full" :required="!isEditing" />
                            <InputError class="mt-2" :message="form.errors.password" />
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <PrimaryButton :disabled="form.processing" class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-md">
                            {{ isEditing ? tr('Guardar cambios', 'Save changes') : tr('Crear usuario', 'Create user') }}
                        </PrimaryButton>
                        <button v-if="isEditing" type="button" @click="resetForm" class="px-4 py-2 rounded border border-gray-300 text-gray-700">
                            {{ tr('Cancelar', 'Cancel') }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="bg-white border rounded-lg p-6">
                <h2 class="text-lg font-semibold mb-3">{{ tr('Usuarios existentes', 'Existing users') }}</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-700">
                            <tr>
                                <th class="text-left px-3 py-2">{{ tr('Nombre', 'Name') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Correo', 'Email') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Perfil', 'Profile') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Alcance', 'Scope') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Estado', 'Status') }}</th>
                                <th class="text-right px-3 py-2">{{ tr('Acciones', 'Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="props.users.length === 0">
                                <td colspan="6" class="px-3 py-3 text-gray-500">{{ tr('No hay usuarios.', 'There are no users.') }}</td>
                            </tr>
                            <tr v-for="user in props.users" :key="user.id" class="border-t">
                                <td class="px-3 py-2">{{ user.name }}</td>
                                <td class="px-3 py-2">{{ user.email }}</td>
                                <td class="px-3 py-2">{{ user.role_key || user.profile_type }}</td>
                                <td class="px-3 py-2">{{ scopeLabel(user) }}</td>
                                <td class="px-3 py-2">{{ user.status || tr('activo', 'active') }}</td>
                                <td class="px-3 py-2 text-right space-x-2">
                                    <button type="button" class="text-blue-600 hover:underline" @click="editUser(user)">{{ tr('Editar', 'Edit') }}</button>
                                    <button type="button" class="text-amber-600 hover:underline" @click="deactivateUser(user)">{{ tr('Desactivar', 'Deactivate') }}</button>
                                    <button type="button" class="text-red-600 hover:underline" @click="deleteUser(user)">{{ tr('Eliminar', 'Delete') }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>
