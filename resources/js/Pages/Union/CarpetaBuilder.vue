<script setup>
import axios from 'axios'
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import Modal from '@/Components/Modal.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useLocale } from '@/Composables/useLocale'
import { useGeneral } from '@/Composables/useGeneral'

const props = defineProps({
    union: { type: Object, required: true },
    years: { type: Array, default: () => [] },
    clubCatalogs: { type: Array, default: () => [] },
})

const { tr } = useLocale()
const { showToast, showError } = useGeneral()

const systemForm = useForm({
    evaluation_system: props.union?.evaluation_system || 'honors',
})

const yearForm = useForm({
    year: new Date().getFullYear(),
})

const requirementModalOpen = ref(false)
const activeYear = ref(null)
const activeClubCatalog = ref(null)
const activeClassCatalog = ref(null)
const savingRequirement = ref(false)
const evidenceTypeOptions = [
    { value: 'photo', label: 'Photo' },
    { value: 'file', label: 'File' },
    { value: 'text', label: 'Text' },
    { value: 'video_link', label: 'Video link' },
    { value: 'external_link', label: 'External link' },
    { value: 'physical_only', label: 'Physical only' },
]

const requirementForm = useForm({
    title: '',
    description: '',
    requirement_type: 'other',
    validation_mode: 'electronic',
    allowed_evidence_types: [],
    evidence_instructions: '',
    sort_order: '',
})

const isCarpetas = computed(() => systemForm.evaluation_system === 'carpetas')
const sortedYears = computed(() =>
    [...(props.years || [])].sort((a, b) => Number(b.year) - Number(a.year) || Number(b.id) - Number(a.id))
)

const resetRequirementForm = () => {
    requirementForm.reset()
    requirementForm.title = ''
    requirementForm.description = ''
    requirementForm.requirement_type = 'other'
    requirementForm.validation_mode = 'electronic'
    requirementForm.allowed_evidence_types = []
    requirementForm.evidence_instructions = ''
    requirementForm.sort_order = ''
}

const firstCatalogContext = computed(() => {
    const firstClub = props.clubCatalogs?.[0] || null
    const firstClass = firstClub?.class_catalogs?.[0] || null
    if (!firstClub || !firstClass) return null
    return {
        clubCatalog: firstClub,
        classCatalog: firstClass,
    }
})

const getRequirementsForClass = (yearRow, clubCatalog, classCatalog) => {
    return (yearRow?.requirements || []).filter((requirement) =>
        String(requirement.club_type || '') === String(clubCatalog?.name || '') &&
        String(requirement.class_name || '') === String(classCatalog?.name || '')
    )
}

const openRequirementModal = (yearRow, clubCatalog, classCatalog) => {
    activeYear.value = yearRow
    activeClubCatalog.value = clubCatalog
    activeClassCatalog.value = classCatalog
    resetRequirementForm()
    requirementModalOpen.value = true
}

const closeRequirementModal = () => {
    requirementModalOpen.value = false
    activeYear.value = null
    activeClubCatalog.value = null
    activeClassCatalog.value = null
    resetRequirementForm()
}

const refreshBuilder = () => {
    router.reload({ only: ['union', 'years'] })
}

const submitSystem = () => {
    systemForm.put(route('union.carpeta-builder.evaluation-system.update'), {
        preserveScroll: true,
        onSuccess: () => {
            showToast(tr('Sistema de evaluación actualizado.', 'Evaluation system updated.'), 'success')
            refreshBuilder()
        },
        onError: () => {
            const firstError = Object.values(systemForm.errors || {})[0]
            if (firstError) showToast(firstError, 'error')
        },
    })
}

const submitYear = async () => {
    try {
        yearForm.clearErrors()
        const { data } = await axios.post(route('union.carpeta-builder.years.store'), {
            year: Number(yearForm.year),
        })
        showToast(data?.message || tr('Ciclo anual creado.', 'Yearly cycle created.'), 'success')
        yearForm.reset()
        yearForm.year = new Date().getFullYear()
        if (data?.year && firstCatalogContext.value) {
            openRequirementModal(data.year, firstCatalogContext.value.clubCatalog, firstCatalogContext.value.classCatalog)
        }
        refreshBuilder()
    } catch (error) {
        if (error?.response?.status === 422 && error?.response?.data?.errors) {
            yearForm.setError(error.response.data.errors)
        }
        showError(error, tr('No se pudo crear el ciclo anual.', 'Could not create the yearly cycle.'))
    }
}

const submitRequirement = async () => {
    if (!activeYear.value?.id || !activeClubCatalog.value?.id || !activeClassCatalog.value?.id) return

    savingRequirement.value = true
    requirementForm.clearErrors()
    try {
        const payload = {
            ...requirementForm.data(),
            club_catalog_id: Number(activeClubCatalog.value.id),
            class_catalog_id: Number(activeClassCatalog.value.id),
            sort_order: requirementForm.sort_order ? Number(requirementForm.sort_order) : null,
            allowed_evidence_types: Array.isArray(requirementForm.allowed_evidence_types)
                ? requirementForm.allowed_evidence_types
                : [],
        }

        const { data } = await axios.post(
            route('union.carpeta-builder.requirements.store', activeYear.value.id),
            payload
        )

        showToast(data?.message || tr('Requisito creado.', 'Requirement created.'), 'success')
        refreshBuilder()
        resetRequirementForm()
    } catch (error) {
        if (error?.response?.status === 422 && error?.response?.data?.errors) {
            requirementForm.setError(error.response.data.errors)
        }
        showError(error, tr('No se pudo crear el requisito.', 'Could not create the requirement.'))
    } finally {
        savingRequirement.value = false
    }
}

const publishYear = (yearRow) => {
    if (!confirm(tr(`Publicar ciclo ${yearRow.year}?`, `Publish cycle ${yearRow.year}?`))) return
    router.put(route('union.carpeta-builder.years.publish', yearRow.id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            showToast(tr('Ciclo anual publicado.', 'Yearly cycle published.'), 'success')
            refreshBuilder()
        },
    })
}

const archiveYear = (yearRow) => {
    if (!confirm(tr(`Archivar ciclo ${yearRow.year}?`, `Archive cycle ${yearRow.year}?`))) return
    router.put(route('union.carpeta-builder.years.archive', yearRow.id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            showToast(tr('Ciclo anual archivado.', 'Yearly cycle archived.'), 'success')
            refreshBuilder()
        },
    })
}

const formatDateTime = (value) => {
    if (!value) return '—'
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return value
    return date.toLocaleString()
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Builder de carpetas', 'Carpeta builder') }}</template>

        <div class="max-w-5xl mx-auto space-y-6">
            <section class="rounded-lg border bg-white p-6 shadow-sm space-y-4">
                <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ union?.name || tr('Unión', 'Union') }}</h2>
                        <p class="text-sm text-gray-600">
                            {{ tr('Define el sistema de evaluación que la unión aplicará a sus clubes y controla los ciclos anuales de carpetas.', 'Define the evaluation system this union applies to its clubs and manage annual carpeta cycles.') }}
                        </p>
                    </div>

                    <a :href="route('union.dashboard')" class="inline-flex items-center rounded border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        {{ tr('Volver al panel', 'Back to dashboard') }}
                    </a>
                </div>

                <form @submit.prevent="submitSystem" class="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                    <div>
                        <InputLabel for="evaluation_system" :value="tr('Sistema de evaluación', 'Evaluation system')" />
                        <select id="evaluation_system" v-model="systemForm.evaluation_system" class="mt-1 block w-full rounded-md border-gray-300" required>
                            <option value="honors">{{ tr('Honores / requisitos', 'Honors / requirements') }}</option>
                            <option value="carpetas">{{ tr('Carpetas', 'Carpetas') }}</option>
                        </select>
                        <InputError class="mt-2" :message="systemForm.errors.evaluation_system" />
                    </div>

                    <PrimaryButton :disabled="systemForm.processing" class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-md">
                        {{ tr('Guardar sistema', 'Save system') }}
                    </PrimaryButton>
                </form>

                <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <div class="font-medium">{{ tr('Regla operativa', 'Operating rule') }}</div>
                    <div class="mt-1">
                        {{ tr('Cada ciclo de carpeta se define una vez por año. Al publicarlo, la definición queda congelada para no romper la planificación de los clubes.', 'Each carpeta cycle is defined once per year. Once published, the definition is frozen so club planning is not broken.') }}
                    </div>
                </div>
            </section>

            <section v-if="!isCarpetas" class="rounded-lg border bg-white p-6 shadow-sm">
                <h3 class="text-base font-semibold text-gray-900">{{ tr('Workflow en modo honores', 'Workflow in honors mode') }}</h3>
                <p class="mt-2 text-sm text-gray-600">
                    {{ tr('Mientras la unión esté configurada en honores, el builder anual de carpetas permanece inactivo. Cambia el sistema a carpetas para empezar a definir ciclos anuales.', 'While the union is configured for honors, the annual carpeta builder stays inactive. Switch the system to carpetas to start defining annual cycles.') }}
                </p>
            </section>

            <template v-else>
                <section class="rounded-lg border bg-white p-6 shadow-sm space-y-4">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ tr('Crear ciclo anual', 'Create annual cycle') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ tr('Al crear el borrador anual se abrirá de inmediato el modal para comenzar a capturar requisitos.', 'Creating the yearly draft immediately opens the modal so you can start entering requirements.') }}
                        </p>
                    </div>

                    <form @submit.prevent="submitYear" class="grid gap-4 md:grid-cols-[220px_auto] md:items-end">
                        <div>
                            <InputLabel for="year" :value="tr('Año', 'Year')" />
                            <input id="year" v-model="yearForm.year" type="number" min="2000" max="2100" class="mt-1 block w-full rounded-md border-gray-300" required />
                            <InputError class="mt-2" :message="yearForm.errors.year" />
                        </div>

                        <PrimaryButton :disabled="yearForm.processing" class="justify-self-start whitespace-normal text-center leading-tight bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-md">
                            {{ tr('Crear borrador anual', 'Create yearly draft') }}
                        </PrimaryButton>
                    </form>
                </section>

                <section class="rounded-lg border bg-white p-6 shadow-sm">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ tr('Ciclos de carpetas', 'Carpeta cycles') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ tr('Publicado significa congelado para el año activo. Archivado conserva el historial.', 'Published means frozen for the active year. Archived keeps the historical record.') }}
                        </p>
                    </div>

                    <div class="mt-4 space-y-4">
                        <div v-if="!sortedYears.length" class="text-sm text-gray-500">
                            {{ tr('Todavía no hay ciclos anuales creados.', 'There are no yearly cycles yet.') }}
                        </div>

                        <article v-for="yearRow in sortedYears" :key="yearRow.id" class="rounded-lg border border-gray-200 p-4">
                            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                <div>
                                    <div class="text-base font-semibold text-gray-900">{{ yearRow.year }}</div>
                                    <div class="mt-1 text-sm text-gray-600">
                                        {{ tr('Estado', 'Status') }}: {{ yearRow.status }} |
                                        {{ tr('Publicado', 'Published') }}: {{ formatDateTime(yearRow.published_at) }}
                                    </div>
                                </div>

                                <div class="flex flex-wrap justify-end gap-3 text-sm">
                                    <button v-if="yearRow.status === 'draft'" type="button" class="text-blue-600 hover:underline" @click="publishYear(yearRow)">
                                        {{ tr('Publicar', 'Publish') }}
                                    </button>
                                    <button v-if="yearRow.status !== 'archived'" type="button" class="text-amber-600 hover:underline" @click="archiveYear(yearRow)">
                                        {{ tr('Archivar', 'Archive') }}
                                    </button>
                                </div>
                            </div>

                            <div class="mt-4">
                                <div v-if="!props.clubCatalogs.length" class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                    {{ tr('Primero define el catálogo de clubes y clases de la unión para poder segmentar correctamente los requisitos.', 'Define the union club and class catalog first so requirements can be segmented correctly.') }}
                                </div>

                                <div v-else class="mt-3 space-y-5">
                                    <section v-for="clubCatalog in props.clubCatalogs" :key="`${yearRow.id}-club-${clubCatalog.id}`" class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                                        <div class="flex items-center justify-between gap-3">
                                            <div>
                                                <h4 class="text-sm font-semibold uppercase tracking-wide text-gray-700">{{ clubCatalog.name }}</h4>
                                                <p class="mt-1 text-xs text-gray-500">
                                                    {{ tr('Clases de referencia', 'Reference classes') }}: {{ (clubCatalog.class_catalogs || []).length }}
                                                </p>
                                            </div>
                                        </div>

                                        <div v-if="!(clubCatalog.class_catalogs || []).length" class="mt-3 text-sm text-gray-500">
                                            {{ tr('Este tipo de club todavía no tiene clases en el catálogo.', 'This club type does not have classes in the catalog yet.') }}
                                        </div>

                                        <div v-else class="mt-4 grid gap-4 lg:grid-cols-2">
                                            <article
                                                v-for="classCatalog in (clubCatalog.class_catalogs || [])"
                                                :key="`${yearRow.id}-class-${classCatalog.id}`"
                                                class="rounded-lg border border-gray-200 bg-white p-4"
                                            >
                                                <div class="flex items-start justify-between gap-3">
                                                    <div>
                                                        <h5 class="text-sm font-semibold text-gray-900">{{ classCatalog.name }}</h5>
                                                        <p class="mt-1 text-xs text-gray-500">
                                                            {{ tr('Requisitos', 'Requirements') }}:
                                                            {{ getRequirementsForClass(yearRow, clubCatalog, classCatalog).length }}
                                                        </p>
                                                    </div>

                                                    <button
                                                        type="button"
                                                        class="text-sm text-blue-600 hover:underline"
                                                        @click="openRequirementModal(yearRow, clubCatalog, classCatalog)"
                                                    >
                                                        {{ tr('Agregar requisito', 'Add requirement') }}
                                                    </button>
                                                </div>

                                                <div v-if="!getRequirementsForClass(yearRow, clubCatalog, classCatalog).length" class="mt-3 text-sm text-gray-500">
                                                    {{ tr('Todavía no hay requisitos para esta clase.', 'There are no requirements for this class yet.') }}
                                                </div>

                                                <div v-else class="mt-3 overflow-x-auto">
                                                    <table class="min-w-full text-sm">
                                                        <thead>
                                                            <tr class="border-b text-left text-gray-500">
                                                                <th class="pb-2 pr-4 font-medium">#</th>
                                                                <th class="pb-2 pr-4 font-medium">{{ tr('Título', 'Title') }}</th>
                                                                <th class="pb-2 pr-4 font-medium">{{ tr('Tipo', 'Type') }}</th>
                                                                <th class="pb-2 pr-4 font-medium">{{ tr('Validación', 'Validation') }}</th>
                                                                <th class="pb-2 font-medium">{{ tr('Evidencias', 'Evidence') }}</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <tr
                                                                v-for="requirement in getRequirementsForClass(yearRow, clubCatalog, classCatalog)"
                                                                :key="requirement.id"
                                                                class="border-b last:border-b-0"
                                                            >
                                                                <td class="py-2 pr-4 text-gray-700">{{ requirement.sort_order }}</td>
                                                                <td class="py-2 pr-4">
                                                                    <div class="font-medium text-gray-900">{{ requirement.title }}</div>
                                                                    <div v-if="requirement.description" class="text-xs text-gray-500">{{ requirement.description }}</div>
                                                                </td>
                                                                <td class="py-2 pr-4 text-gray-700">{{ requirement.requirement_type }}</td>
                                                                <td class="py-2 pr-4 text-gray-700">{{ requirement.validation_mode }}</td>
                                                                <td class="py-2 text-gray-700">
                                                                    {{ Array.isArray(requirement.allowed_evidence_types) && requirement.allowed_evidence_types.length ? requirement.allowed_evidence_types.join(', ') : '—' }}
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </article>
                                        </div>
                                    </section>
                                </div>
                            </div>
                        </article>
                    </div>
                </section>
            </template>
        </div>

        <Modal :show="requirementModalOpen" max-width="2xl" @close="closeRequirementModal">
            <div class="p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">
                            {{ tr('Crear requisito de carpeta', 'Create carpeta requirement') }}
                        </h3>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ tr('Ciclo activo', 'Active cycle') }}: {{ activeYear?.year || '—' }}
                        </p>
                        <p v-if="activeClubCatalog?.name && activeClassCatalog?.name" class="mt-2 text-sm text-gray-700">
                            {{ tr('Contexto', 'Context') }}: {{ activeClubCatalog.name }} / {{ activeClassCatalog.name }}
                        </p>
                        <p v-if="!props.clubCatalogs.length" class="mt-2 text-sm text-amber-700">
                            {{ tr('No hay catálogo de clubes y clases todavía. Crea ese catálogo primero desde el menú lateral.', 'There is no club/class catalog yet. Create it first from the sidebar menu.') }}
                        </p>
                    </div>
                    <button type="button" class="text-sm text-gray-500 hover:text-gray-700" @click="closeRequirementModal">
                        {{ tr('Cerrar', 'Close') }}
                    </button>
                </div>

                <form class="mt-6 space-y-4" @submit.prevent="submitRequirement">
                    <div>
                        <InputLabel for="requirement_title" :value="tr('Título', 'Title')" />
                        <input id="requirement_title" v-model="requirementForm.title" type="text" class="mt-1 block w-full rounded-md border-gray-300" required />
                        <InputError class="mt-2" :message="requirementForm.errors.title" />
                    </div>

                    <div>
                        <InputLabel for="requirement_description" :value="tr('Descripción', 'Description')" />
                        <textarea id="requirement_description" v-model="requirementForm.description" rows="3" class="mt-1 block w-full rounded-md border-gray-300" />
                        <InputError class="mt-2" :message="requirementForm.errors.description" />
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <InputLabel for="sort_order" :value="tr('Orden', 'Order')" />
                            <input id="sort_order" v-model="requirementForm.sort_order" type="number" min="1" class="mt-1 block w-full rounded-md border-gray-300" />
                            <InputError class="mt-2" :message="requirementForm.errors.sort_order" />
                        </div>
                        <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-3 text-sm text-gray-600">
                            {{ tr('Este requisito se guardará dentro de la clase seleccionada en el esquema del ciclo.', 'This requirement will be saved inside the class selected in the cycle schema.') }}
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <InputLabel for="requirement_type" :value="tr('Tipo', 'Type')" />
                            <select id="requirement_type" v-model="requirementForm.requirement_type" class="mt-1 block w-full rounded-md border-gray-300" required>
                                <option value="speciality">Speciality</option>
                                <option value="event">Event</option>
                                <option value="class">Class</option>
                                <option value="presentation">Presentation</option>
                                <option value="other">Other</option>
                            </select>
                            <InputError class="mt-2" :message="requirementForm.errors.requirement_type" />
                        </div>

                        <div>
                            <InputLabel for="validation_mode" :value="tr('Validación', 'Validation')" />
                            <select id="validation_mode" v-model="requirementForm.validation_mode" class="mt-1 block w-full rounded-md border-gray-300" required>
                                <option value="electronic">Electronic</option>
                                <option value="physical">Physical</option>
                                <option value="hybrid">Hybrid</option>
                            </select>
                            <InputError class="mt-2" :message="requirementForm.errors.validation_mode" />
                        </div>
                    </div>

                    <div>
                        <InputLabel :value="tr('Tipos de evidencia permitidos', 'Allowed evidence types')" />
                        <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            <label v-for="option in evidenceTypeOptions" :key="option.value" class="inline-flex items-center gap-2 rounded border border-gray-200 px-3 py-2 text-sm text-gray-700">
                                <input v-model="requirementForm.allowed_evidence_types" type="checkbox" :value="option.value" class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                                <span>{{ option.label }}</span>
                            </label>
                        </div>
                        <InputError class="mt-2" :message="requirementForm.errors.allowed_evidence_types" />
                    </div>

                    <div>
                        <InputLabel for="evidence_instructions" :value="tr('Instrucciones de evidencia', 'Evidence instructions')" />
                        <textarea id="evidence_instructions" v-model="requirementForm.evidence_instructions" rows="3" class="mt-1 block w-full rounded-md border-gray-300" />
                        <InputError class="mt-2" :message="requirementForm.errors.evidence_instructions" />
                    </div>

                    <div class="flex items-center justify-end gap-3">
                        <button type="button" class="rounded border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50" @click="closeRequirementModal">
                            {{ tr('Cerrar', 'Close') }}
                        </button>
                        <PrimaryButton :disabled="savingRequirement || !activeClubCatalog || !activeClassCatalog" class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-md">
                            {{ savingRequirement ? tr('Guardando...', 'Saving...') : tr('Guardar requisito', 'Save requirement') }}
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </Modal>
    </PathfinderLayout>
</template>
