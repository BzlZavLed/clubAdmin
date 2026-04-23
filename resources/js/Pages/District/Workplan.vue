<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import Modal from '@/Components/Modal.vue'
import { router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useGeneral } from '@/Composables/useGeneral'

const props = defineProps({
    district: { type: Object, required: true },
    association: { type: Object, default: null },
    union: { type: Object, default: null },
    year: { type: Number, required: true },
    events: { type: Array, default: () => [] },
    associationPublication: { type: Object, default: null },
    districtPublication: { type: Object, default: null },
    requiresRepublish: { type: Boolean, default: false },
})

const { showToast } = useGeneral()
const currentYear = new Date().getFullYear()
const yearOptions = Array.from({ length: 6 }, (_, i) => currentYear - 1 + i)
const changeYear = (y) => router.get(route('district.workplan'), { year: y }, { preserveScroll: true })

const showModal = ref(false)
const detailModalOpen = ref(false)
const detailEvent = ref(null)
const editingEvent = ref(null)
const publishing = ref(false)
const syncing = ref(false)
const confirmationModalOpen = ref(false)
const confirmationTitle = ref('')
const confirmationMessage = ref('')
const confirmationConfirmLabel = ref('')
const confirmationTone = ref('primary')
const confirmationBusy = ref(false)
const confirmationHandler = ref(null)

const emptyForm = () => ({
    year: props.year,
    date: '',
    end_date: '',
    start_time: '',
    end_time: '',
    event_type: 'general',
    title: '',
    description: '',
    location: '',
    target_club_types: [],
    is_mandatory: false,
})

const form = useForm(emptyForm())

const openCreate = () => {
    editingEvent.value = null
    form.reset()
    Object.assign(form, emptyForm())
    showModal.value = true
}

const openEdit = (ev) => {
    if (ev.source_level !== 'district') return
    editingEvent.value = ev
    form.year = ev.year
    form.date = ev.date?.split('T')[0] ?? ev.date
    form.end_date = ev.end_date?.split('T')[0] ?? ''
    form.start_time = ev.start_time ?? ''
    form.end_time = ev.end_time ?? ''
    form.event_type = ev.event_type
    form.title = ev.title
    form.description = ev.description ?? ''
    form.location = ev.location ?? ''
    form.target_club_types = ev.target_club_types ?? []
    form.is_mandatory = ev.is_mandatory ?? false
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
    editingEvent.value = null
    form.clearErrors()
}

const openDetails = (ev) => {
    detailEvent.value = ev
    detailModalOpen.value = true
}

const closeDetails = () => {
    detailModalOpen.value = false
    detailEvent.value = null
}

const trimTime = (value) => value ? String(value).slice(0, 5) : ''
const showFormErrors = () => {
    const firstError = Object.values(form.errors || {})[0]
    if (firstError) showToast(firstError, 'error')
}

const submit = () => {
    const payload = {
        ...form.data(),
        start_time: trimTime(form.start_time),
        end_time: trimTime(form.end_time),
        target_club_types: form.target_club_types.length ? form.target_club_types : null,
    }

    if (editingEvent.value) {
        form.transform(() => payload).put(route('district.workplan.events.update', editingEvent.value.id), {
            preserveScroll: true,
            onSuccess: closeModal,
            onError: showFormErrors,
        })
    } else {
        form.transform(() => payload).post(route('district.workplan.events.store'), {
            preserveScroll: true,
            onSuccess: closeModal,
            onError: showFormErrors,
        })
    }
}

const openConfirmationModal = ({ title, message, confirmLabel, tone = 'primary', onConfirm }) => {
    confirmationTitle.value = title
    confirmationMessage.value = message
    confirmationConfirmLabel.value = confirmLabel
    confirmationTone.value = tone
    confirmationHandler.value = onConfirm
    confirmationModalOpen.value = true
}

const closeConfirmationModal = () => {
    if (confirmationBusy.value) return
    confirmationModalOpen.value = false
    confirmationTitle.value = ''
    confirmationMessage.value = ''
    confirmationConfirmLabel.value = ''
    confirmationTone.value = 'primary'
    confirmationHandler.value = null
}

const runConfirmation = async () => {
    if (!confirmationHandler.value) return
    confirmationBusy.value = true
    try {
        await confirmationHandler.value()
        confirmationBusy.value = false
        closeConfirmationModal()
    } finally {
        confirmationBusy.value = false
    }
}

const deleteEvent = (ev) => {
    if (ev.source_level !== 'district') return
    openConfirmationModal({
        title: 'Eliminar evento distrital',
        message: `¿Eliminar "${ev.title}"?`,
        confirmLabel: 'Eliminar',
        tone: 'danger',
        onConfirm: () => new Promise((resolve) => {
            router.delete(route('district.workplan.events.destroy', ev.id), {
                preserveScroll: true,
                onFinish: resolve,
            })
        }),
    })
}

const publishCalendar = () => {
    openConfirmationModal({
        title: needsRepublish.value ? 'Republicar calendario distrital' : 'Publicar calendario distrital',
        message: `¿Publicar los eventos distritales ${props.year} a los clubes del distrito?`,
        confirmLabel: needsRepublish.value ? 'Republicar cambios' : 'Publicar a clubes',
        onConfirm: () => new Promise((resolve) => {
            publishing.value = true
            router.post(route('district.workplan.publish'), { year: props.year }, {
                preserveScroll: true,
                onFinish: () => {
                    publishing.value = false
                    resolve()
                },
            })
        }),
    })
}

const unpublishCalendar = () => {
    openConfirmationModal({
        title: 'Despublicar calendario distrital',
        message: `¿Despublicar los eventos distritales ${props.year}? Esto removerá esos eventos de los clubes del distrito.`,
        confirmLabel: 'Despublicar',
        tone: 'danger',
        onConfirm: () => new Promise((resolve) => {
            publishing.value = true
            router.post(route('district.workplan.unpublish'), { year: props.year }, {
                preserveScroll: true,
                onFinish: () => {
                    publishing.value = false
                    resolve()
                },
            })
        }),
    })
}

const syncMissingCalendar = () => {
    openConfirmationModal({
        title: 'Sincronizar eventos faltantes',
        message: `¿Agregar a los clubes del distrito los eventos distritales ${props.year} que todavia no existan en sus planes de trabajo?`,
        confirmLabel: 'Sincronizar faltantes',
        onConfirm: () => new Promise((resolve) => {
            syncing.value = true
            router.post(route('district.workplan.sync-missing'), { year: props.year }, {
                preserveScroll: true,
                onFinish: () => {
                    syncing.value = false
                    resolve()
                },
            })
        }),
    })
}

const MONTHS = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre']
const clubTypeLabels = { pathfinders: 'Conquistadores', adventurers: 'Aventureros', master_guide: 'Guías Mayores' }
const clubTypeOptions = [
    { value: 'pathfinders', label: 'Conquistadores' },
    { value: 'adventurers', label: 'Aventureros' },
    { value: 'master_guide', label: 'Guías Mayores' },
]

const districtEvents = computed(() => props.events.filter((ev) => ev.source_level === 'district'))
const isDistrictPublished = computed(() => props.districtPublication?.status === 'published')
const needsRepublish = computed(() => isDistrictPublished.value && props.requiresRepublish)
const dateOnly = (value) => String(value || '').slice(0, 10)

const eventsByMonth = computed(() => {
    const groups = {}
    for (let m = 1; m <= 12; m++) groups[m] = []
    for (const ev of props.events) {
        const m = new Date(dateOnly(ev.date) + 'T12:00:00').getMonth() + 1
        if (groups[m]) groups[m].push(ev)
    }
    return groups
})

const activeMonths = computed(() => Object.entries(eventsByMonth.value).filter(([, evs]) => evs.length > 0))

const formatTime = (t) => t ? t.slice(0, 5) : ''
const formatDate = (d) => {
    if (!d) return ''
    const dt = new Date(dateOnly(d) + 'T12:00:00')
    return dt.toLocaleDateString('es', { day: 'numeric', month: 'short' })
}
const formatDateLong = (d) => {
    if (!d) return ''
    const dt = new Date(dateOnly(d) + 'T12:00:00')
    return dt.toLocaleDateString('es', { day: 'numeric', month: 'long', year: 'numeric' })
}
const formatDateRange = (ev) => {
    if (!ev?.end_date || dateOnly(ev.end_date) === dateOnly(ev.date)) return ''
    return `${formatDate(ev.date)} - ${formatDate(ev.end_date)}`
}
const typeLabel = (type) => type === 'program' ? 'Programa' : 'General'
const typeStyle = (type) => type === 'program' ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-700'
const sourceLabel = (ev) => {
    if (ev.source_level === 'district') return 'Distrito'
    if (ev.source_level === 'union') return 'Union'
    return 'Asociacion'
}
const sourcePrefixClass = (ev) => {
    if (ev.source_level === 'district') return 'text-red-700'
    if (ev.source_level === 'union') return 'text-green-700'
    return 'text-blue-700'
}
const sourceBadgeClass = (ev) => {
    if (ev.source_level === 'district') return 'bg-red-50 text-red-700'
    if (ev.source_level === 'union') return 'bg-green-50 text-green-700'
    return 'bg-blue-50 text-blue-700'
}

const toggleClubType = (val) => {
    const idx = form.target_club_types.indexOf(val)
    if (idx === -1) form.target_club_types.push(val)
    else form.target_club_types.splice(idx, 1)
}
</script>

<template>
    <PathfinderLayout>
        <template #title>Plan de trabajo distrital</template>

        <div class="space-y-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ district.name }}</h2>
                        <p class="mt-0.5 text-sm text-gray-500">
                            Calendario publicado por {{ association?.name || 'la asociación' }}
                            <span v-if="union?.name"> · {{ union.name }}</span>
                        </p>
                        <p class="mt-1 text-xs" :class="associationPublication?.status === 'published' ? 'text-green-700' : 'text-gray-400'">
                            {{ associationPublication?.status === 'published' ? `Asociación publicada · ${associationPublication.published_at || ''}` : 'Mostrando calendario de asociación disponible para este año.' }}
                        </p>
                        <p class="mt-1 text-xs" :class="isDistrictPublished ? 'text-green-700' : 'text-gray-400'">
                            {{ isDistrictPublished ? `Distrito publicado a clubes · ${districtPublication?.published_at || ''}` : 'Eventos distritales no publicados a clubes' }}
                        </p>
                        <p v-if="needsRepublish" class="mt-1 text-xs font-medium text-amber-700">
                            Hay cambios distritales posteriores a la última publicación. Republica para sincronizar los clubes.
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <div class="flex items-center gap-1 rounded-lg border border-gray-300 bg-white px-1 py-1">
                            <button
                                v-for="y in yearOptions"
                                :key="y"
                                type="button"
                                :class="[
                                    'rounded-md px-3 py-1 text-sm font-medium transition-colors',
                                    y === year ? 'bg-red-700 text-white' : 'text-gray-600 hover:text-red-700',
                                ]"
                                @click="changeYear(y)"
                            >
                                {{ y }}
                            </button>
                        </div>
                        <button
                            v-if="!isDistrictPublished"
                            type="button"
                            :disabled="publishing || syncing || !districtEvents.length"
                            class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-50"
                            @click="publishCalendar"
                        >
                            Publicar distritales
                        </button>
                        <button
                            v-else-if="needsRepublish"
                            type="button"
                            :disabled="publishing || syncing"
                            class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50"
                            @click="publishCalendar"
                        >
                            Republicar cambios
                        </button>
                        <button
                            v-if="isDistrictPublished"
                            type="button"
                            :disabled="publishing || syncing"
                            class="rounded-lg border border-blue-300 bg-white px-4 py-2 text-sm font-medium text-blue-700 hover:bg-blue-50 disabled:opacity-50"
                            @click="syncMissingCalendar"
                        >
                            Sincronizar faltantes
                        </button>
                        <button
                            v-if="isDistrictPublished"
                            type="button"
                            :disabled="publishing || syncing"
                            class="rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
                            @click="unpublishCalendar"
                        >
                            Despublicar distritales
                        </button>
                        <button
                            type="button"
                            class="rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800"
                            @click="openCreate"
                        >
                            + Evento distrital
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="needsRepublish" class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900">
                Los clubes reciben copias publicadas de los eventos distritales. Si editaste o eliminaste eventos existentes, usa <strong>Republicar cambios</strong>. Si solo agregaste eventos nuevos, usa <strong>Sincronizar faltantes</strong>.
            </div>

            <div v-if="!events.length" class="rounded-2xl border border-dashed border-gray-200 bg-white p-12 text-center">
                <p class="text-sm font-medium text-gray-500">Sin eventos para {{ year }}</p>
                <p class="mt-1 text-xs text-gray-400">Cuando la asociación tenga eventos o el distrito cree eventos, aparecerán aquí.</p>
            </div>

            <div v-if="events.length" class="space-y-4">
                <template v-for="[monthNum, monthEvents] in activeMonths" :key="monthNum">
                    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                        <div class="flex items-center gap-3 border-b border-gray-100 bg-gray-50 px-6 py-3">
                            <span class="text-sm font-semibold text-gray-700">{{ MONTHS[monthNum - 1] }}</span>
                            <span class="rounded-full bg-gray-200 px-2 py-0.5 text-xs text-gray-500">{{ monthEvents.length }}</span>
                        </div>

                        <ul class="divide-y divide-gray-100">
                            <li v-for="ev in monthEvents" :key="`${ev.source_level}-${ev.id}`" class="group flex items-start gap-4 px-6 py-4 hover:bg-gray-50">
                                <div class="w-14 shrink-0 text-center">
                                    <p class="text-lg font-bold leading-none text-gray-800">{{ new Date(dateOnly(ev.date) + 'T12:00:00').getDate() }}</p>
                                    <p class="text-[10px] uppercase tracking-wide text-gray-400">{{ MONTHS[new Date(dateOnly(ev.date) + 'T12:00:00').getMonth()].slice(0, 3) }}</p>
                                    <p v-if="ev.end_date && dateOnly(ev.end_date) !== dateOnly(ev.date)" class="mt-0.5 text-[10px] text-gray-400">
                                        al {{ formatDate(ev.end_date) }}
                                    </p>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="mb-1 flex flex-wrap items-center gap-2">
                                        <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', typeStyle(ev.event_type)]">{{ typeLabel(ev.event_type) }}</span>
                                        <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', sourceBadgeClass(ev)]">{{ sourceLabel(ev) }}</span>
                                        <span v-if="ev.is_mandatory" class="rounded-full border border-red-600 px-2 py-0.5 text-xs font-medium text-red-600">Obligatorio</span>
                                        <template v-if="ev.target_club_types?.length">
                                            <span v-for="ct in ev.target_club_types" :key="ct" class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">
                                                {{ clubTypeLabels[ct] ?? ct }}
                                            </span>
                                        </template>
                                        <span v-else class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-400">Todos los clubes</span>
                                    </div>

                                    <button type="button" class="block max-w-full text-left" @click="openDetails(ev)">
                                        <p class="truncate text-sm font-semibold text-gray-900 hover:text-red-700">
                                            <span :class="sourcePrefixClass(ev)">{{ sourceLabel(ev) }} / </span>{{ ev.title }}
                                        </p>
                                    </button>
                                    <div v-if="formatDateRange(ev) || ev.start_time || ev.location" class="mt-0.5 flex flex-wrap gap-3 text-xs text-gray-400">
                                        <span v-if="formatDateRange(ev)" class="font-medium text-gray-500">{{ formatDateRange(ev) }}</span>
                                        <span v-if="ev.start_time">{{ formatTime(ev.start_time) }}<template v-if="ev.end_time"> - {{ formatTime(ev.end_time) }}</template></span>
                                        <span v-if="ev.location">{{ ev.location }}</span>
                                    </div>
                                    <p v-if="ev.description" class="mt-1 line-clamp-2 text-xs text-gray-500">{{ ev.description }}</p>
                                </div>

                                <div class="flex shrink-0 items-center gap-2 opacity-0 transition-opacity group-hover:opacity-100">
                                    <button type="button" class="rounded-md px-2 py-1 text-xs text-gray-600 hover:bg-gray-100" @click="openDetails(ev)">
                                        Ver
                                    </button>
                                    <button v-if="ev.source_level === 'district'" type="button" class="rounded-md px-2 py-1 text-xs text-blue-600 hover:bg-blue-50" @click="openEdit(ev)">
                                        Editar
                                    </button>
                                    <button v-if="ev.source_level === 'district'" type="button" class="rounded-md px-2 py-1 text-xs text-red-600 hover:bg-red-50" @click="deleteEvent(ev)">
                                        Eliminar
                                    </button>
                                </div>
                            </li>
                        </ul>
                    </div>
                </template>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/30" @click="closeModal" />
                <div class="relative flex max-h-[90vh] w-full max-w-lg flex-col rounded-2xl border border-gray-200 bg-white shadow-xl">
                    <div class="flex shrink-0 items-center justify-between border-b border-gray-100 px-6 py-4">
                        <h3 class="text-sm font-semibold text-gray-900">
                            {{ editingEvent ? 'Editar evento distrital' : 'Nuevo evento distrital' }}
                        </h3>
                        <button type="button" class="text-gray-400 hover:text-gray-600" @click="closeModal">x</button>
                    </div>

                    <div class="flex-1 space-y-4 overflow-y-auto px-6 py-5">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-700">Título <span class="text-red-500">*</span></label>
                            <input v-model="form.title" type="text" class="w-full rounded-lg border-gray-300 text-sm shadow-sm" />
                            <p v-if="form.errors.title" class="mt-1 text-xs text-red-500">{{ form.errors.title }}</p>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-700">Fecha <span class="text-red-500">*</span></label>
                                <input v-model="form.date" type="date" class="w-full rounded-lg border-gray-300 text-sm shadow-sm" />
                                <p v-if="form.errors.date" class="mt-1 text-xs text-red-500">{{ form.errors.date }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-700">Fecha fin</label>
                                <input v-model="form.end_date" type="date" class="w-full rounded-lg border-gray-300 text-sm shadow-sm" />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-700">Hora inicio</label>
                                <input v-model="form.start_time" type="time" class="w-full rounded-lg border-gray-300 text-sm shadow-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-700">Hora fin</label>
                                <input v-model="form.end_time" type="time" class="w-full rounded-lg border-gray-300 text-sm shadow-sm" />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-700">Tipo</label>
                                <select v-model="form.event_type" class="w-full rounded-lg border-gray-300 text-sm shadow-sm">
                                    <option value="general">General</option>
                                    <option value="program">Programa</option>
                                </select>
                            </div>
                            <div class="flex items-end pb-0.5">
                                <label class="flex cursor-pointer items-center gap-2">
                                    <input v-model="form.is_mandatory" type="checkbox" class="rounded border-gray-300 text-red-600" />
                                    <span class="text-xs font-medium text-gray-700">Obligatorio</span>
                                </label>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-700">Lugar</label>
                            <input v-model="form.location" type="text" class="w-full rounded-lg border-gray-300 text-sm shadow-sm" />
                        </div>

                        <div>
                            <label class="mb-2 block text-xs font-medium text-gray-700">Aplica a</label>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="ct in clubTypeOptions"
                                    :key="ct.value"
                                    type="button"
                                    :class="[
                                        'rounded-full border px-3 py-1 text-xs font-medium',
                                        form.target_club_types.includes(ct.value)
                                            ? 'border-red-700 bg-red-700 text-white'
                                            : 'border-gray-300 bg-white text-gray-600 hover:border-red-400 hover:text-red-700',
                                    ]"
                                    @click="toggleClubType(ct.value)"
                                >
                                    {{ ct.label }}
                                </button>
                            </div>
                            <p class="mt-1.5 text-xs text-gray-400">Sin selección = todos los clubes del distrito</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-700">Descripción</label>
                            <textarea v-model="form.description" rows="4" class="w-full rounded-lg border-gray-300 text-sm shadow-sm" />
                        </div>
                    </div>

                    <div class="flex shrink-0 justify-end gap-3 border-t border-gray-100 px-6 py-4">
                        <button type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50" @click="closeModal">
                            Cancelar
                        </button>
                        <button type="button" :disabled="form.processing" class="rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800 disabled:opacity-60" @click="submit">
                            {{ editingEvent ? 'Guardar cambios' : 'Crear evento' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <Modal :show="detailModalOpen" max-width="lg" @close="closeDetails">
            <div v-if="detailEvent" class="p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="mb-2 flex flex-wrap gap-2">
                            <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', typeStyle(detailEvent.event_type)]">{{ typeLabel(detailEvent.event_type) }}</span>
                            <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', sourceBadgeClass(detailEvent)]">{{ sourceLabel(detailEvent) }}</span>
                            <span v-if="detailEvent.is_mandatory" class="rounded-full border border-red-600 px-2 py-0.5 text-xs font-medium text-red-600">Obligatorio</span>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900">
                            <span :class="sourcePrefixClass(detailEvent)">{{ sourceLabel(detailEvent) }} / </span>{{ detailEvent.title }}
                        </h3>
                    </div>
                    <button type="button" class="text-gray-400 hover:text-gray-600" @click="closeDetails">x</button>
                </div>

                <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-400">Fecha</dt>
                        <dd class="mt-1 text-gray-800">
                            {{ formatDateLong(detailEvent.date) }}
                            <template v-if="detailEvent.end_date && dateOnly(detailEvent.end_date) !== dateOnly(detailEvent.date)">
                                - {{ formatDateLong(detailEvent.end_date) }}
                            </template>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-400">Hora</dt>
                        <dd class="mt-1 text-gray-800">
                            <template v-if="detailEvent.start_time">{{ formatTime(detailEvent.start_time) }}<template v-if="detailEvent.end_time"> - {{ formatTime(detailEvent.end_time) }}</template></template>
                            <template v-else>Sin horario</template>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-400">Lugar</dt>
                        <dd class="mt-1 text-gray-800">{{ detailEvent.location || 'Sin lugar definido' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-400">Aplica a</dt>
                        <dd class="mt-1 text-gray-800">
                            <template v-if="detailEvent.target_club_types?.length">
                                {{ detailEvent.target_club_types.map((ct) => clubTypeLabels[ct] ?? ct).join(', ') }}
                            </template>
                            <template v-else>Todos los clubes</template>
                        </dd>
                    </div>
                </dl>

                <div class="mt-5">
                    <h4 class="text-xs font-medium uppercase tracking-wide text-gray-400">Descripción</h4>
                    <p class="mt-2 whitespace-pre-line text-sm leading-6 text-gray-700">
                        {{ detailEvent.description || 'Sin descripción.' }}
                    </p>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50" @click="closeDetails">
                        Cerrar
                    </button>
                    <button v-if="detailEvent.source_level === 'district'" type="button" class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800" @click="closeDetails(); openEdit(detailEvent)">
                        Editar
                    </button>
                </div>
            </div>
        </Modal>

        <Modal :show="confirmationModalOpen" max-width="md" @close="closeConfirmationModal">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900">{{ confirmationTitle }}</h3>
                <p class="mt-3 text-sm leading-6 text-gray-600">{{ confirmationMessage }}</p>

                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        class="rounded border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                        :disabled="confirmationBusy"
                        @click="closeConfirmationModal"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        class="rounded px-4 py-2 text-sm font-medium text-white disabled:opacity-70"
                        :class="confirmationTone === 'danger' ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-700 hover:bg-blue-800'"
                        :disabled="confirmationBusy"
                        @click="runConfirmation"
                    >
                        {{ confirmationBusy ? 'Procesando...' : confirmationConfirmLabel }}
                    </button>
                </div>
            </div>
        </Modal>
    </PathfinderLayout>
</template>
