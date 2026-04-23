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
    church_options: { type: Array, default: () => [] },
    evaluators: { type: Array, default: () => [] },
})

const { tr } = useLocale()

const editingId = ref(null)
const showAddForm = ref(false)
const showEvaluatorForm = ref(false)

const addForm = useForm({
    name: '',
    pastor_name: '',
    pastor_email: '',
    incoming_church_ids: [],
})

const editForms = Object.fromEntries(
    props.districts.map((district) => [
        district.id,
        useForm({
            name: district.name ?? '',
            pastor_name: district.pastor_name ?? '',
            pastor_email: district.pastor_email ?? '',
            incoming_church_ids: [],
        }),
    ])
)

const submitAdd = () => {
    addForm.post(route('association.districts.store'), {
        preserveScroll: true,
        onSuccess: () => {
            addForm.reset()
            showAddForm.value = false
        },
    })
}

const startEdit = (district) => {
    const form = editForms[district.id]
    form.name = district.name ?? ''
    form.pastor_name = district.pastor_name ?? ''
    form.pastor_email = district.pastor_email ?? ''
    form.incoming_church_ids = []
    form.clearErrors()
    editingId.value = district.id
}

const cancelEdit = (district) => {
    const form = editForms[district.id]
    form.name = district.name ?? ''
    form.pastor_name = district.pastor_name ?? ''
    form.pastor_email = district.pastor_email ?? ''
    form.incoming_church_ids = []
    form.clearErrors()
    editingId.value = null
}

const saveDistrict = (district) => {
    editForms[district.id].patch(route('association.districts.update', district.id), {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null
        },
    })
}

const toggleEvaluator = (district) => {
    router.patch(
        route('association.districts.update', district.id),
        { is_evaluator: !district.is_evaluator },
        { preserveScroll: true }
    )
}

const toggleChurchSelection = (form, churchId) => {
    const id = Number(churchId)
    const selected = new Set((form.incoming_church_ids ?? []).map((value) => Number(value)))

    if (selected.has(id)) {
        selected.delete(id)
    } else {
        selected.add(id)
    }

    form.incoming_church_ids = Array.from(selected)
}

const allChurchOptions = () => props.church_options

const transferOptionsForDistrict = (district) =>
    props.church_options.filter((church) => Number(church.district_id) !== Number(district.id))

const evaluatorForm = useForm({ name: '', email: '', notes: '' })

const submitEvaluator = () => {
    evaluatorForm.post(route('association.evaluators.store'), {
        preserveScroll: true,
        onSuccess: () => {
            evaluatorForm.reset()
            showEvaluatorForm.value = false
        },
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
            <section class="space-y-4">
                <div class="flex items-start justify-between rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ association.name }}</h2>
                        <p class="mt-1 text-sm text-gray-500">{{ tr('Unión', 'Union') }}: {{ union.name || '—' }}</p>
                        <p class="mt-2 text-sm text-gray-600">
                            {{ tr('La asociación administra distritos, cambia pastores distritales y puede mover iglesias entre distritos cuando se divide o reordena la estructura.', 'The association manages districts, reassigns district pastors, and can move churches between districts when the structure is split or reshuffled.') }}
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

                <div v-if="showAddForm" class="rounded-2xl border border-blue-200 bg-blue-50 p-6 shadow-sm">
                    <h3 class="mb-4 text-sm font-semibold text-blue-900">{{ tr('Nuevo distrito', 'New district') }}</h3>
                    <form class="grid gap-4 lg:grid-cols-3" @submit.prevent="submitAdd">
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
                            <InputLabel :value="tr('Pastor distrital', 'District pastor')" />
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

                        <div class="lg:col-span-3">
                            <div class="rounded-xl border border-blue-100 bg-white p-4">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <h4 class="text-sm font-semibold text-gray-900">{{ tr('Mover iglesias a este distrito', 'Move churches into this district') }}</h4>
                                        <p class="mt-1 text-xs text-gray-500">
                                            {{ tr('Úsalo al dividir distritos o reacomodar iglesias existentes.', 'Use this when splitting districts or reshuffling existing churches.') }}
                                        </p>
                                    </div>
                                    <span class="rounded-full bg-blue-100 px-3 py-1 text-xs font-medium text-blue-700">
                                        {{ addForm.incoming_church_ids.length }} {{ tr('seleccionadas', 'selected') }}
                                    </span>
                                </div>

                                <div v-if="allChurchOptions().length" class="mt-4 grid gap-3 md:grid-cols-2">
                                    <label
                                        v-for="church in allChurchOptions()"
                                        :key="church.id"
                                        class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3"
                                    >
                                        <input
                                            :checked="addForm.incoming_church_ids.includes(church.id)"
                                            type="checkbox"
                                            class="mt-1 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                            @change="toggleChurchSelection(addForm, church.id)"
                                        />
                                        <div class="min-w-0">
                                            <p class="text-sm font-medium text-gray-900">{{ church.church_name }}</p>
                                            <p class="text-xs text-gray-500">{{ church.district_name || '—' }}</p>
                                            <p class="text-xs text-gray-400">{{ church.clubs_count || 0 }} {{ tr('club(es)', 'club(s)') }}</p>
                                        </div>
                                    </label>
                                </div>

                                <p v-else class="mt-4 text-sm text-gray-500">
                                    {{ tr('No hay iglesias disponibles para mover todavía.', 'No churches are available to move yet.') }}
                                </p>

                                <InputError class="mt-3" :message="addForm.errors.incoming_church_ids" />
                            </div>
                        </div>

                        <div class="lg:col-span-3">
                            <PrimaryButton type="submit" :disabled="addForm.processing">
                                {{ tr('Guardar distrito', 'Save district') }}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>

                <div v-if="!districts.length" class="rounded-2xl border border-dashed border-gray-200 p-8 text-center text-sm text-gray-400">
                    {{ tr('No hay distritos registrados. Agrega el primero.', 'No districts registered. Add the first one.') }}
                </div>

                <div v-else class="space-y-4">
                    <article
                        v-for="district in districts"
                        :key="district.id"
                        class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm"
                    >
                        <template v-if="editingId !== district.id">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <h3 class="text-base font-semibold text-gray-900">{{ district.name }}</h3>
                                    <p class="mt-1 text-sm text-gray-600">
                                        {{ district.pastor_name || tr('Sin pastor distrital asignado', 'No district pastor assigned') }}
                                        <span v-if="district.pastor_email"> · {{ district.pastor_email }}</span>
                                    </p>
                                </div>

                                <div class="flex flex-wrap items-center gap-3">
                                    <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700">
                                        {{ district.churches_count || 0 }} {{ tr('iglesia(s)', 'church(es)') }}
                                    </span>
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
                                    <button type="button" class="text-sm text-blue-600 hover:underline" @click="startEdit(district)">
                                        {{ tr('Editar', 'Edit') }}
                                    </button>
                                </div>
                            </div>

                            <div class="mt-4">
                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    {{ tr('Iglesias en este distrito', 'Churches in this district') }}
                                </p>
                                <div v-if="district.churches?.length" class="flex flex-wrap gap-2">
                                    <span
                                        v-for="church in district.churches"
                                        :key="church.id"
                                        class="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-700"
                                    >
                                        {{ church.church_name }} · {{ church.clubs_count || 0 }} {{ tr('club(es)', 'club(s)') }}
                                    </span>
                                </div>
                                <p v-else class="text-sm italic text-gray-400">
                                    {{ tr('Sin iglesias registradas', 'No churches registered') }}
                                </p>
                            </div>
                        </template>

                        <template v-else>
                            <form class="grid gap-4 lg:grid-cols-3" @submit.prevent="saveDistrict(district)">
                                <div>
                                    <InputLabel :value="tr('Nombre del distrito', 'District name')" />
                                    <input
                                        v-model="editForms[district.id].name"
                                        type="text"
                                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                    />
                                    <InputError class="mt-1" :message="editForms[district.id].errors.name" />
                                </div>
                                <div>
                                    <InputLabel :value="tr('Pastor distrital', 'District pastor')" />
                                    <input
                                        v-model="editForms[district.id].pastor_name"
                                        type="text"
                                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                    />
                                    <InputError class="mt-1" :message="editForms[district.id].errors.pastor_name" />
                                </div>
                                <div>
                                    <InputLabel :value="tr('Correo del pastor', 'Pastor email')" />
                                    <input
                                        v-model="editForms[district.id].pastor_email"
                                        type="email"
                                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                    />
                                    <InputError class="mt-1" :message="editForms[district.id].errors.pastor_email" />
                                </div>

                                <div class="lg:col-span-3 rounded-xl border border-blue-100 bg-blue-50 p-4">
                                    <div class="flex items-center justify-between gap-3">
                                        <div>
                                            <h4 class="text-sm font-semibold text-gray-900">{{ tr('Traer iglesias desde otros distritos', 'Bring churches from other districts') }}</h4>
                                            <p class="mt-1 text-xs text-gray-500">
                                                {{ tr('Selecciona iglesias para moverlas a este distrito. Las existentes aquí no se tocan.', 'Select churches to move them into this district. Existing churches here stay as they are.') }}
                                            </p>
                                        </div>
                                        <span class="rounded-full bg-white px-3 py-1 text-xs font-medium text-blue-700">
                                            {{ editForms[district.id].incoming_church_ids.length }} {{ tr('seleccionadas', 'selected') }}
                                        </span>
                                    </div>

                                    <div v-if="transferOptionsForDistrict(district).length" class="mt-4 grid gap-3 md:grid-cols-2">
                                        <label
                                            v-for="church in transferOptionsForDistrict(district)"
                                            :key="church.id"
                                            class="flex cursor-pointer items-start gap-3 rounded-xl border border-blue-100 bg-white px-4 py-3"
                                        >
                                            <input
                                                :checked="editForms[district.id].incoming_church_ids.includes(church.id)"
                                                type="checkbox"
                                                class="mt-1 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                                @change="toggleChurchSelection(editForms[district.id], church.id)"
                                            />
                                            <div class="min-w-0">
                                                <p class="text-sm font-medium text-gray-900">{{ church.church_name }}</p>
                                                <p class="text-xs text-gray-500">{{ church.district_name || '—' }}</p>
                                                <p class="text-xs text-gray-400">{{ church.clubs_count || 0 }} {{ tr('club(es)', 'club(s)') }}</p>
                                            </div>
                                        </label>
                                    </div>

                                    <p v-else class="mt-4 text-sm text-gray-500">
                                        {{ tr('No hay otras iglesias disponibles para mover.', 'There are no other churches available to move.') }}
                                    </p>

                                    <InputError class="mt-3" :message="editForms[district.id].errors.incoming_church_ids" />
                                </div>

                                <div class="lg:col-span-3 flex justify-end gap-3">
                                    <button
                                        type="submit"
                                        class="text-sm font-medium text-blue-600 hover:underline"
                                        :disabled="editForms[district.id].processing"
                                    >
                                        {{ tr('Guardar', 'Save') }}
                                    </button>
                                    <button type="button" class="text-sm text-gray-500 hover:underline" @click="cancelEdit(district)">
                                        {{ tr('Cancelar', 'Cancel') }}
                                    </button>
                                </div>
                            </form>
                        </template>
                    </article>
                </div>
            </section>

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
