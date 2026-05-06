<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useForm } from '@inertiajs/vue3'
import { useLocale } from '@/Composables/useLocale'
import { computed } from 'vue'

const props = defineProps({
    scopeOptions: {
        type: Object,
        default: () => ({}),
    },
    selectedScopeType: {
        type: String,
        default: 'club',
    },
    selectedScopeId: {
        type: [Number, String, null],
        default: null,
    },
    lockScopeSelection: {
        type: Boolean,
        default: false,
    },
    targetClubOptions: {
        type: Array,
        default: () => [],
    },
    clubTypeOptions: {
        type: Array,
        default: () => [],
    },
})

const scopeTypeLabels = {
    club: 'Club',
    church: 'Iglesia',
    district: 'Distrito',
    association: 'Asociación',
    union: 'Unión',
}

const availableScopeTypes = computed(() => Object.keys(props.scopeOptions || {}))
const selectedScopeOptions = computed(() => props.scopeOptions?.[form.scope_type] || [])
const isClubScope = computed(() => form.scope_type === 'club')
const selectedClubTypes = computed(() => new Set((form.target_club_types || []).map((value) => String(value))))
const requiresClubTypeSelection = computed(() => !isClubScope.value)
const selectedScopeLabel = computed(() => {
    const selected = selectedScopeOptions.value.find((option) => String(option.id) === String(form.scope_id))
    return selected?.label || ''
})

const filteredTargetClubs = computed(() => {
    const scopeId = Number(form.scope_id || 0)
    if (!scopeId) return []
    if (requiresClubTypeSelection.value && !selectedClubTypes.value.size) return []

    return (props.targetClubOptions || []).filter((club) => {
        const matchesClubType = !selectedClubTypes.value.size || selectedClubTypes.value.has(String(club.club_type || ''))
        if (!matchesClubType) return false

        switch (form.scope_type) {
            case 'church':
                return Number(club.church_id) === scopeId
            case 'district':
                return Number(club.district_id) === scopeId
            case 'association':
                return Number(club.association_id) === scopeId
            case 'union':
                return Number(club.union_id) === scopeId
            case 'club':
            default:
                return Number(club.id) === scopeId
        }
    })
})

const form = useForm({
    scope_type: props.selectedScopeType || availableScopeTypes.value[0] || 'club',
    scope_id: props.selectedScopeId || '',
    involved_club_ids: [],
    title: '',
    description: '',
    event_type: '',
    start_at: '',
    end_at: '',
    timezone: 'America/New_York',
    location_name: '',
    location_address: '',
    status: 'draft',
    target_club_types: [],
    budget_estimated_total: '',
    budget_actual_total: '',
    requires_approval: false,
    is_mandatory: false,
    fee_components: [],
    risk_level: '',
})
const { tr } = useLocale()

const visibleScopeLabel = computed(() => scopeTypeLabels[form.scope_type] || 'Scope')
const feeComponents = computed(() => form.fee_components || [])
const feeBreakdownTotal = computed(() => feeComponents.value.reduce((total, component) => {
    return total + Number(component?.amount || 0)
}, 0))
const requiredFeeBreakdownTotal = computed(() => feeComponents.value.reduce((total, component) => {
    return component?.is_required ? total + Number(component?.amount || 0) : total
}, 0))

const syncInvolvedClubs = () => {
    if (isClubScope.value) {
        form.involved_club_ids = form.scope_id ? [Number(form.scope_id)] : []
        return
    }

    const allowedIds = new Set(filteredTargetClubs.value.map((club) => Number(club.id)))
    form.involved_club_ids = (form.involved_club_ids || [])
        .map((id) => Number(id))
        .filter((id) => allowedIds.has(id))
}

const toggleTargetClub = (clubId) => {
    const id = Number(clubId)
    const selected = new Set((form.involved_club_ids || []).map((value) => Number(value)))
    if (selected.has(id)) {
        selected.delete(id)
    } else {
        selected.add(id)
    }
    form.involved_club_ids = Array.from(selected)
}

const selectAllTargetClubs = () => {
    form.involved_club_ids = filteredTargetClubs.value.map((club) => Number(club.id))
}

const clearTargetClubs = () => {
    form.involved_club_ids = []
}

const toggleClubType = (clubType) => {
    const value = String(clubType)
    const current = new Set((form.target_club_types || []).map((item) => String(item)))
    if (current.has(value)) {
        current.delete(value)
    } else {
        current.add(value)
    }
    form.target_club_types = Array.from(current)
    syncInvolvedClubs()
}

const addFeeComponent = () => {
    form.fee_components.push({
        label: '',
        amount: '',
        is_required: form.fee_components.length === 0,
    })
}

const removeFeeComponent = (index) => {
    form.fee_components.splice(index, 1)
}

const onScopeTypeChange = () => {
    const options = props.scopeOptions?.[form.scope_type] || []
    form.scope_id = options[0]?.id || ''
    if (form.scope_type === 'club') {
        form.target_club_types = []
    }
    syncInvolvedClubs()
}

const onScopeIdChange = () => {
    syncInvolvedClubs()
}

syncInvolvedClubs()

const submit = () => {
    if (requiresClubTypeSelection.value && !selectedClubTypes.value.size) {
        form.setError('target_club_types', tr('Selecciona al menos un tipo de club para eventos por encima del club.', 'Select at least one club type for events above club level.'))
        return
    }
    const payableComponents = (form.fee_components || []).filter((component) => String(component.label || '').trim() !== '' && Number(component.amount || 0) > 0)
    if (payableComponents.length && !payableComponents.some((component) => component.is_required)) {
        form.setError('fee_components', tr('Marca al menos un concepto obligatorio, por ejemplo Inscripción.', 'Mark at least one required component, for example registration.'))
        return
    }

    form.clearErrors('target_club_types', 'fee_components')
    form.post(route('events.store'))
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Crear evento', 'Create Event') }}</template>

        <div class="bg-white rounded-lg border p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm text-gray-600">{{ tr('Nivel del evento', 'Event level') }}</label>
                    <template v-if="lockScopeSelection">
                        <input
                            :value="visibleScopeLabel"
                            class="w-full border rounded px-3 py-2 text-sm bg-gray-100 text-gray-700"
                            readonly
                        />
                    </template>
                    <template v-else>
                        <select v-model="form.scope_type" class="w-full border rounded px-3 py-2 text-sm" @change="onScopeTypeChange">
                            <option v-for="scopeType in availableScopeTypes" :key="scopeType" :value="scopeType">
                                {{ scopeTypeLabels[scopeType] || scopeType }}
                            </option>
                        </select>
                    </template>
                </div>
                <div>
                    <label class="text-sm text-gray-600">{{ tr(visibleScopeLabel, visibleScopeLabel) }}</label>
                    <template v-if="lockScopeSelection">
                        <input
                            :value="selectedScopeLabel || tr('Selecciona un scope', 'Select a scope')"
                            class="w-full border rounded px-3 py-2 text-sm bg-gray-100 text-gray-700"
                            readonly
                        />
                    </template>
                    <template v-else>
                        <select v-model="form.scope_id" class="w-full border rounded px-3 py-2 text-sm" @change="onScopeIdChange">
                            <option value="">{{ tr('Selecciona un scope', 'Select a scope') }}</option>
                            <option v-for="option in selectedScopeOptions" :key="option.id" :value="option.id">{{ option.label }}</option>
                        </select>
                    </template>
                </div>
                <div>
                    <label class="text-sm text-gray-600">{{ tr('Título', 'Title') }}</label>
                    <input v-model="form.title" class="w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-sm text-gray-600">{{ tr('Tipo de evento', 'Event Type') }}</label>
                    <input v-model="form.event_type" class="w-full border rounded px-3 py-2 text-sm" :placeholder="tr('campamento, recaudación...', 'camp, fundraiser...')" />
                </div>
                <div class="md:col-span-2">
                    <label class="text-sm text-gray-600">{{ tr('Descripción del evento', 'Event Description') }}</label>
                    <textarea
                        v-model="form.description"
                        rows="4"
                        class="w-full border rounded px-3 py-2 text-sm"
                        :placeholder="tr('Describe el propósito, formato y contexto del evento para mejorar las tareas sugeridas por IA.', 'Describe the purpose, format, and context of the event to improve AI task suggestions.')"
                    ></textarea>
                </div>
                <div>
                    <label class="text-sm text-gray-600">{{ tr('Inicio', 'Start') }}</label>
                    <input v-model="form.start_at" type="datetime-local" class="w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-sm text-gray-600">{{ tr('Fin', 'End') }}</label>
                    <input v-model="form.end_at" type="datetime-local" class="w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-sm text-gray-600">{{ tr('Zona horaria', 'Timezone') }}</label>
                    <input v-model="form.timezone" class="w-full border rounded px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="text-sm text-gray-600">{{ tr('Nombre del lugar (opcional)', 'Location Name (optional)') }}</label>
                    <input v-model="form.location_name" class="w-full border rounded px-3 py-2 text-sm" :placeholder="tr('Opcional', 'Optional')" />
                </div>
                <div>
                    <label class="text-sm text-gray-600">{{ tr('Dirección del lugar (opcional)', 'Location Address (optional)') }}</label>
                    <input v-model="form.location_address" class="w-full border rounded px-3 py-2 text-sm" :placeholder="tr('Opcional', 'Optional')" />
                </div>
                <div v-if="!isClubScope" class="md:col-span-2 rounded border border-gray-200 p-4">
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <div class="text-sm font-semibold text-gray-800">{{ tr('Clubes involucrados', 'Involved clubs') }}</div>
                            <div class="text-xs text-gray-500">{{ tr('Selecciona los clubes que participan en este evento.', 'Select the clubs involved in this event.') }}</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" class="px-3 py-1 rounded border text-xs text-gray-700" @click="selectAllTargetClubs">{{ tr('Todos', 'Select all') }}</button>
                            <button type="button" class="px-3 py-1 rounded border text-xs text-gray-700" @click="clearTargetClubs">{{ tr('Limpiar', 'Clear') }}</button>
                        </div>
                    </div>
                    <div v-if="clubTypeOptions.length" class="mt-4 rounded border border-gray-200 p-3 space-y-3">
                        <div>
                            <div class="text-sm font-semibold text-gray-800">{{ tr('Tipos de club objetivo', 'Target club types') }}</div>
                            <div class="text-xs text-gray-500">{{ tr('Para eventos que no son de un club específico, primero selecciona qué tipos de clubes participan.', 'For events not owned by a specific club, first select which club types participate.') }}</div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <label
                                v-for="clubType in clubTypeOptions"
                                :key="clubType.value"
                                class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm"
                                :class="selectedClubTypes.has(String(clubType.value)) ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-gray-200 text-gray-700'"
                            >
                                <input
                                    type="checkbox"
                                    class="hidden"
                                    :checked="selectedClubTypes.has(String(clubType.value))"
                                    @change="toggleClubType(clubType.value)"
                                />
                                {{ clubType.label }}
                            </label>
                        </div>
                        <p v-if="form.errors.target_club_types" class="text-xs text-red-600">
                            {{ form.errors.target_club_types }}
                        </p>
                    </div>
                    <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-2 max-h-64 overflow-y-auto">
                        <label
                            v-for="club in filteredTargetClubs"
                            :key="club.id"
                            class="flex items-start gap-3 rounded border px-3 py-2 text-sm text-gray-700"
                        >
                            <input
                                type="checkbox"
                                class="mt-1"
                                :checked="form.involved_club_ids.includes(Number(club.id))"
                                @change="toggleTargetClub(club.id)"
                            />
                            <span>
                                <span class="block font-medium text-gray-900">{{ club.club_name }}</span>
                                <span class="block text-xs text-gray-500">{{ club.church_name }}<template v-if="club.district_name"> · {{ club.district_name }}</template></span>
                            </span>
                        </label>
                        <div v-if="requiresClubTypeSelection && !selectedClubTypes.size" class="text-sm text-amber-700">
                            {{ tr('Selecciona primero al menos un tipo de club.', 'Select at least one club type first.') }}
                        </div>
                        <div v-else-if="!filteredTargetClubs.length" class="text-sm text-gray-500">
                            {{ tr('No hay clubes disponibles para este scope y tipos seleccionados.', 'No clubs are available for this scope and selected types.') }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-6">
                <label class="text-sm text-gray-600 flex items-center gap-2">
                    <input type="checkbox" v-model="form.requires_approval" />
                    {{ tr('Requiere aprobación', 'Requires approval') }}
                </label>
                <label v-if="!isClubScope" class="text-sm text-gray-600 flex items-center gap-2">
                    <input type="checkbox" v-model="form.is_mandatory" />
                    {{ tr('Evento obligatorio', 'Mandatory event') }}
                </label>
            </div>
            <div class="rounded border border-gray-200 p-4 space-y-3">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="text-sm font-semibold text-gray-800">{{ tr('Desglose de inscripción', 'Signup fee breakdown') }}</div>
                        <div class="text-xs text-gray-500">{{ tr('Cada componente genera su propio concepto de pago por miembro participante.', 'Each component creates its own payment concept per participating member.') }}</div>
                    </div>
                    <div class="shrink-0 text-sm font-semibold text-gray-900 sm:text-right">
                        <div>{{ tr('Total', 'Total') }}: ${{ feeBreakdownTotal.toFixed(2) }}</div>
                        <div class="text-xs font-medium text-gray-500">{{ tr('Obligatorio', 'Required') }}: ${{ requiredFeeBreakdownTotal.toFixed(2) }}</div>
                    </div>
                </div>
                <div v-if="form.errors.fee_components" class="text-sm text-red-600">{{ form.errors.fee_components }}</div>
                <div class="space-y-2">
                    <div
                        v-for="(component, index) in feeComponents"
                        :key="`fee-component-${index}`"
                        class="space-y-3 rounded border border-gray-200 p-3"
                    >
                        <label class="block">
                            <span class="text-xs font-medium text-gray-600">{{ tr('Concepto', 'Component') }}</span>
                            <input
                                v-model="component.label"
                                class="mt-1 w-full rounded border px-3 py-2 text-sm"
                                :placeholder="tr('Ej. Inscripción, seguro, transporte', 'Ex. Registration, insurance, transport')"
                            />
                        </label>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,9rem)_minmax(0,10rem)_auto] sm:items-end">
                            <label class="block">
                                <span class="text-xs font-medium text-gray-600">{{ tr('Monto', 'Amount') }}</span>
                                <input
                                    v-model="component.amount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    class="mt-1 w-full rounded border px-3 py-2 text-sm"
                                    placeholder="0.00"
                                />
                            </label>
                            <div>
                                <span class="text-xs font-medium text-gray-600">{{ tr('Tipo', 'Type') }}</span>
                                <label class="mt-1 flex items-center gap-2 rounded border border-gray-200 px-3 py-2 text-sm text-gray-700">
                                    <input v-model="component.is_required" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                                    {{ tr('Obligatorio', 'Required') }}
                                </label>
                            </div>
                            <button
                                type="button"
                                class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-red-200 text-lg font-semibold leading-none text-red-700 hover:bg-red-50 sm:justify-self-end"
                                :aria-label="tr('Quitar componente', 'Remove component')"
                                :title="tr('Quitar componente', 'Remove component')"
                                @click="removeFeeComponent(index)"
                            >
                                ×
                            </button>
                        </div>
                    </div>
                </div>
                <button type="button" class="rounded border px-3 py-2 text-sm text-gray-700" @click="addFeeComponent">
                    {{ tr('Agregar componente', 'Add component') }}
                </button>
            </div>
            <button @click="submit" class="px-4 py-2 bg-blue-600 text-white rounded text-sm" :disabled="form.processing">
                {{ form.processing ? tr('Guardando...', 'Saving...') : tr('Crear evento', 'Create Event') }}
            </button>
        </div>
    </PathfinderLayout>
</template>
