<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    union: { type: Object, required: true },
    associations: { type: Array, default: () => [] },
})

const { tr } = useLocale()
const showAddForm = ref(false)
const editingId = ref(null)

const addForm = useForm({
    name: '',
})

const editForms = Object.fromEntries(
    props.associations.map((association) => [
        association.id,
        useForm({
            name: association.name ?? '',
        }),
    ])
)

const submitAdd = () => {
    addForm.post(route('union.associations.store'), {
        preserveScroll: true,
        onSuccess: () => {
            addForm.reset()
            showAddForm.value = false
        },
    })
}

const startEdit = (association) => {
    editingId.value = association.id
}

const cancelEdit = (association) => {
    const form = editForms[association.id]
    form.name = association.name ?? ''
    form.clearErrors()
    editingId.value = null
}

const saveAssociation = (association) => {
    editForms[association.id].patch(route('union.associations.update', association.id), {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null
        },
    })
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Asociaciones', 'Associations') }}</template>

        <div class="space-y-6">
            <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ union.name }}</h2>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ tr('Sistema de evaluación', 'Evaluation system') }}: {{ union.evaluation_system || 'honors' }}
                        </p>
                        <p class="mt-2 text-sm text-gray-600">
                            {{ tr('La unión administra las asociaciones. Los distritos creados dentro de cada asociación se reflejan aquí automáticamente.', 'The union manages associations. Districts created inside each association are reflected here automatically.') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                        @click="showAddForm = !showAddForm"
                    >
                        {{ showAddForm ? tr('Cancelar', 'Cancel') : tr('+ Agregar asociación', '+ Add association') }}
                    </button>
                </div>
            </section>

            <section v-if="showAddForm" class="rounded-2xl border border-blue-200 bg-blue-50 p-6 shadow-sm">
                <h3 class="mb-4 text-sm font-semibold text-blue-900">{{ tr('Nueva asociación', 'New association') }}</h3>
                <form class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto]" @submit.prevent="submitAdd">
                    <div>
                        <InputLabel :value="tr('Nombre de la asociación', 'Association name')" />
                        <input
                            v-model="addForm.name"
                            type="text"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                        />
                        <InputError class="mt-1" :message="addForm.errors.name" />
                    </div>
                    <div class="self-end">
                        <PrimaryButton type="submit" :disabled="addForm.processing">
                            {{ tr('Guardar asociación', 'Save association') }}
                        </PrimaryButton>
                    </div>
                </form>
            </section>

            <section v-if="!associations.length" class="rounded-2xl border border-dashed border-gray-200 bg-white p-8 text-center text-sm text-gray-400 shadow-sm">
                {{ tr('No hay asociaciones registradas. Agrega la primera.', 'There are no associations registered. Add the first one.') }}
            </section>

            <section v-else class="grid gap-4 lg:grid-cols-2">
                <article
                    v-for="association in associations"
                    :key="association.id"
                    class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm"
                >
                    <template v-if="editingId !== association.id">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">{{ association.name }}</h3>
                                <p class="mt-1 text-sm text-gray-600">
                                    {{ association.districts_count || 0 }} {{ tr('distrito(s)', 'district(s)') }}
                                </p>
                            </div>
                            <button type="button" class="text-sm text-blue-600 hover:underline" @click="startEdit(association)">
                                {{ tr('Editar', 'Edit') }}
                            </button>
                        </div>

                        <div class="mt-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Distritos creados', 'Created districts') }}</p>
                            <div v-if="association.districts?.length" class="mt-3 flex flex-wrap gap-2">
                                <span
                                    v-for="district in association.districts"
                                    :key="district.id"
                                    class="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-700"
                                >
                                    {{ district.name }}
                                </span>
                            </div>
                            <p v-else class="mt-3 text-sm italic text-gray-400">
                                {{ tr('Sin distritos registrados todavía.', 'No districts registered yet.') }}
                            </p>
                        </div>
                    </template>

                    <template v-else>
                        <div>
                            <InputLabel :value="tr('Nombre de la asociación', 'Association name')" />
                            <input
                                v-model="editForms[association.id].name"
                                type="text"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            />
                            <InputError class="mt-1" :message="editForms[association.id].errors.name" />
                        </div>

                        <div class="mt-4 flex justify-end gap-3">
                            <button
                                type="button"
                                class="text-sm font-medium text-blue-600 hover:underline"
                                :disabled="editForms[association.id].processing"
                                @click="saveAssociation(association)"
                            >
                                {{ tr('Guardar', 'Save') }}
                            </button>
                            <button type="button" class="text-sm text-gray-500 hover:underline" @click="cancelEdit(association)">
                                {{ tr('Cancelar', 'Cancel') }}
                            </button>
                        </div>
                    </template>
                </article>
            </section>
        </div>
    </PathfinderLayout>
</template>
