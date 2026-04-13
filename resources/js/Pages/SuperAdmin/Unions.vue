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
})

const editingUnionId = ref(null)
const { tr } = useLocale()

const form = useForm({
    name: '',
})

const isEditing = computed(() => editingUnionId.value !== null)

const resetForm = () => {
    editingUnionId.value = null
    form.reset()
}

const editUnion = (union) => {
    editingUnionId.value = union.id
    form.name = union.name || ''
}

const submit = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            resetForm()
            router.reload({ only: ['unions'] })
        },
    }

    if (isEditing.value) {
        form.put(route('superadmin.unions.update', editingUnionId.value), options)
        return
    }

    form.post(route('superadmin.unions.store'), options)
}

const deactivateUnion = (union) => {
    if (!confirm(tr(`Desactivar union "${union.name}"?`, `Deactivate union "${union.name}"?`))) return
    router.put(route('superadmin.unions.deactivate', union.id), {}, {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['unions'] }),
    })
}

const deleteUnion = (union) => {
    if (!confirm(tr(`Eliminar union "${union.name}"?`, `Delete union "${union.name}"?`))) return
    router.delete(route('superadmin.unions.delete', union.id), {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['unions'] }),
    })
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Superadmin: Uniones', 'Superadmin: Unions') }}</template>

        <div class="max-w-5xl mx-auto space-y-6">
            <div class="bg-white border rounded-lg p-6 space-y-4">
                <h2 class="text-lg font-semibold">{{ isEditing ? tr('Editar union', 'Edit union') : tr('Crear union', 'Create union') }}</h2>

                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <InputLabel for="name" :value="tr('Nombre de la union', 'Union name')" />
                        <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full" required />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <div class="flex gap-2">
                        <PrimaryButton :disabled="form.processing" class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-md">
                            {{ isEditing ? tr('Guardar cambios', 'Save changes') : tr('Crear union', 'Create union') }}
                        </PrimaryButton>
                        <button v-if="isEditing" type="button" @click="resetForm" class="px-4 py-2 rounded border border-gray-300 text-gray-700">
                            {{ tr('Cancelar', 'Cancel') }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="bg-white border rounded-lg p-6">
                <h2 class="text-lg font-semibold mb-3">{{ tr('Uniones registradas', 'Registered unions') }}</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-700">
                            <tr>
                                <th class="text-left px-3 py-2">{{ tr('Nombre', 'Name') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Asociaciones', 'Associations') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Estado', 'Status') }}</th>
                                <th class="text-right px-3 py-2">{{ tr('Acciones', 'Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="props.unions.length === 0">
                                <td colspan="4" class="px-3 py-3 text-gray-500">{{ tr('No hay uniones.', 'There are no unions.') }}</td>
                            </tr>
                            <tr v-for="union in props.unions" :key="union.id" class="border-t">
                                <td class="px-3 py-2">{{ union.name }}</td>
                                <td class="px-3 py-2">{{ union.associations_count ?? 0 }}</td>
                                <td class="px-3 py-2">{{ union.status || tr('activo', 'active') }}</td>
                                <td class="px-3 py-2 text-right space-x-2">
                                    <button type="button" class="text-blue-600 hover:underline" @click="editUnion(union)">{{ tr('Editar', 'Edit') }}</button>
                                    <button type="button" class="text-amber-600 hover:underline" @click="deactivateUnion(union)">{{ tr('Desactivar', 'Deactivate') }}</button>
                                    <button type="button" class="text-red-600 hover:underline" @click="deleteUnion(union)">{{ tr('Eliminar', 'Delete') }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>
