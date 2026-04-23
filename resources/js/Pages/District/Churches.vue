<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    district: { type: Object, required: true },
    association: { type: Object, default: null },
    churches: { type: Array, default: () => [] },
})

const { tr } = useLocale()
const showAddForm = ref(false)
const editingId = ref(null)
const search = ref('')

const addForm = useForm({
    church_name: '',
    address: '',
    ethnicity: '',
    phone_number: '',
    email: '',
    pastor_name: '',
    pastor_email: '',
})

const editForms = Object.fromEntries(
    props.churches.map((church) => [
        church.id,
        useForm({
            church_name: church.church_name ?? '',
            address: church.address ?? '',
            ethnicity: church.ethnicity ?? '',
            phone_number: church.phone_number ?? '',
            email: church.email ?? '',
            pastor_name: church.pastor_name ?? '',
            pastor_email: church.pastor_email ?? '',
        }),
    ])
)

const filteredChurches = computed(() => {
    const query = search.value.trim().toLowerCase()
    if (!query) return props.churches

    return props.churches.filter((church) =>
        [
            church.church_name,
            church.pastor_name,
            church.pastor_email,
            church.email,
            church.phone_number,
            church.address,
        ]
            .filter(Boolean)
            .some((value) => String(value).toLowerCase().includes(query))
    )
})

const submitAdd = () => {
    addForm.post(route('district.churches.store'), {
        preserveScroll: true,
        onSuccess: () => {
            addForm.reset()
            showAddForm.value = false
        },
    })
}

const startEdit = (church) => {
    editingId.value = church.id
}

const cancelEdit = (church) => {
    const form = editForms[church.id]
    form.church_name = church.church_name ?? ''
    form.address = church.address ?? ''
    form.ethnicity = church.ethnicity ?? ''
    form.phone_number = church.phone_number ?? ''
    form.email = church.email ?? ''
    form.pastor_name = church.pastor_name ?? ''
    form.pastor_email = church.pastor_email ?? ''
    form.clearErrors()
    editingId.value = null
}

const saveChurch = (church) => {
    editForms[church.id].patch(route('district.churches.update', church.id), {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null
        },
    })
}

const deleteChurch = (church) => {
    if (!confirm(tr(`¿Eliminar "${church.church_name}"?`, `Delete "${church.church_name}"?`))) return

    router.delete(route('district.churches.destroy', church.id), {
        preserveScroll: true,
    })
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Iglesias del distrito', 'District churches') }}</template>

        <div class="space-y-6">
            <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ district.name }}</h2>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ tr('Asociación', 'Association') }}: {{ association?.name || '—' }}
                        </p>
                        <p class="mt-2 text-sm text-gray-600">
                            {{ tr('El distrito administra las iglesias. Cada iglesia creada aquí se refleja automáticamente en la vista de distritos de la asociación.', 'The district manages churches. Every church created here is reflected automatically in the association district view.') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                        @click="showAddForm = !showAddForm"
                    >
                        {{ showAddForm ? tr('Cancelar', 'Cancel') : tr('+ Agregar iglesia', '+ Add church') }}
                    </button>
                </div>
            </section>

            <section v-if="showAddForm" class="rounded-2xl border border-blue-200 bg-blue-50 p-6 shadow-sm">
                <h3 class="mb-4 text-sm font-semibold text-blue-900">{{ tr('Nueva iglesia', 'New church') }}</h3>
                <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="submitAdd">
                    <div>
                        <InputLabel :value="tr('Nombre de la iglesia *', 'Church name *')" />
                        <input v-model="addForm.church_name" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                        <InputError class="mt-1" :message="addForm.errors.church_name" />
                    </div>
                    <div>
                        <InputLabel :value="tr('Pastor', 'Pastor')" />
                        <input v-model="addForm.pastor_name" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                        <InputError class="mt-1" :message="addForm.errors.pastor_name" />
                    </div>
                    <div>
                        <InputLabel :value="tr('Correo del pastor', 'Pastor email')" />
                        <input v-model="addForm.pastor_email" type="email" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                        <InputError class="mt-1" :message="addForm.errors.pastor_email" />
                    </div>
                    <div>
                        <InputLabel :value="tr('Correo', 'Email')" />
                        <input v-model="addForm.email" type="email" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                        <InputError class="mt-1" :message="addForm.errors.email" />
                    </div>
                    <div>
                        <InputLabel :value="tr('Teléfono', 'Phone')" />
                        <input v-model="addForm.phone_number" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                        <InputError class="mt-1" :message="addForm.errors.phone_number" />
                    </div>
                    <div>
                        <InputLabel :value="tr('Etnia', 'Ethnicity')" />
                        <input v-model="addForm.ethnicity" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                        <InputError class="mt-1" :message="addForm.errors.ethnicity" />
                    </div>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <InputLabel :value="tr('Dirección', 'Address')" />
                        <input v-model="addForm.address" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                        <InputError class="mt-1" :message="addForm.errors.address" />
                    </div>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <PrimaryButton type="submit" :disabled="addForm.processing">
                            {{ tr('Guardar iglesia', 'Save church') }}
                        </PrimaryButton>
                    </div>
                </form>
            </section>

            <section class="space-y-4">
                <input
                    v-model="search"
                    type="search"
                    :placeholder="tr('Buscar por nombre, pastor o contacto…', 'Search by name, pastor or contact…')"
                    class="block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                />

                <div v-if="!filteredChurches.length" class="rounded-2xl border border-dashed border-gray-200 bg-white p-8 text-center text-sm text-gray-400 shadow-sm">
                    {{ tr('No hay iglesias registradas en este distrito.', 'There are no churches registered in this district.') }}
                </div>

                <div v-else class="grid gap-4 lg:grid-cols-2">
                    <article
                        v-for="church in filteredChurches"
                        :key="church.id"
                        class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm"
                    >
                        <template v-if="editingId !== church.id">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <h3 class="text-base font-semibold text-gray-900">{{ church.church_name }}</h3>
                                    <p class="mt-1 text-sm text-gray-600">
                                        {{ church.pastor_name || tr('Sin pastor registrado', 'No pastor recorded') }}
                                        <span v-if="church.pastor_email"> · {{ church.pastor_email }}</span>
                                    </p>
                                </div>
                                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700">
                                    {{ church.clubs_count }} {{ tr('club(es)', 'club(s)') }}
                                </span>
                            </div>

                            <div class="mt-4 space-y-2 text-sm text-gray-600">
                                <p><span class="font-medium text-gray-900">{{ tr('Correo:', 'Email:') }}</span> {{ church.email || '—' }}</p>
                                <p><span class="font-medium text-gray-900">{{ tr('Teléfono:', 'Phone:') }}</span> {{ church.phone_number || '—' }}</p>
                                <p><span class="font-medium text-gray-900">{{ tr('Etnia:', 'Ethnicity:') }}</span> {{ church.ethnicity || '—' }}</p>
                                <p><span class="font-medium text-gray-900">{{ tr('Dirección:', 'Address:') }}</span> {{ church.address || '—' }}</p>
                            </div>

                            <div class="mt-4 flex flex-wrap justify-end gap-3">
                                <button type="button" class="text-sm text-blue-600 hover:underline" @click="startEdit(church)">
                                    {{ tr('Editar', 'Edit') }}
                                </button>
                                <button type="button" class="text-sm text-red-600 hover:underline" @click="deleteChurch(church)">
                                    {{ tr('Eliminar', 'Delete') }}
                                </button>
                            </div>
                        </template>

                        <template v-else>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <InputLabel :value="tr('Nombre de la iglesia *', 'Church name *')" />
                                    <input v-model="editForms[church.id].church_name" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                    <InputError class="mt-1" :message="editForms[church.id].errors.church_name" />
                                </div>
                                <div>
                                    <InputLabel :value="tr('Pastor', 'Pastor')" />
                                    <input v-model="editForms[church.id].pastor_name" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                    <InputError class="mt-1" :message="editForms[church.id].errors.pastor_name" />
                                </div>
                                <div>
                                    <InputLabel :value="tr('Correo del pastor', 'Pastor email')" />
                                    <input v-model="editForms[church.id].pastor_email" type="email" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                    <InputError class="mt-1" :message="editForms[church.id].errors.pastor_email" />
                                </div>
                                <div>
                                    <InputLabel :value="tr('Correo', 'Email')" />
                                    <input v-model="editForms[church.id].email" type="email" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                    <InputError class="mt-1" :message="editForms[church.id].errors.email" />
                                </div>
                                <div>
                                    <InputLabel :value="tr('Teléfono', 'Phone')" />
                                    <input v-model="editForms[church.id].phone_number" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                    <InputError class="mt-1" :message="editForms[church.id].errors.phone_number" />
                                </div>
                                <div>
                                    <InputLabel :value="tr('Etnia', 'Ethnicity')" />
                                    <input v-model="editForms[church.id].ethnicity" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                    <InputError class="mt-1" :message="editForms[church.id].errors.ethnicity" />
                                </div>
                                <div class="sm:col-span-2">
                                    <InputLabel :value="tr('Dirección', 'Address')" />
                                    <input v-model="editForms[church.id].address" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                    <InputError class="mt-1" :message="editForms[church.id].errors.address" />
                                </div>
                            </div>

                            <div class="mt-4 flex flex-wrap justify-end gap-3">
                                <button
                                    type="button"
                                    class="text-sm font-medium text-blue-600 hover:underline"
                                    :disabled="editForms[church.id].processing"
                                    @click="saveChurch(church)"
                                >
                                    {{ tr('Guardar', 'Save') }}
                                </button>
                                <button type="button" class="text-sm text-gray-500 hover:underline" @click="cancelEdit(church)">
                                    {{ tr('Cancelar', 'Cancel') }}
                                </button>
                            </div>
                        </template>
                    </article>
                </div>
            </section>
        </div>
    </PathfinderLayout>
</template>
