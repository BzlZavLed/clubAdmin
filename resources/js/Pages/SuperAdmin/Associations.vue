<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import TextInput from '@/Components/TextInput.vue'
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    unions: { type: Array, default: () => [] },
    associations: { type: Array, default: () => [] },
})

const editingAssociationId = ref(null)
const { tr } = useLocale()

const form = useForm({
    union_id: '',
    name: '',
})

const isEditing = computed(() => editingAssociationId.value !== null)

const resetForm = () => {
    editingAssociationId.value = null
    form.reset()
}

const editAssociation = (association) => {
    editingAssociationId.value = association.id
    form.union_id = association.union_id || ''
    form.name = association.name || ''
}

const submit = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            resetForm()
            router.reload({ only: ['associations'] })
        },
    }

    if (isEditing.value) {
        form.put(route('superadmin.associations.update', editingAssociationId.value), options)
        return
    }

    form.post(route('superadmin.associations.store'), options)
}

const deactivateAssociation = (association) => {
    if (!confirm(tr(`Desactivar asociacion "${association.name}"?`, `Deactivate association "${association.name}"?`))) return
    router.put(route('superadmin.associations.deactivate', association.id), {}, {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['associations'] }),
    })
}

const deleteAssociation = (association) => {
    if (!confirm(tr(`Eliminar asociacion "${association.name}"?`, `Delete association "${association.name}"?`))) return
    router.delete(route('superadmin.associations.delete', association.id), {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['associations'] }),
    })
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Superadmin: Asociaciones', 'Superadmin: Associations') }}</template>

        <div class="max-w-6xl mx-auto space-y-6">
            <div class="bg-white border rounded-lg p-6 space-y-4">
                <h2 class="text-lg font-semibold">{{ isEditing ? tr('Editar asociacion', 'Edit association') : tr('Crear asociacion', 'Create association') }}</h2>

                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <InputLabel for="union_id" :value="tr('Union', 'Union')" />
                            <select id="union_id" v-model="form.union_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                <option disabled value="">{{ tr('Selecciona una union', 'Select a union') }}</option>
                                <option v-for="union in props.unions" :key="union.id" :value="union.id">
                                    {{ union.name }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.union_id" />
                        </div>

                        <div>
                            <InputLabel for="name" :value="tr('Nombre de la asociacion', 'Association name')" />
                            <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <PrimaryButton :disabled="form.processing" class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-md">
                            {{ isEditing ? tr('Guardar cambios', 'Save changes') : tr('Crear asociacion', 'Create association') }}
                        </PrimaryButton>
                        <button v-if="isEditing" type="button" @click="resetForm" class="px-4 py-2 rounded border border-gray-300 text-gray-700">
                            {{ tr('Cancelar', 'Cancel') }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="bg-white border rounded-lg p-6">
                <h2 class="text-lg font-semibold mb-3">{{ tr('Asociaciones registradas', 'Registered associations') }}</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-700">
                            <tr>
                                <th class="text-left px-3 py-2">{{ tr('Asociacion', 'Association') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Union', 'Union') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Distritos', 'Districts') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Estado', 'Status') }}</th>
                                <th class="text-right px-3 py-2">{{ tr('Acciones', 'Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="props.associations.length === 0">
                                <td colspan="5" class="px-3 py-3 text-gray-500">{{ tr('No hay asociaciones.', 'There are no associations.') }}</td>
                            </tr>
                            <tr v-for="association in props.associations" :key="association.id" class="border-t">
                                <td class="px-3 py-2">{{ association.name }}</td>
                                <td class="px-3 py-2">{{ association.union?.name || '-' }}</td>
                                <td class="px-3 py-2">{{ association.districts_count ?? 0 }}</td>
                                <td class="px-3 py-2">{{ association.status || tr('activo', 'active') }}</td>
                                <td class="px-3 py-2 text-right space-x-2">
                                    <button type="button" class="text-blue-600 hover:underline" @click="editAssociation(association)">{{ tr('Editar', 'Edit') }}</button>
                                    <button type="button" class="text-amber-600 hover:underline" @click="deactivateAssociation(association)">{{ tr('Desactivar', 'Deactivate') }}</button>
                                    <button type="button" class="text-red-600 hover:underline" @click="deleteAssociation(association)">{{ tr('Eliminar', 'Delete') }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>
