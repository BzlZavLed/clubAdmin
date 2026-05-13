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

        <div class="mx-auto max-w-6xl space-y-4 px-3 sm:px-4 lg:px-0">
            <div class="rounded-lg border bg-white p-4 shadow-sm sm:p-6">
                <h2 class="text-lg font-semibold">{{ isEditing ? tr('Editar asociacion', 'Edit association') : tr('Crear asociacion', 'Create association') }}</h2>

                <form @submit.prevent="submit" class="mt-4 space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <InputLabel for="union_id" :value="tr('Union', 'Union')" />
                            <select id="union_id" v-model="form.union_id" class="mt-1 block w-full rounded-md border-gray-300 p-3 text-base sm:p-2 sm:text-sm" required>
                                <option disabled value="">{{ tr('Selecciona una union', 'Select a union') }}</option>
                                <option v-for="union in props.unions" :key="union.id" :value="union.id">
                                    {{ union.name }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.union_id" />
                        </div>

                        <div>
                            <InputLabel for="name" :value="tr('Nombre de la asociacion', 'Association name')" />
                            <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full text-base sm:text-sm" required />
                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>
                    </div>

                    <div class="flex flex-col gap-2 sm:flex-row">
                        <PrimaryButton :disabled="form.processing" class="w-full justify-center rounded-md bg-red-600 px-6 py-3 text-white hover:bg-red-700 sm:w-auto sm:py-2">
                            {{ isEditing ? tr('Guardar cambios', 'Save changes') : tr('Crear asociacion', 'Create association') }}
                        </PrimaryButton>
                        <button v-if="isEditing" type="button" @click="resetForm" class="w-full rounded border border-gray-300 px-4 py-3 text-gray-700 sm:w-auto sm:py-2">
                            {{ tr('Cancelar', 'Cancel') }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="rounded-lg border bg-white p-4 shadow-sm sm:p-6">
                <h2 class="text-lg font-semibold mb-3">{{ tr('Asociaciones registradas', 'Registered associations') }}</h2>
                <div v-if="props.associations.length === 0" class="rounded border border-dashed border-gray-200 bg-gray-50 p-4 text-sm text-gray-500">
                    {{ tr('No hay asociaciones.', 'There are no associations.') }}
                </div>

                <div v-else class="space-y-3 md:hidden">
                    <article v-for="association in props.associations" :key="`mobile-${association.id}`" class="rounded-lg border border-gray-200 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="break-words font-semibold text-gray-900">{{ association.name }}</h3>
                                <p class="mt-1 break-words text-sm text-gray-600">{{ association.union?.name || '-' }}</p>
                            </div>
                            <span class="shrink-0 rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700">
                                {{ association.status || tr('activo', 'active') }}
                            </span>
                        </div>
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                            <div class="rounded bg-gray-50 p-2">
                                <dt class="text-xs text-gray-500">{{ tr('Distritos', 'Districts') }}</dt>
                                <dd class="font-medium text-gray-900">{{ association.districts_count ?? 0 }}</dd>
                            </div>
                            <div class="rounded bg-gray-50 p-2">
                                <dt class="text-xs text-gray-500">{{ tr('ID', 'ID') }}</dt>
                                <dd class="font-medium text-gray-900">{{ association.id }}</dd>
                            </div>
                        </dl>
                        <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-3">
                            <button type="button" class="rounded border border-blue-200 px-3 py-2 text-sm font-medium text-blue-700" @click="editAssociation(association)">{{ tr('Editar', 'Edit') }}</button>
                            <button type="button" class="rounded border border-amber-200 px-3 py-2 text-sm font-medium text-amber-700" @click="deactivateAssociation(association)">{{ tr('Desactivar', 'Deactivate') }}</button>
                            <button type="button" class="rounded border border-red-200 px-3 py-2 text-sm font-medium text-red-700" @click="deleteAssociation(association)">{{ tr('Eliminar', 'Delete') }}</button>
                        </div>
                    </article>
                </div>

                <div v-if="props.associations.length" class="hidden overflow-x-auto md:block">
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
