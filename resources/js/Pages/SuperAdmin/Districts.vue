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
    associations: { type: Array, default: () => [] },
    districts: { type: Array, default: () => [] },
})

const editingDistrictId = ref(null)
const { tr } = useLocale()

const form = useForm({
    association_id: '',
    name: '',
})

const isEditing = computed(() => editingDistrictId.value !== null)

const resetForm = () => {
    editingDistrictId.value = null
    form.reset()
}

const editDistrict = (district) => {
    editingDistrictId.value = district.id
    form.association_id = district.association_id || ''
    form.name = district.name || ''
}

const submit = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            resetForm()
            router.reload({ only: ['districts'] })
        },
    }

    if (isEditing.value) {
        form.put(route('superadmin.districts.update', editingDistrictId.value), options)
        return
    }

    form.post(route('superadmin.districts.store'), options)
}

const deactivateDistrict = (district) => {
    if (!confirm(tr(`Desactivar distrito "${district.name}"?`, `Deactivate district "${district.name}"?`))) return
    router.put(route('superadmin.districts.deactivate', district.id), {}, {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['districts'] }),
    })
}

const deleteDistrict = (district) => {
    if (!confirm(tr(`Eliminar distrito "${district.name}"?`, `Delete district "${district.name}"?`))) return
    router.delete(route('superadmin.districts.delete', district.id), {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['districts'] }),
    })
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Superadmin: Distritos', 'Superadmin: Districts') }}</template>

        <div class="max-w-6xl mx-auto space-y-6">
            <div class="bg-white border rounded-lg p-6 space-y-4">
                <h2 class="text-lg font-semibold">{{ isEditing ? tr('Editar distrito', 'Edit district') : tr('Crear distrito', 'Create district') }}</h2>

                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <InputLabel for="association_id" :value="tr('Asociacion', 'Association')" />
                            <select id="association_id" v-model="form.association_id" class="mt-1 block w-full rounded-md border-gray-300" required>
                                <option disabled value="">{{ tr('Selecciona una asociacion', 'Select an association') }}</option>
                                <option v-for="association in props.associations" :key="association.id" :value="association.id">
                                    {{ association.name }}{{ association.union?.name ? ` - ${association.union.name}` : '' }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.association_id" />
                        </div>

                        <div>
                            <InputLabel for="name" :value="tr('Nombre del distrito', 'District name')" />
                            <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <PrimaryButton :disabled="form.processing" class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-md">
                            {{ isEditing ? tr('Guardar cambios', 'Save changes') : tr('Crear distrito', 'Create district') }}
                        </PrimaryButton>
                        <button v-if="isEditing" type="button" @click="resetForm" class="px-4 py-2 rounded border border-gray-300 text-gray-700">
                            {{ tr('Cancelar', 'Cancel') }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="bg-white border rounded-lg p-6">
                <h2 class="text-lg font-semibold mb-3">{{ tr('Distritos registrados', 'Registered districts') }}</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-700">
                            <tr>
                                <th class="text-left px-3 py-2">{{ tr('Distrito', 'District') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Asociacion', 'Association') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Union', 'Union') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Iglesias', 'Churches') }}</th>
                                <th class="text-left px-3 py-2">{{ tr('Estado', 'Status') }}</th>
                                <th class="text-right px-3 py-2">{{ tr('Acciones', 'Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="props.districts.length === 0">
                                <td colspan="6" class="px-3 py-3 text-gray-500">{{ tr('No hay distritos.', 'There are no districts.') }}</td>
                            </tr>
                            <tr v-for="district in props.districts" :key="district.id" class="border-t">
                                <td class="px-3 py-2">{{ district.name }}</td>
                                <td class="px-3 py-2">{{ district.association?.name || '-' }}</td>
                                <td class="px-3 py-2">{{ district.association?.union?.name || '-' }}</td>
                                <td class="px-3 py-2">{{ district.churches_count ?? 0 }}</td>
                                <td class="px-3 py-2">{{ district.status || tr('activo', 'active') }}</td>
                                <td class="px-3 py-2 text-right space-x-2">
                                    <button type="button" class="text-blue-600 hover:underline" @click="editDistrict(district)">{{ tr('Editar', 'Edit') }}</button>
                                    <button type="button" class="text-amber-600 hover:underline" @click="deactivateDistrict(district)">{{ tr('Desactivar', 'Deactivate') }}</button>
                                    <button type="button" class="text-red-600 hover:underline" @click="deleteDistrict(district)">{{ tr('Eliminar', 'Delete') }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>
