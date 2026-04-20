<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useForm, router } from '@inertiajs/vue3'
import { ref, computed, watch } from 'vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    association: { type: Object, required: true },
    districts: { type: Array, default: () => [] },
    churches: { type: Array, default: () => [] },
})

const { tr } = useLocale()

const view = ref('district') // 'district' | 'church'
const showAddForm = ref(false)
const search = ref('')
const editingId = ref(null)

// ── Add form ──────────────────────────────────────────────────
const addForm = useForm({
    district_id: '',
    church_name: '',
    address: '',
    ethnicity: '',
    phone_number: '',
    email: '',
    pastor_name: '',
    pastor_email: '',
})

watch(() => addForm.district_id, (id) => {
    const district = props.districts.find((d) => d.id === Number(id) || d.id === id)
    if (district) {
        addForm.pastor_name = district.pastor_name ?? ''
        addForm.pastor_email = district.pastor_email ?? ''
    }
})

const submitAdd = () => {
    addForm.post(route('association.churches.store'), {
        preserveScroll: true,
        onSuccess: () => { addForm.reset(); showAddForm.value = false },
    })
}

// ── Edit forms (one per church) ───────────────────────────────
const editForms = Object.fromEntries(
    props.churches.map((c) => [
        c.id,
        useForm({
            district_id: c.district_id ?? '',
            church_name: c.church_name ?? '',
            address: c.address ?? '',
            ethnicity: c.ethnicity ?? '',
            phone_number: c.phone_number ?? '',
            email: c.email ?? '',
            pastor_name: c.pastor_name ?? '',
            pastor_email: c.pastor_email ?? '',
        }),
    ])
)

const startEdit = (church) => { editingId.value = church.id }

const cancelEdit = (church) => {
    const f = editForms[church.id]
    f.district_id = church.district_id ?? ''
    f.church_name = church.church_name ?? ''
    f.address = church.address ?? ''
    f.ethnicity = church.ethnicity ?? ''
    f.phone_number = church.phone_number ?? ''
    f.email = church.email ?? ''
    f.pastor_name = church.pastor_name ?? ''
    f.pastor_email = church.pastor_email ?? ''
    f.clearErrors()
    editingId.value = null
}

const saveChurch = (church) => {
    editForms[church.id].patch(route('association.churches.update', church.id), {
        preserveScroll: true,
        onSuccess: () => { editingId.value = null },
    })
}

const deleteChurch = (church) => {
    if (!confirm(tr(`¿Eliminar "${church.church_name}"?`, `Delete "${church.church_name}"?`))) return
    router.delete(route('association.churches.destroy', church.id), { preserveScroll: true })
}

// ── Helpers ───────────────────────────────────────────────────
const districtMap = computed(() =>
    Object.fromEntries(props.districts.map((d) => [d.id, d]))
)

const filteredChurches = computed(() => {
    const q = search.value.trim().toLowerCase()
    if (!q) return props.churches
    return props.churches.filter(
        (c) =>
            c.church_name?.toLowerCase().includes(q) ||
            districtMap.value[c.district_id]?.name?.toLowerCase().includes(q) ||
            c.pastor_name?.toLowerCase().includes(q)
    )
})
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Iglesias', 'Churches') }}</template>

        <div class="space-y-6">

            <!-- Header + add toggle -->
            <div class="flex items-start justify-between rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">{{ association.name }}</h2>
                    <p class="mt-2 text-sm text-gray-600">
                        {{ tr('Registra y administra las iglesias de cada distrito.', 'Register and manage the churches of each district.') }}
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

            <!-- Add church form -->
            <div v-if="showAddForm" class="rounded-2xl border border-blue-200 bg-blue-50 p-6 shadow-sm">
                <h3 class="mb-4 text-sm font-semibold text-blue-900">{{ tr('Nueva iglesia', 'New church') }}</h3>
                <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="submitAdd">
                    <div class="sm:col-span-2 lg:col-span-1">
                        <InputLabel :value="tr('Distrito *', 'District *')" />
                        <select
                            v-model="addForm.district_id"
                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                        >
                            <option value="">{{ tr('Selecciona distrito', 'Select district') }}</option>
                            <option v-for="d in districts" :key="d.id" :value="d.id">{{ d.name }}</option>
                        </select>
                        <InputError class="mt-1" :message="addForm.errors.district_id" />
                    </div>
                    <div>
                        <InputLabel :value="tr('Nombre de la iglesia *', 'Church name *')" />
                        <input v-model="addForm.church_name" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                        <InputError class="mt-1" :message="addForm.errors.church_name" />
                    </div>
                    <div>
                        <InputLabel :value="tr('Dirección', 'Address')" />
                        <input v-model="addForm.address" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                        <InputError class="mt-1" :message="addForm.errors.address" />
                    </div>
                    <div>
                        <InputLabel :value="tr('Etnia', 'Ethnicity')" />
                        <input v-model="addForm.ethnicity" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                        <InputError class="mt-1" :message="addForm.errors.ethnicity" />
                    </div>
                    <div>
                        <InputLabel :value="tr('Teléfono', 'Phone')" />
                        <input v-model="addForm.phone_number" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                        <InputError class="mt-1" :message="addForm.errors.phone_number" />
                    </div>
                    <div>
                        <InputLabel :value="tr('Correo', 'Email')" />
                        <input v-model="addForm.email" type="email" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                        <InputError class="mt-1" :message="addForm.errors.email" />
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
                    <div class="sm:col-span-2 lg:col-span-3">
                        <PrimaryButton type="submit" :disabled="addForm.processing">
                            {{ tr('Guardar iglesia', 'Save church') }}
                        </PrimaryButton>
                    </div>
                </form>
            </div>

            <!-- View tabs -->
            <div class="flex gap-1 rounded-xl border border-gray-200 bg-gray-100 p-1 w-fit">
                <button
                    type="button"
                    :class="['rounded-lg px-4 py-1.5 text-sm font-medium transition-colors', view === 'district' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700']"
                    @click="view = 'district'"
                >
                    {{ tr('Por distrito', 'By district') }}
                </button>
                <button
                    type="button"
                    :class="['rounded-lg px-4 py-1.5 text-sm font-medium transition-colors', view === 'church' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700']"
                    @click="view = 'church'"
                >
                    {{ tr('Por iglesia', 'By church') }}
                </button>
            </div>

            <!-- ── By district ── -->
            <div v-if="view === 'district'" class="space-y-4">
                <div v-if="!districts.length" class="rounded-2xl border border-dashed border-gray-200 p-8 text-center text-sm text-gray-400">
                    {{ tr('No hay distritos registrados aún.', 'No districts registered yet.') }}
                </div>
                <article
                    v-for="district in districts"
                    :key="district.id"
                    class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm"
                >
                    <!-- District header -->
                    <div class="flex items-center justify-between border-b border-gray-100 bg-gray-50 px-6 py-4">
                        <div>
                            <h3 class="font-semibold text-gray-900">{{ district.name }}</h3>
                            <p v-if="district.pastor_name" class="mt-0.5 text-xs text-gray-500">
                                {{ tr('Pastor', 'Pastor') }}: {{ district.pastor_name }}
                                <span v-if="district.pastor_email"> · {{ district.pastor_email }}</span>
                            </p>
                            <p v-else class="mt-0.5 text-xs italic text-gray-400">{{ tr('Sin pastor asignado', 'No pastor assigned') }}</p>
                        </div>
                        <span
                            v-if="district.is_evaluator"
                            class="rounded-full border border-green-300 bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800"
                        >
                            {{ tr('Evaluador', 'Evaluator') }}
                        </span>
                    </div>

                    <!-- Churches list -->
                    <div v-if="district.churches.length" class="divide-y divide-gray-100">
                        <div
                            v-for="church in district.churches"
                            :key="church.id"
                            class="flex items-start justify-between px-6 py-3"
                        >
                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ church.church_name }}</p>
                                <p v-if="church.pastor_name" class="text-xs text-gray-500">{{ church.pastor_name }}<span v-if="church.pastor_email"> · {{ church.pastor_email }}</span></p>
                                <p v-if="church.email || church.phone_number" class="text-xs text-gray-400">
                                    {{ [church.email, church.phone_number].filter(Boolean).join(' · ') }}
                                </p>
                            </div>
                        </div>
                    </div>
                    <div v-else class="px-6 py-4 text-sm italic text-gray-400">
                        {{ tr('Sin iglesias registradas en este distrito.', 'No churches registered in this district.') }}
                    </div>
                </article>
            </div>

            <!-- ── By church ── -->
            <div v-else class="space-y-4">
                <input
                    v-model="search"
                    type="search"
                    :placeholder="tr('Buscar por nombre, distrito o pastor…', 'Search by name, district or pastor…')"
                    class="block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                />

                <div v-if="!filteredChurches.length" class="rounded-2xl border border-dashed border-gray-200 p-8 text-center text-sm text-gray-400">
                    {{ tr('Sin resultados.', 'No results.') }}
                </div>

                <div v-else class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Iglesia', 'Church') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Distrito', 'District') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Pastor', 'Pastor') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Contacto', 'Contact') }}</th>
                                <th class="px-6 py-3" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <template v-for="church in filteredChurches" :key="church.id">
                                <!-- View row -->
                                <tr v-if="editingId !== church.id">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ church.church_name }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-700">{{ districtMap[church.district_id]?.name || '—' }}</td>
                                    <td class="px-6 py-4">
                                        <p class="text-sm text-gray-700">{{ church.pastor_name || '—' }}</p>
                                        <p v-if="church.pastor_email" class="text-xs text-gray-400">{{ church.pastor_email }}</p>
                                    </td>
                                    <td class="px-6 py-4 text-xs text-gray-500">
                                        {{ [church.email, church.phone_number].filter(Boolean).join(' · ') || '—' }}
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex justify-end gap-3">
                                            <button type="button" class="text-sm text-blue-600 hover:underline" @click="startEdit(church)">{{ tr('Editar', 'Edit') }}</button>
                                            <button type="button" class="text-sm text-red-600 hover:underline" @click="deleteChurch(church)">{{ tr('Eliminar', 'Delete') }}</button>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Edit row -->
                                <tr v-else class="bg-blue-50 align-top">
                                    <td class="px-4 py-3">
                                        <input v-model="editForms[church.id].church_name" type="text" :placeholder="tr('Nombre', 'Name')" class="block w-full rounded-md border-gray-300 text-sm" />
                                        <InputError class="mt-1" :message="editForms[church.id].errors.church_name" />
                                    </td>
                                    <td class="px-4 py-3">
                                        <select v-model="editForms[church.id].district_id" class="block w-full rounded-md border-gray-300 text-sm">
                                            <option value="">—</option>
                                            <option v-for="d in districts" :key="d.id" :value="d.id">{{ d.name }}</option>
                                        </select>
                                        <InputError class="mt-1" :message="editForms[church.id].errors.district_id" />
                                    </td>
                                    <td class="px-4 py-3 space-y-2">
                                        <input v-model="editForms[church.id].pastor_name" type="text" :placeholder="tr('Pastor', 'Pastor')" class="block w-full rounded-md border-gray-300 text-sm" />
                                        <input v-model="editForms[church.id].pastor_email" type="email" :placeholder="tr('Correo pastor', 'Pastor email')" class="block w-full rounded-md border-gray-300 text-sm" />
                                    </td>
                                    <td class="px-4 py-3 space-y-2">
                                        <input v-model="editForms[church.id].email" type="email" :placeholder="tr('Correo', 'Email')" class="block w-full rounded-md border-gray-300 text-sm" />
                                        <input v-model="editForms[church.id].phone_number" type="text" :placeholder="tr('Teléfono', 'Phone')" class="block w-full rounded-md border-gray-300 text-sm" />
                                    </td>
                                    <td class="px-4 py-3 text-right align-middle">
                                        <div class="flex justify-end gap-3">
                                            <button type="button" class="text-sm font-medium text-blue-600 hover:underline" :disabled="editForms[church.id].processing" @click="saveChurch(church)">{{ tr('Guardar', 'Save') }}</button>
                                            <button type="button" class="text-sm text-gray-500 hover:underline" @click="cancelEdit(church)">{{ tr('Cancelar', 'Cancel') }}</button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </PathfinderLayout>
</template>
