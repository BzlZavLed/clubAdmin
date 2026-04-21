<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { router, useForm } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

const props = defineProps({
    union:  { type: Object, required: true },
    year:   { type: Number, required: true },
    events: { type: Array, default: () => [] },
})

// ── Year navigation ───────────────────────────────────────────
const currentYear = new Date().getFullYear()
const yearOptions = Array.from({ length: 6 }, (_, i) => currentYear - 1 + i)

const changeYear = (y) => router.get(route('union.workplan'), { year: y }, { preserveScroll: true })

// ── Modal state ───────────────────────────────────────────────
const showModal   = ref(false)
const editingEvent = ref(null)

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

const deleteEvent = (ev) => {
    if (!confirm(`¿Eliminar "${ev.title}"?`)) return
    router.delete(route('union.workplan.events.destroy', ev.id))
}

// ── Display helpers ───────────────────────────────────────────
const MONTHS = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre']

const clubTypeLabels = { pathfinders: 'Conquistadores', adventurers: 'Aventureros', master_guide: 'Guías Mayores' }
const clubTypeOptions = [
    { value: 'pathfinders',  label: 'Conquistadores' },
    { value: 'adventurers',  label: 'Aventureros' },
    { value: 'master_guide', label: 'Guías Mayores' },
]

const eventsByMonth = computed(() => {
    const groups = {}
    for (let m = 1; m <= 12; m++) groups[m] = []
    for (const ev of props.events) {
        const m = new Date(ev.date + 'T12:00:00').getMonth() + 1
        if (groups[m]) groups[m].push(ev)
    }
    return groups
})

const formatDate = (d) => {
    if (!d) return ''
    const dt = new Date(d + 'T12:00:00')
    return dt.toLocaleDateString('es', { day: 'numeric', month: 'short' })
}

const formatTime = (t) => t ? t.slice(0, 5) : ''

const typeStyle = (type) => type === 'program'
    ? 'bg-amber-100 text-amber-800'
    : 'bg-blue-100 text-blue-700'

const typeLabel = (type) => type === 'program' ? 'Programa' : 'General'

const toggleClubType = (val) => {
    const idx = form.target_club_types.indexOf(val)
    if (idx === -1) form.target_club_types.push(val)
    else form.target_club_types.splice(idx, 1)
}

const activeMonths = computed(() => Object.entries(eventsByMonth.value).filter(([, evs]) => evs.length > 0))
</script>

<template>
    <PathfinderLayout>
        <template #title>Plan de trabajo</template>

        <div class="space-y-6">

            <!-- Header -->
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ union.name }}</h2>
                        <p class="mt-0.5 text-sm text-gray-500">Calendario general de actividades para la unión</p>
                    </div>
                    <div class="flex items-center gap-3">
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
                        <button
                            type="button"
                            class="rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800 transition-colors"
                            @click="openCreate"
                        >
                            + Agregar evento
                        </button>
                    </div>
                </div>

                <!-- Legend -->
                <div class="mt-4 flex flex-wrap gap-3 text-xs">
                    <span class="flex items-center gap-1.5">
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-blue-400" />
                        <span class="text-gray-500">General — informativo para toda la unión</span>
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-amber-400" />
                        <span class="text-gray-500">Programa — actividad que los clubes deben preparar o realizar</span>
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="inline-block w-2 h-2 rounded-sm border-2 border-red-600" />
                        <span class="text-gray-500">Obligatorio</span>
                    </span>
                </div>
            </div>

            <!-- Empty state -->
            <div v-if="!events.length" class="rounded-2xl border border-dashed border-gray-200 p-12 text-center">
                <p class="text-sm font-medium text-gray-500">Sin eventos para {{ year }}</p>
                <p class="mt-1 text-xs text-gray-400">Haz clic en "Agregar evento" para comenzar el plan de trabajo.</p>
            </div>

            <!-- Monthly timeline -->
            <div v-else class="space-y-4">
                <template v-for="[monthNum, monthEvents] in activeMonths" :key="monthNum">
                    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                        <!-- Month header -->
                        <div class="flex items-center gap-3 border-b border-gray-100 bg-gray-50 px-6 py-3">
                            <span class="text-sm font-semibold text-gray-700">{{ MONTHS[monthNum - 1] }}</span>
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
                                        {{ new Date(ev.date + 'T12:00:00').getDate() }}
                                    </p>
                                    <p class="text-[10px] uppercase text-gray-400 tracking-wide">
                                        {{ MONTHS[new Date(ev.date + 'T12:00:00').getMonth()].slice(0, 3) }}
                                    </p>
                                    <p v-if="ev.end_date && ev.end_date !== ev.date" class="mt-0.5 text-[10px] text-gray-400">
                                        al {{ formatDate(ev.end_date) }}
                                    </p>
                                </div>

                                <!-- Content -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex flex-wrap items-center gap-2 mb-1">
                                        <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', typeStyle(ev.event_type)]">
                                            {{ typeLabel(ev.event_type) }}
                                        </span>
                                        <span v-if="ev.is_mandatory" class="rounded-full border border-red-600 px-2 py-0.5 text-xs font-medium text-red-600">
                                            Obligatorio
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
                                        <span v-else class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-400">Todos los clubes</span>
                                    </div>

                                    <p class="text-sm font-semibold text-gray-900 truncate">{{ ev.title }}</p>

                                    <div v-if="ev.start_time || ev.location" class="mt-0.5 flex flex-wrap gap-3 text-xs text-gray-400">
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
                                        Editar
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-md px-2 py-1 text-xs text-red-600 hover:bg-red-50 transition-colors"
                                        @click="deleteEvent(ev)"
                                    >
                                        Eliminar
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
                                {{ editingEvent ? 'Editar evento' : 'Nuevo evento' }}
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
                                <label class="block text-xs font-medium text-gray-700 mb-1">Título <span class="text-red-500">*</span></label>
                                <input
                                    v-model="form.title"
                                    type="text"
                                    placeholder="Nombre del evento"
                                    class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                />
                                <p v-if="form.errors.title" class="mt-1 text-xs text-red-500">{{ form.errors.title }}</p>
                            </div>

                            <!-- Type + Mandatory -->
                            <div class="flex gap-3">
                                <div class="flex-1">
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Tipo <span class="text-red-500">*</span></label>
                                    <select v-model="form.event_type" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        <option value="general">General</option>
                                        <option value="program">Programa</option>
                                    </select>
                                </div>
                                <div class="flex items-end pb-0.5">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" v-model="form.is_mandatory" class="rounded border-gray-300 text-red-600 focus:ring-red-500" />
                                        <span class="text-xs font-medium text-gray-700">Obligatorio</span>
                                    </label>
                                </div>
                            </div>

                            <!-- Dates -->
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Fecha <span class="text-red-500">*</span></label>
                                    <input v-model="form.date" type="date" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                    <p v-if="form.errors.date" class="mt-1 text-xs text-red-500">{{ form.errors.date }}</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Fecha fin</label>
                                    <input v-model="form.end_date" type="date" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                </div>
                            </div>

                            <!-- Times -->
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Hora inicio</label>
                                    <input v-model="form.start_time" type="time" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Hora fin</label>
                                    <input v-model="form.end_time" type="time" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                </div>
                            </div>

                            <!-- Location -->
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Lugar</label>
                                <input
                                    v-model="form.location"
                                    type="text"
                                    placeholder="Ciudad, sede, etc."
                                    class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                />
                            </div>

                            <!-- Target club types -->
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-2">Aplica a</label>
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
                                <p class="mt-1.5 text-xs text-gray-400">Sin selección = todos los clubes</p>
                            </div>

                            <!-- Description -->
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Descripción</label>
                                <textarea
                                    v-model="form.description"
                                    rows="3"
                                    placeholder="Detalles, instrucciones o notas para los clubes..."
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
                                Cancelar
                            </button>
                            <button
                                type="button"
                                :disabled="form.processing"
                                class="rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800 disabled:opacity-60 transition-colors"
                                @click="submit"
                            >
                                {{ editingEvent ? 'Guardar cambios' : 'Crear evento' }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

    </PathfinderLayout>
</template>
