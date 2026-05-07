<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import Modal from '@/Components/Modal.vue'
import { router, useForm } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    union:  { type: Object, required: true },
    clubTypeOptions: { type: Array, default: () => [] },
    year:   { type: Number, required: true },
    events: { type: Array, default: () => [] },
    publication: { type: Object, default: null },
    requiresRepublish: { type: Boolean, default: false },
})

const { locale, tr } = useLocale()

// ── Year navigation ───────────────────────────────────────────
const currentYear = new Date().getFullYear()
const yearOptions = Array.from({ length: 6 }, (_, i) => currentYear - 1 + i)

const changeYear = (y) => router.get(route('union.workplan'), { year: y }, { preserveScroll: true })
const pdfHref = computed(() => route('union.workplan.pdf', { year: props.year }))

// ── Modal state ───────────────────────────────────────────────
const showModal   = ref(false)
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
    year:              props.year,
    date:              '',
    end_date:          '',
    start_time:        '',
    end_time:          '',
    event_type:        'general',
    title:             '',
    description:       '',
    location:          '',
    target_club_types: [],
    is_mandatory:      false,
})

const form = useForm(emptyForm())

const openCreate = () => {
    editingEvent.value = null
    form.reset()
    Object.assign(form, emptyForm())
    showModal.value = true
}

const openEdit = (ev) => {
    editingEvent.value = ev
    form.year              = ev.year
    form.date              = ev.date?.split('T')[0] ?? ev.date
    form.end_date          = ev.end_date?.split('T')[0] ?? ''
    form.start_time        = ev.start_time ?? ''
    form.end_time          = ev.end_time ?? ''
    form.event_type        = ev.event_type
    form.title             = ev.title
    form.description       = ev.description ?? ''
    form.location          = ev.location ?? ''
    form.target_club_types = ev.target_club_types ?? []
    form.is_mandatory      = ev.is_mandatory ?? false
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
    editingEvent.value = null
    form.clearErrors()
}

const submit = () => {
    const payload = {
        ...form.data(),
        target_club_types: form.target_club_types.length ? form.target_club_types : null,
    }
    if (editingEvent.value) {
        form.transform(() => payload).put(route('union.workplan.events.update', editingEvent.value.id), {
            onSuccess: closeModal,
        })
    } else {
        form.transform(() => payload).post(route('union.workplan.events.store'), {
            onSuccess: closeModal,
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
    openConfirmationModal({
        title: tr('Eliminar evento', 'Delete event'),
        message: tr(`¿Eliminar "${ev.title}"?`, `Delete "${ev.title}"?`),
        confirmLabel: tr('Eliminar', 'Delete'),
        tone: 'danger',
        onConfirm: () => new Promise((resolve) => {
            router.delete(route('union.workplan.events.destroy', ev.id), {
                preserveScroll: true,
                onFinish: resolve,
            })
        }),
    })
}

const publishCalendar = () => {
    openConfirmationModal({
        title: needsRepublish.value ? tr('Republicar calendario', 'Republish calendar') : tr('Publicar calendario', 'Publish calendar'),
        message: tr(
            `¿Publicar el calendario ${props.year} a todas las asociaciones de la unión?`,
            `Publish the ${props.year} calendar to every association in the union?`,
        ),
        confirmLabel: needsRepublish.value ? tr('Republicar cambios', 'Republish changes') : tr('Publicar calendario', 'Publish calendar'),
        onConfirm: () => new Promise((resolve) => {
            publishing.value = true
            router.post(route('union.workplan.publish'), { year: props.year }, {
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
        title: tr('Sincronizar eventos faltantes', 'Sync missing events'),
        message: tr(
            `¿Buscar eventos nuevos del calendario ${props.year} y agregarlos a las asociaciones y clubes donde todavia no existan?`,
            `Find new events from the ${props.year} calendar and add them to associations and clubs where they do not exist yet?`,
        ),
        confirmLabel: tr('Sincronizar faltantes', 'Sync missing'),
        onConfirm: () => new Promise((resolve) => {
            syncing.value = true
            router.post(route('union.workplan.sync-missing'), { year: props.year }, {
                preserveScroll: true,
                onFinish: () => {
                    syncing.value = false
                    resolve()
                },
            })
        }),
    })
}

const unpublishCalendar = () => {
    openConfirmationModal({
        title: tr('Despublicar calendario', 'Unpublish calendar'),
        message: tr(
            `¿Despublicar el calendario ${props.year}? Esto removerá los eventos propagados hacia asociaciones y clubes.`,
            `Unpublish the ${props.year} calendar? This will remove the events propagated to associations and clubs.`,
        ),
        confirmLabel: tr('Despublicar', 'Unpublish'),
        tone: 'danger',
        onConfirm: () => new Promise((resolve) => {
            publishing.value = true
            router.post(route('union.workplan.unpublish'), { year: props.year }, {
                preserveScroll: true,
                onFinish: () => {
                    publishing.value = false
                    resolve()
                },
            })
        }),
    })
}

// ── Display helpers ───────────────────────────────────────────
const months = computed(() => locale.value === 'en'
    ? ['January','February','March','April','May','June','July','August','September','October','November','December']
    : ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre']
)

const clubTypeOptions = computed(() => props.clubTypeOptions ?? [])
const clubTypeLabels = computed(() =>
    Object.fromEntries(clubTypeOptions.value.map((option) => [option.value, option.label]))
)
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

const formatDate = (d) => {
    if (!d) return ''
    const dt = new Date(dateOnly(d) + 'T12:00:00')
    return dt.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'es', { day: 'numeric', month: 'short' })
}

const formatDateRange = (ev) => {
    if (!ev?.end_date || dateOnly(ev.end_date) === dateOnly(ev.date)) return ''
    return `${formatDate(ev.date)} - ${formatDate(ev.end_date)}`
}

const formatTime = (t) => t ? t.slice(0, 5) : ''

const typeStyle = (type) => type === 'program'
    ? 'bg-amber-100 text-amber-800'
    : 'bg-blue-100 text-blue-700'

const typeLabel = (type) => type === 'program' ? tr('Programa', 'Program') : tr('General', 'General')

const toggleClubType = (val) => {
    const idx = form.target_club_types.indexOf(val)
    if (idx === -1) form.target_club_types.push(val)
    else form.target_club_types.splice(idx, 1)
}

const activeMonths = computed(() => Object.entries(eventsByMonth.value).filter(([, evs]) => evs.length > 0))
const isPublished = computed(() => props.publication?.status === 'published')
const needsRepublish = computed(() => isPublished.value && props.requiresRepublish)
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Plan de trabajo', 'Workplan') }}</template>

        <div class="space-y-6">

            <!-- Header -->
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ union.name }}</h2>
                        <p class="mt-0.5 text-sm text-gray-500">{{ tr('Calendario general de actividades para la unión', 'General activity calendar for the union') }}</p>
                        <p class="mt-1 text-xs" :class="isPublished ? 'text-green-700' : 'text-gray-400'">
                            {{ isPublished ? `${tr('Publicado', 'Published')} · ${publication?.published_at || ''}` : tr('No publicado a asociaciones', 'Not published to associations') }}
                        </p>
                        <p v-if="needsRepublish" class="mt-1 text-xs font-medium text-amber-700">
                            {{ tr('Hay cambios posteriores a la ultima publicacion. Usa republicar para aplicar ediciones y eliminaciones; usa sincronizar faltantes si solo agregaste eventos nuevos.', 'There are changes after the last publication. Use republish to apply edits and deletions; use sync missing if you only added new events.') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <!-- Year selector -->
                        <div class="flex items-center gap-1 rounded-lg border border-gray-300 bg-white px-1 py-1">
                            <button
                                v-for="y in yearOptions" :key="y"
                                type="button"
                                :class="[
                                    'rounded-md px-3 py-1 text-sm font-medium transition-colors',
                                    y === year ? 'bg-red-700 text-white' : 'text-gray-600 hover:text-red-700',
                                ]"
                                @click="changeYear(y)"
                            >{{ y }}</button>
                        </div>
                        <a
                            :href="pdfHref"
                            target="_blank"
                            rel="noopener"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50"
                        >
                            {{ tr('Imprimir PDF', 'Print PDF') }}
                        </a>
                        <button
                            v-if="!isPublished"
                            type="button"
                            :disabled="publishing || syncing || !events.length"
                            class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-800 disabled:opacity-50"
                            @click="publishCalendar"
                        >
                            {{ tr('Publicar calendario', 'Publish calendar') }}
                        </button>
                        <button
                            v-else-if="needsRepublish"
                            type="button"
                            :disabled="publishing || syncing"
                            class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-amber-700 disabled:opacity-50"
                            @click="publishCalendar"
                        >
                            {{ tr('Republicar cambios', 'Republish changes') }}
                        </button>
                        <button
                            v-if="isPublished"
                            type="button"
                            :disabled="publishing || syncing"
                            class="rounded-lg border border-blue-300 bg-white px-4 py-2 text-sm font-medium text-blue-700 transition-colors hover:bg-blue-50 disabled:opacity-50"
                            @click="syncMissingCalendar"
                        >
                            {{ tr('Sincronizar faltantes', 'Sync missing') }}
                        </button>
                        <button
                            v-if="isPublished"
                            type="button"
                            :disabled="publishing || syncing"
                            class="rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 transition-colors hover:bg-red-50 disabled:opacity-50"
                            @click="unpublishCalendar"
                        >
                            {{ tr('Despublicar', 'Unpublish') }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800 transition-colors"
                            @click="openCreate"
                        >
                            + {{ tr('Agregar evento', 'Add event') }}
                        </button>
                    </div>
                </div>

                <!-- Legend -->
                <div class="mt-4 flex flex-wrap gap-3 text-xs">
                    <span class="flex items-center gap-1.5">
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-blue-400" />
                        <span class="text-gray-500">{{ tr('General - informativo para toda la unión', 'General - informational for the whole union') }}</span>
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-amber-400" />
                        <span class="text-gray-500">{{ tr('Programa - actividad que los clubes deben preparar o realizar', 'Program - activity clubs must prepare or complete') }}</span>
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="inline-block w-2 h-2 rounded-sm border-2 border-red-600" />
                        <span class="text-gray-500">{{ tr('Obligatorio', 'Required') }}</span>
                    </span>
                </div>
            </div>

            <div v-if="needsRepublish" class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900">
                {{ tr('Las asociaciones consultan el calendario de union y los clubes reciben copias publicadas a traves de cada asociacion. Si editaste o eliminaste eventos existentes, usa', 'Associations review the union calendar and clubs receive published copies through each association. If you edited or deleted existing events, use') }} <strong>{{ tr('Republicar cambios', 'Republish changes') }}</strong>. {{ tr('Si solo agregaste eventos nuevos, usa', 'If you only added new events, use') }} <strong>{{ tr('Sincronizar faltantes', 'Sync missing') }}</strong>.
            </div>

            <!-- Empty state -->
            <div v-if="!events.length" class="rounded-2xl border border-dashed border-gray-200 p-12 text-center">
                <p class="text-sm font-medium text-gray-500">{{ tr('Sin eventos para', 'No events for') }} {{ year }}</p>
                <p class="mt-1 text-xs text-gray-400">{{ tr('Haz clic en "Agregar evento" para comenzar el plan de trabajo.', 'Click "Add event" to start the workplan.') }}</p>
            </div>

            <!-- Monthly timeline -->
            <div v-else class="space-y-4">
                <template v-for="[monthNum, monthEvents] in activeMonths" :key="monthNum">
                    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                        <!-- Month header -->
                        <div class="flex items-center gap-3 border-b border-gray-100 bg-gray-50 px-6 py-3">
                            <span class="text-sm font-semibold text-gray-700">{{ months[monthNum - 1] }}</span>
                            <span class="rounded-full bg-gray-200 px-2 py-0.5 text-xs text-gray-500">{{ monthEvents.length }}</span>
                        </div>

                        <!-- Events list -->
                        <ul class="divide-y divide-gray-100">
                            <li
                                v-for="ev in monthEvents"
                                :key="ev.id"
                                class="group flex items-start gap-4 px-6 py-4 hover:bg-gray-50 transition-colors"
                            >
                                <!-- Date column -->
                                <div class="w-14 shrink-0 text-center">
                                    <p class="text-lg font-bold text-gray-800 leading-none">
                                        {{ new Date(dateOnly(ev.date) + 'T12:00:00').getDate() }}
                                    </p>
                                    <p class="text-[10px] uppercase text-gray-400 tracking-wide">
                                        {{ months[new Date(dateOnly(ev.date) + 'T12:00:00').getMonth()].slice(0, 3) }}
                                    </p>
                                    <p v-if="ev.end_date && dateOnly(ev.end_date) !== dateOnly(ev.date)" class="mt-0.5 text-[10px] text-gray-400">
                                        {{ tr('al', 'to') }} {{ formatDate(ev.end_date) }}
                                    </p>
                                </div>

                                <!-- Content -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex flex-wrap items-center gap-2 mb-1">
                                        <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', typeStyle(ev.event_type)]">
                                            {{ typeLabel(ev.event_type) }}
                                        </span>
                                        <span v-if="ev.is_mandatory" class="rounded-full border border-red-600 px-2 py-0.5 text-xs font-medium text-red-600">
                                            {{ tr('Obligatorio', 'Required') }}
                                        </span>
                                        <template v-if="ev.target_club_types?.length">
                                            <span
                                                v-for="ct in ev.target_club_types"
                                                :key="ct"
                                                class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500"
                                            >
                                                {{ clubTypeLabels[ct] ?? ct }}
                                            </span>
                                        </template>
                                        <span v-else class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-400">{{ tr('Todos los clubes', 'All clubs') }}</span>
                                    </div>

                                    <p class="text-sm font-semibold text-gray-900 truncate">{{ ev.title }}</p>

                                    <div v-if="formatDateRange(ev) || ev.start_time || ev.location" class="mt-0.5 flex flex-wrap gap-3 text-xs text-gray-400">
                                        <span v-if="formatDateRange(ev)" class="font-medium text-gray-500">
                                            {{ formatDateRange(ev) }}
                                        </span>
                                        <span v-if="ev.start_time">
                                            {{ formatTime(ev.start_time) }}<template v-if="ev.end_time"> – {{ formatTime(ev.end_time) }}</template>
                                        </span>
                                        <span v-if="ev.location">📍 {{ ev.location }}</span>
                                    </div>

                                    <p v-if="ev.description" class="mt-1 text-xs text-gray-500 line-clamp-2">{{ ev.description }}</p>
                                </div>

                                <!-- Actions -->
                                <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity shrink-0">
                                    <button
                                        type="button"
                                        class="rounded-md px-2 py-1 text-xs text-blue-600 hover:bg-blue-50 transition-colors"
                                        @click="openEdit(ev)"
                                    >
                                        {{ tr('Editar', 'Edit') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-md px-2 py-1 text-xs text-red-600 hover:bg-red-50 transition-colors"
                                        @click="deleteEvent(ev)"
                                    >
                                        {{ tr('Eliminar', 'Delete') }}
                                    </button>
                                </div>
                            </li>
                        </ul>
                    </div>
                </template>
            </div>

        </div>

        <!-- Add / Edit modal -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition ease-out duration-150"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition ease-in duration-100"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-black/30" @click="closeModal" />

                    <div class="relative w-full max-w-lg rounded-2xl bg-white shadow-xl border border-gray-200 max-h-[90vh] flex flex-col">

                        <!-- Modal header -->
                        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 shrink-0">
                            <h3 class="text-sm font-semibold text-gray-900">
                                {{ editingEvent ? tr('Editar evento', 'Edit event') : tr('Nuevo evento', 'New event') }}
                            </h3>
                            <button type="button" class="text-gray-400 hover:text-gray-600" @click="closeModal">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <!-- Modal body -->
                        <div class="flex-1 overflow-y-auto px-6 py-5 space-y-4">

                            <!-- Title -->
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">{{ tr('Título', 'Title') }} <span class="text-red-500">*</span></label>
                                <input
                                    v-model="form.title"
                                    type="text"
                                    :placeholder="tr('Nombre del evento', 'Event name')"
                                    class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                />
                                <p v-if="form.errors.title" class="mt-1 text-xs text-red-500">{{ form.errors.title }}</p>
                            </div>

                            <!-- Type + Mandatory -->
                            <div class="flex gap-3">
                                <div class="flex-1">
                                    <label class="block text-xs font-medium text-gray-700 mb-1">{{ tr('Tipo', 'Type') }} <span class="text-red-500">*</span></label>
                                    <select v-model="form.event_type" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        <option value="general">{{ tr('General', 'General') }}</option>
                                        <option value="program">{{ tr('Programa', 'Program') }}</option>
                                    </select>
                                </div>
                                <div class="flex items-end pb-0.5">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" v-model="form.is_mandatory" class="rounded border-gray-300 text-red-600 focus:ring-red-500" />
                                        <span class="text-xs font-medium text-gray-700">{{ tr('Obligatorio', 'Required') }}</span>
                                    </label>
                                </div>
                            </div>

                            <!-- Dates -->
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">{{ tr('Fecha', 'Date') }} <span class="text-red-500">*</span></label>
                                    <input v-model="form.date" type="date" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                    <p v-if="form.errors.date" class="mt-1 text-xs text-red-500">{{ form.errors.date }}</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">{{ tr('Fecha fin', 'End date') }}</label>
                                    <input v-model="form.end_date" type="date" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                </div>
                            </div>

                            <!-- Times -->
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">{{ tr('Hora inicio', 'Start time') }}</label>
                                    <input v-model="form.start_time" type="time" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">{{ tr('Hora fin', 'End time') }}</label>
                                    <input v-model="form.end_time" type="time" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                </div>
                            </div>

                            <!-- Location -->
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">{{ tr('Lugar', 'Location') }}</label>
                                <input
                                    v-model="form.location"
                                    type="text"
                                    :placeholder="tr('Ciudad, sede, etc.', 'City, venue, etc.')"
                                    class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                />
                            </div>

                            <!-- Target club types -->
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-2">{{ tr('Aplica a', 'Applies to') }}</label>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="ct in clubTypeOptions" :key="ct.value"
                                        type="button"
                                        :class="[
                                            'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                                            form.target_club_types.includes(ct.value)
                                                ? 'bg-red-700 text-white border-red-700'
                                                : 'bg-white text-gray-600 border-gray-300 hover:border-red-400 hover:text-red-700',
                                        ]"
                                        @click="toggleClubType(ct.value)"
                                    >
                                        {{ ct.label }}
                                    </button>
                                </div>
                                <p class="mt-1.5 text-xs text-gray-400">{{ tr('Sin selección = todos los clubes', 'No selection = all clubs') }}</p>
                            </div>

                            <!-- Description -->
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">{{ tr('Descripción', 'Description') }}</label>
                                <textarea
                                    v-model="form.description"
                                    rows="3"
                                    :placeholder="tr('Detalles, instrucciones o notas para los clubes...', 'Details, instructions, or notes for clubs...')"
                                    class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                />
                            </div>

                        </div>

                        <!-- Modal footer -->
                        <div class="flex justify-end gap-3 border-t border-gray-100 px-6 py-4 shrink-0">
                            <button
                                type="button"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                @click="closeModal"
                            >
                                {{ tr('Cancelar', 'Cancel') }}
                            </button>
                            <button
                                type="button"
                                :disabled="form.processing"
                                class="rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800 disabled:opacity-60 transition-colors"
                                @click="submit"
                            >
                                {{ editingEvent ? tr('Guardar cambios', 'Save changes') : tr('Crear evento', 'Create event') }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

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
                        {{ tr('Cancelar', 'Cancel') }}
                    </button>
                    <button
                        type="button"
                        class="rounded px-4 py-2 text-sm font-medium text-white disabled:opacity-70"
                        :class="confirmationTone === 'danger' ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-700 hover:bg-blue-800'"
                        :disabled="confirmationBusy"
                        @click="runConfirmation"
                    >
                        {{ confirmationBusy ? tr('Procesando...', 'Processing...') : confirmationConfirmLabel }}
                    </button>
                </div>
            </div>
        </Modal>

    </PathfinderLayout>
</template>
