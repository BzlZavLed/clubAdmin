<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useForm, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    association: { type: Object, required: true },
    union: { type: Object, required: true },
    districts: { type: Array, default: () => [] },
    evaluators: { type: Array, default: () => [] },
})

const { tr } = useLocale()

const editingId = ref(null)
const showAddForm = ref(false)
const showEvaluatorForm = ref(false)

// Add district form
const addForm = useForm({ name: '', pastor_name: '', pastor_email: '' })

const submitAdd = () => {
    addForm.post(route('association.districts.store'), {
        preserveScroll: true,
        onSuccess: () => { addForm.reset(); showAddForm.value = false },
    })
}

// Per-district edit forms
const editForms = Object.fromEntries(
    props.districts.map((d) => [
        d.id,
        useForm({ pastor_name: d.pastor_name ?? '', pastor_email: d.pastor_email ?? '' }),
    ])
)

const startEdit = (id) => { editingId.value = id }

const cancelEdit = (district) => {
    editForms[district.id].pastor_name = district.pastor_name ?? ''
    editForms[district.id].pastor_email = district.pastor_email ?? ''
    editForms[district.id].clearErrors()
    editingId.value = null
}

const saveDistrict = (district) => {
    editForms[district.id].patch(route('association.districts.update', district.id), {
        preserveScroll: true,
        onSuccess: () => { editingId.value = null },
    })
}

const applyHint = (district) => {
    editForms[district.id].pastor_name = district.club_pastor_hint
    editingId.value = district.id
}

// Evaluator toggle — quick patch without entering edit mode
const toggleEvaluator = (district) => {
    router.patch(
        route('association.districts.update', district.id),
        { is_evaluator: !district.is_evaluator },
        { preserveScroll: true }
    )
}

// Standalone evaluator form
const evaluatorForm = useForm({ name: '', email: '', notes: '' })

const submitEvaluator = () => {
    evaluatorForm.post(route('association.evaluators.store'), {
        preserveScroll: true,
        onSuccess: () => { evaluatorForm.reset(); showEvaluatorForm.value = false },
    })
}

const removeEvaluator = (evaluator) => {
    if (!confirm(tr(`¿Eliminar evaluador "${evaluator.name}"?`, `Remove evaluator "${evaluator.name}"?`))) return
    router.delete(route('association.evaluators.destroy', evaluator.id), { preserveScroll: true })
}
</script>

<template>
    <PathfinderLayout>
        <template #title>
            {{ tr('Distritos y evaluadores', 'Districts & Evaluators') }}
        </template>

        <div class="space-y-8">

            <!-- ── Districts ── -->
            <section class="space-y-4">
                <div class="flex items-start justify-between rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ association.name }}</h2>
                        <p class="mt-1 text-sm text-gray-500">{{ tr('Unión', 'Union') }}: {{ union.name || '—' }}</p>
                        <p class="mt-2 text-sm text-gray-600">
                            {{ tr('Administra los distritos, asigna el pastor y marca cuáles actuarán como evaluadores al cierre del ciclo. Las iglesias se crean desde el portal distrital y aquí se reflejan por distrito.', 'Manage districts, assign the pastor, and mark which ones will act as evaluators at year close. Churches are created from the district portal and reflected here under each district.') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                        @click="showAddForm = !showAddForm"
                    >
                        {{ showAddForm ? tr('Cancelar', 'Cancel') : tr('+ Agregar distrito', '+ Add district') }}
                    </button>
                </div>

                <!-- Add district form -->
                <div v-if="showAddForm" class="rounded-2xl border border-blue-200 bg-blue-50 p-6 shadow-sm">
                    <h3 class="mb-4 text-sm font-semibold text-blue-900">{{ tr('Nuevo distrito', 'New district') }}</h3>
                    <form class="grid gap-4 sm:grid-cols-3" @submit.prevent="submitAdd">
                        <div>
                            <InputLabel :value="tr('Nombre del distrito', 'District name')" />
                            <input
                                v-model="addForm.name"
                                type="text"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            />
                            <InputError class="mt-1" :message="addForm.errors.name" />
                        </div>
                        <div>
                            <InputLabel :value="tr('Pastor', 'Pastor')" />
                            <input
                                v-model="addForm.pastor_name"
                                type="text"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            />
                            <InputError class="mt-1" :message="addForm.errors.pastor_name" />
                        </div>
                        <div>
                            <InputLabel :value="tr('Correo del pastor', 'Pastor email')" />
                            <input
                                v-model="addForm.pastor_email"
                                type="email"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            />
                            <InputError class="mt-1" :message="addForm.errors.pastor_email" />
                        </div>
                        <div class="sm:col-span-3">
                            <PrimaryButton type="submit" :disabled="addForm.processing">
                                {{ tr('Guardar distrito', 'Save district') }}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>

                <!-- Districts table -->
                <div v-if="!districts.length" class="rounded-2xl border border-dashed border-gray-200 p-8 text-center text-sm text-gray-400">
                    {{ tr('No hay distritos registrados. Agrega el primero.', 'No districts registered. Add the first one.') }}
                </div>

                <div v-else class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    {{ tr('Distrito', 'District') }}
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    {{ tr('Pastor', 'Pastor') }}
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    {{ tr('Correo', 'Email') }}
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    {{ tr('Iglesias', 'Churches') }}
                                </th>
                                <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    {{ tr('Evaluador', 'Evaluator') }}
                                </th>
                                <th class="px-6 py-3" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <template v-for="district in districts" :key="district.id">
                                <!-- View row -->
                                <tr v-if="editingId !== district.id" :class="district.club_pastor_hint ? 'bg-amber-50' : ''">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ district.name }}</td>
                                    <td class="px-6 py-4">
                                        <span v-if="district.pastor_name" class="text-sm text-gray-800">{{ district.pastor_name }}</span>
                                        <div v-else-if="district.club_pastor_hint" class="flex flex-wrap items-center gap-2">
                                            <span class="rounded-full border border-amber-300 bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                                                {{ tr('Sin guardar', 'Not saved') }}
                                            </span>
                                            <span class="text-sm text-amber-700">{{ district.club_pastor_hint }}</span>
                                            <button type="button" class="text-xs text-blue-600 hover:underline" @click="applyHint(district)">
                                                {{ tr('Guardar en distrito', 'Save to district') }}
                                            </button>
                                        </div>
                                        <span v-else class="text-sm italic text-gray-400">{{ tr('Sin asignar', 'Unassigned') }}</span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">{{ district.pastor_email || '—' }}</td>
                                    <td class="px-6 py-4">
                                        <div class="flex flex-col gap-2">
                                            <span class="text-sm font-medium text-gray-900">
                                                {{ district.churches_count || 0 }} {{ tr('iglesia(s)', 'church(es)') }}
                                            </span>
                                            <div v-if="district.churches?.length" class="flex flex-wrap gap-2">
                                                <span
                                                    v-for="church in district.churches"
                                                    :key="church.id"
                                                    class="rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-700"
                                                >
                                                    {{ church.church_name }}
                                                </span>
                                            </div>
                                            <span v-else class="text-sm italic text-gray-400">
                                                {{ tr('Sin iglesias registradas', 'No churches registered') }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <button
                                            type="button"
                                            :title="district.is_evaluator ? tr('Desmarcar evaluador', 'Remove evaluator flag') : tr('Marcar como evaluador', 'Mark as evaluator')"
                                            :class="[
                                                'inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none',
                                                district.is_evaluator ? 'bg-green-500' : 'bg-gray-200',
                                            ]"
                                            @click="toggleEvaluator(district)"
                                        >
                                            <span
                                                :class="[
                                                    'inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
                                                    district.is_evaluator ? 'translate-x-6' : 'translate-x-1',
                                                ]"
                                            />
                                        </button>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <button type="button" class="text-sm text-blue-600 hover:underline" @click="startEdit(district.id)">
                                            {{ tr('Editar', 'Edit') }}
                                        </button>
                                    </td>
                                </tr>

                                <!-- Edit row -->
                                <tr v-else class="bg-blue-50">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ district.name }}</td>
                                    <td class="px-6 py-4">
                                        <input
                                            v-model="editForms[district.id].pastor_name"
                                            type="text"
                                            :placeholder="tr('Nombre del pastor', 'Pastor name')"
                                            class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                        />
                                        <InputError class="mt-1" :message="editForms[district.id].errors.pastor_name" />
                                    </td>
                                    <td class="px-6 py-4">
                                        <input
                                            v-model="editForms[district.id].pastor_email"
                                            type="email"
                                            :placeholder="tr('Correo', 'Email')"
                                            class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                        />
                                        <InputError class="mt-1" :message="editForms[district.id].errors.pastor_email" />
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">
                                        {{ district.churches_count || 0 }} {{ tr('iglesia(s)', 'church(es)') }}
                                    </td>
                                    <td class="px-6 py-4 text-center text-sm text-gray-400 italic">—</td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex justify-end gap-3">
                                            <button
                                                type="button"
                                                class="text-sm font-medium text-blue-600 hover:underline"
                                                :disabled="editForms[district.id].processing"
                                                @click="saveDistrict(district)"
                                            >
                                                {{ tr('Guardar', 'Save') }}
                                            </button>
                                            <button type="button" class="text-sm text-gray-500 hover:underline" @click="cancelEdit(district)">
                                                {{ tr('Cancelar', 'Cancel') }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ── Additional Evaluators ── -->
            <section class="space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ tr('Evaluadores adicionales', 'Additional evaluators') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ tr('Personas que evaluarán sin estar ligadas a un distrito específico.', 'People who will evaluate without being tied to a specific district.') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                        @click="showEvaluatorForm = !showEvaluatorForm"
                    >
                        {{ showEvaluatorForm ? tr('Cancelar', 'Cancel') : tr('+ Agregar evaluador', '+ Add evaluator') }}
                    </button>
                </div>

                <!-- Add evaluator form -->
                <div v-if="showEvaluatorForm" class="rounded-2xl border border-purple-200 bg-purple-50 p-6 shadow-sm">
                    <h4 class="mb-4 text-sm font-semibold text-purple-900">{{ tr('Nuevo evaluador', 'New evaluator') }}</h4>
                    <form class="grid gap-4 sm:grid-cols-3" @submit.prevent="submitEvaluator">
                        <div>
                            <InputLabel :value="tr('Nombre', 'Name')" />
                            <input
                                v-model="evaluatorForm.name"
                                type="text"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500"
                            />
                            <InputError class="mt-1" :message="evaluatorForm.errors.name" />
                        </div>
                        <div>
                            <InputLabel :value="tr('Correo', 'Email')" />
                            <input
                                v-model="evaluatorForm.email"
                                type="email"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500"
                            />
                            <InputError class="mt-1" :message="evaluatorForm.errors.email" />
                        </div>
                        <div>
                            <InputLabel :value="tr('Notas', 'Notes')" />
                            <input
                                v-model="evaluatorForm.notes"
                                type="text"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500"
                            />
                            <InputError class="mt-1" :message="evaluatorForm.errors.notes" />
                        </div>
                        <div class="sm:col-span-3">
                            <PrimaryButton type="submit" :disabled="evaluatorForm.processing">
                                {{ tr('Guardar evaluador', 'Save evaluator') }}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>

                <!-- Evaluators table -->
                <div v-if="!evaluators.length" class="rounded-2xl border border-dashed border-gray-200 p-6 text-center text-sm text-gray-400">
                    {{ tr('Sin evaluadores adicionales aún.', 'No additional evaluators yet.') }}
                </div>

                <div v-else class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Nombre', 'Name') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Correo', 'Email') }}</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Notas', 'Notes') }}</th>
                                <th class="px-6 py-3" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="evaluator in evaluators" :key="evaluator.id">
                                <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ evaluator.name }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ evaluator.email || '—' }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ evaluator.notes || '—' }}</td>
                                <td class="px-6 py-4 text-right">
                                    <button type="button" class="text-sm text-red-600 hover:underline" @click="removeEvaluator(evaluator)">
                                        {{ tr('Eliminar', 'Remove') }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

        </div>
    </PathfinderLayout>
</template>
