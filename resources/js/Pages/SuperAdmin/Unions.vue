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
    evaluation_system: 'honors',
})

const isEditing = computed(() => editingUnionId.value !== null)

const resetForm = () => {
    editingUnionId.value = null
    form.reset()
}

const editUnion = (union) => {
    editingUnionId.value = union.id
    form.name = union.name || ''
    form.evaluation_system = union.evaluation_system || 'honors'
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

        <div class="mx-auto max-w-6xl space-y-4 px-3 sm:px-4 lg:px-0">
            <div class="rounded-lg border bg-white p-4 shadow-sm sm:p-6">
                <h2 class="text-lg font-semibold">{{ isEditing ? tr('Editar union', 'Edit union') : tr('Crear union', 'Create union') }}</h2>

                <form @submit.prevent="submit" class="mt-4 space-y-4">
                    <div>
                        <InputLabel for="name" :value="tr('Nombre de la union', 'Union name')" />
                        <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full text-base sm:text-sm" required />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <div>
                        <InputLabel for="evaluation_system" :value="tr('Sistema de evaluación', 'Evaluation system')" />
                        <select id="evaluation_system" v-model="form.evaluation_system" class="mt-1 block w-full rounded-md border-gray-300 p-3 text-base sm:p-2 sm:text-sm" required>
                            <option value="honors">{{ tr('Honores / requisitos', 'Honors / requirements') }}</option>
                            <option value="carpetas">{{ tr('Carpetas', 'Carpetas') }}</option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.evaluation_system" />
                    </div>

                    <div class="flex flex-col gap-2 sm:flex-row">
                        <PrimaryButton :disabled="form.processing" class="w-full justify-center rounded-md bg-red-600 px-6 py-3 text-white hover:bg-red-700 sm:w-auto sm:py-2">
                            {{ isEditing ? tr('Guardar cambios', 'Save changes') : tr('Crear union', 'Create union') }}
                        </PrimaryButton>
                        <button v-if="isEditing" type="button" @click="resetForm" class="w-full rounded border border-gray-300 px-4 py-3 text-gray-700 sm:w-auto sm:py-2">
                            {{ tr('Cancelar', 'Cancel') }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="rounded-lg border bg-white p-4 shadow-sm sm:p-6">
                <h2 class="text-lg font-semibold mb-3">{{ tr('Uniones registradas', 'Registered unions') }}</h2>
                <div v-if="props.unions.length === 0" class="rounded border border-dashed border-gray-200 bg-gray-50 p-4 text-sm text-gray-500">
                    {{ tr('No hay uniones.', 'There are no unions.') }}
                </div>

                <div v-else class="space-y-3 md:hidden">
                    <article v-for="union in props.unions" :key="`mobile-${union.id}`" class="rounded-lg border border-gray-200 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="break-words font-semibold text-gray-900">{{ union.name }}</h3>
                                <p class="mt-1 text-sm text-gray-600">{{ tr('Sistema', 'System') }}: {{ union.evaluation_system || 'honors' }}</p>
                            </div>
                            <span class="shrink-0 rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700">
                                {{ union.status || tr('activo', 'active') }}
                            </span>
                        </div>
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                            <div class="rounded bg-gray-50 p-2">
                                <dt class="text-xs text-gray-500">{{ tr('Asociaciones', 'Associations') }}</dt>
                                <dd class="font-medium text-gray-900">{{ union.associations_count ?? 0 }}</dd>
                            </div>
                            <div class="rounded bg-gray-50 p-2">
                                <dt class="text-xs text-gray-500">{{ tr('ID', 'ID') }}</dt>
                                <dd class="font-medium text-gray-900">{{ union.id }}</dd>
                            </div>
                        </dl>
                        <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-3">
                            <button type="button" class="rounded border border-blue-200 px-3 py-2 text-sm font-medium text-blue-700" @click="editUnion(union)">{{ tr('Editar', 'Edit') }}</button>
                            <button type="button" class="rounded border border-amber-200 px-3 py-2 text-sm font-medium text-amber-700" @click="deactivateUnion(union)">{{ tr('Desactivar', 'Deactivate') }}</button>
                            <button type="button" class="rounded border border-red-200 px-3 py-2 text-sm font-medium text-red-700" @click="deleteUnion(union)">{{ tr('Eliminar', 'Delete') }}</button>
                        </div>
                    </article>
                </div>

                <div v-if="props.unions.length" class="hidden overflow-x-auto md:block">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-700">
                            <tr>
                                <th class="text-left px-3 py-2">{{ tr('Nombre', 'Name') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Sistema', 'System') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Asociaciones', 'Associations') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Estado', 'Status') }}</th>
                                <th class="text-right px-3 py-2">{{ tr('Acciones', 'Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="union in props.unions" :key="union.id" class="border-t">
                                <td class="px-3 py-2">{{ union.name }}</td>
                                <td class="px-3 py-2">{{ union.evaluation_system || 'honors' }}</td>
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
