<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    churches: { type: Array, default: () => [] },
    directors: { type: Array, default: () => [] },
    clubs: { type: Array, default: () => [] },
})

const editingClubId = ref(null)
const { tr } = useLocale()

const form = useForm({
    club_name: '',
    church_id: '',
    director_user_id: '',
    creation_date: '',
    pastor_name: '',
    conference_name: '',
    conference_region: '',
    club_type: 'pathfinders',
    evaluation_system: 'honors',
})

const isEditing = computed(() => editingClubId.value !== null)

const resetForm = () => {
    editingClubId.value = null
    form.reset()
    form.club_type = 'pathfinders'
    form.evaluation_system = 'honors'
}

const editClub = (club) => {
    editingClubId.value = club.id
    form.club_name = club.club_name || ''
    form.church_id = club.church_id || ''
    form.director_user_id = club.user_id || ''
    form.creation_date = club.creation_date || ''
    form.pastor_name = club.pastor_name || ''
    form.conference_name = club.conference_name || ''
    form.conference_region = club.conference_region || ''
    form.club_type = club.club_type || 'pathfinders'
    form.evaluation_system = club.evaluation_system || 'honors'
}

const selectedChurch = computed(() =>
    props.churches.find((item) => Number(item.id) === Number(form.church_id)) || null
)

const inheritedEvaluationSystemLabel = computed(() => {
    const system = selectedChurch.value?.evaluation_system || form.evaluation_system || 'honors'
    return system === 'carpetas'
        ? tr('Carpetas', 'Carpetas')
        : tr('Honores / requisitos', 'Honors / requirements')
})

const submit = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            resetForm()
            router.reload({ only: ['clubs', 'directors'] })
        },
    }

    if (isEditing.value) {
        form.put(route('superadmin.clubs.update', editingClubId.value), options)
        return
    }

    form.post(route('superadmin.clubs.store'), options)
}

const churchNameById = (churchId) => {
    const church = props.churches.find((item) => Number(item.id) === Number(churchId))
    return church?.church_name || '-'
}

const directorLabelById = (directorId) => {
    const director = props.directors.find((item) => Number(item.id) === Number(directorId))
    return director ? `${director.name} (${director.email})` : '-'
}

const deactivateClub = (club) => {
    if (!confirm(tr(`Desactivar club "${club.club_name}"?`, `Deactivate club "${club.club_name}"?`))) return
    router.put(
        route('superadmin.clubs.deactivate', club.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => router.reload({ only: ['clubs'] }),
        }
    )
}

const deleteClub = (club) => {
    if (!confirm(tr(`Eliminar club "${club.club_name}"? Esto lo ocultará de las listas activas.`, `Delete club "${club.club_name}"? This will hide it from active lists.`))) return
    router.delete(route('superadmin.clubs.delete', club.id), {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['clubs'] }),
    })
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Superadmin: Clubes', 'Superadmin: Clubs') }}</template>

        <div class="max-w-5xl mx-auto space-y-6">
            <div class="bg-white border rounded-lg p-6 space-y-4">
                <h2 class="text-lg font-semibold">{{ isEditing ? tr('Editar club', 'Edit club') : tr('Crear club', 'Create club') }}</h2>

                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <InputLabel for="church_id" :value="tr('Iglesia', 'Church')" />
                        <select id="church_id" v-model="form.church_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                            <option disabled value="">{{ tr('Selecciona una iglesia', 'Select a church') }}</option>
                            <option v-for="church in props.churches" :key="church.id" :value="church.id">
                                {{ church.church_name }}{{ church.union_name ? ` - ${church.union_name}` : '' }}
                            </option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.church_id" />
                    </div>

                    <div>
                        <InputLabel for="director_user_id" :value="tr('Director (usuario)', 'Director (user)')" />
                        <select id="director_user_id" v-model="form.director_user_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                            <option disabled value="">{{ tr('Selecciona un director', 'Select a director') }}</option>
                            <option v-for="director in props.directors" :key="director.id" :value="director.id">
                                {{ director.name }} ({{ director.email }})
                            </option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.director_user_id" />
                    </div>

                    <div>
                        <InputLabel for="club_name" :value="tr('Nombre del club', 'Club name')" />
                        <input id="club_name" v-model="form.club_name" type="text" class="mt-1 block w-full rounded-md border-gray-300" required />
                        <InputError class="mt-2" :message="form.errors.club_name" />
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <InputLabel for="club_type" :value="tr('Tipo', 'Type')" />
                            <select id="club_type" v-model="form.club_type" class="mt-1 block w-full rounded-md border-gray-300" required>
                                <option value="adventurers">Adventurers</option>
                                <option value="pathfinders">Pathfinders</option>
                                <option value="master_guide">Master Guide</option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.club_type" />
                        </div>
                        <div>
                            <InputLabel for="creation_date" :value="tr('Fecha de creacion', 'Creation date')" />
                            <input id="creation_date" v-model="form.creation_date" type="date" class="mt-1 block w-full rounded-md border-gray-300" />
                            <InputError class="mt-2" :message="form.errors.creation_date" />
                        </div>
                    </div>

                    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                        <div class="font-medium">{{ tr('Sistema de evaluación heredado', 'Inherited evaluation system') }}</div>
                        <div class="mt-1">{{ inheritedEvaluationSystemLabel }}</div>
                        <div v-if="selectedChurch?.union_name" class="mt-1 text-xs text-blue-700">
                            {{ tr('Definido por la unión asociada a la iglesia seleccionada.', 'Defined by the union linked to the selected church.') }}
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <InputLabel for="pastor_name" :value="tr('Pastor', 'Pastor')" />
                            <input id="pastor_name" v-model="form.pastor_name" type="text" class="mt-1 block w-full rounded-md border-gray-300" />
                            <InputError class="mt-2" :message="form.errors.pastor_name" />
                        </div>
                        <div>
                            <InputLabel for="conference_name" :value="tr('Conferencia', 'Conference')" />
                            <input id="conference_name" v-model="form.conference_name" type="text" class="mt-1 block w-full rounded-md border-gray-300" />
                            <InputError class="mt-2" :message="form.errors.conference_name" />
                        </div>
                        <div>
                            <InputLabel for="conference_region" :value="tr('Region', 'Region')" />
                            <input id="conference_region" v-model="form.conference_region" type="text" class="mt-1 block w-full rounded-md border-gray-300" />
                            <InputError class="mt-2" :message="form.errors.conference_region" />
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <PrimaryButton :disabled="form.processing" class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-md">
                            {{ isEditing ? tr('Guardar cambios', 'Save changes') : tr('Crear club', 'Create club') }}
                        </PrimaryButton>
                        <button v-if="isEditing" type="button" @click="resetForm" class="px-4 py-2 rounded border border-gray-300 text-gray-700">
                            {{ tr('Cancelar', 'Cancel') }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="bg-white border rounded-lg p-6">
                <h2 class="text-lg font-semibold mb-3">{{ tr('Clubes existentes', 'Existing clubs') }}</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-700">
                            <tr>
                                <th class="text-left px-3 py-2">{{ tr('Club', 'Club') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Iglesia', 'Church') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Director', 'Director') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Tipo', 'Type') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Sistema', 'System') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Estado', 'Status') }}</th>
                                <th class="text-right px-3 py-2">{{ tr('Acciones', 'Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="props.clubs.length === 0">
                                <td colspan="7" class="px-3 py-3 text-gray-500">{{ tr('No hay clubes.', 'There are no clubs.') }}</td>
                            </tr>
                            <tr v-for="club in props.clubs" :key="club.id" class="border-t">
                                <td class="px-3 py-2">{{ club.club_name }}</td>
                                <td class="px-3 py-2">{{ churchNameById(club.church_id) }}</td>
                                <td class="px-3 py-2">{{ directorLabelById(club.user_id) }}</td>
                                <td class="px-3 py-2">{{ club.club_type }}</td>
                                <td class="px-3 py-2">{{ club.evaluation_system || 'honors' }}</td>
                                <td class="px-3 py-2">{{ club.status || tr('activo', 'active') }}</td>
                                <td class="px-3 py-2 text-right space-x-2">
                                    <button type="button" class="text-blue-600 hover:underline" @click="editClub(club)">{{ tr('Editar', 'Edit') }}</button>
                                    <button type="button" class="text-amber-600 hover:underline" @click="deactivateClub(club)">{{ tr('Desactivar', 'Deactivate') }}</button>
                                    <button type="button" class="text-red-600 hover:underline" @click="deleteClub(club)">{{ tr('Eliminar', 'Delete') }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>
