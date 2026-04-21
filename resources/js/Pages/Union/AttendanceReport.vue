<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

const props = defineProps({
    union:              { type: Object, required: true },
    month:              { type: Number, required: true },
    year:               { type: Number, required: true },
    level:              { type: String, default: 'union' },
    breadcrumb:         { type: Array, default: () => [] },
    rows:               { type: Array, default: () => [] },
    sessions:           { type: Array, default: () => [] },
    current_entity:     { type: Object, default: null },
    parent_entity:      { type: Object, default: null },
    grandparent_entity: { type: Object, default: null },
})

const months = [
    { value: 1, label: 'Enero' }, { value: 2, label: 'Febrero' }, { value: 3, label: 'Marzo' },
    { value: 4, label: 'Abril' }, { value: 5, label: 'Mayo' }, { value: 6, label: 'Junio' },
    { value: 7, label: 'Julio' }, { value: 8, label: 'Agosto' }, { value: 9, label: 'Septiembre' },
    { value: 10, label: 'Octubre' }, { value: 11, label: 'Noviembre' }, { value: 12, label: 'Diciembre' },
]

const currentYear = new Date().getFullYear()
const yearOptions = Array.from({ length: 5 }, (_, i) => currentYear - 2 + i)

const selectedMonth = ref(props.month)
const selectedYear  = ref(props.year)

// ── Navigation helpers ────────────────────────────────────────
const currentParams = () => {
    const p = { month: selectedMonth.value, year: selectedYear.value }
    if (props.level !== 'union') p.level = props.level
    if (props.current_entity) {
        if (props.level === 'association') p.association_id = props.current_entity.id
        if (props.level === 'district')    p.district_id    = props.current_entity.id
        if (props.level === 'club')        p.club_id        = props.current_entity.id
    }
    if (props.parent_entity) {
        if (props.level === 'district') p.association_id = props.parent_entity.id
        if (props.level === 'club')     p.district_id    = props.parent_entity.id
    }
    if (props.grandparent_entity && props.level === 'club') {
        p.association_id = props.grandparent_entity.id
    }
    return p
}

const applyFilter = () => {
    router.get(route('union.reports.assistance'), currentParams(), { preserveScroll: true })
}

const drillInto = (row) => {
    const levelMap = { union: 'association', association: 'district', district: 'club' }
    const nextLevel = levelMap[props.level]
    if (!nextLevel) return

    const params = { month: selectedMonth.value, year: selectedYear.value, level: nextLevel }

    if (props.level === 'union') {
        params.association_id = row.id
    } else if (props.level === 'association') {
        params.association_id = props.current_entity?.id
        params.district_id    = row.id
    } else if (props.level === 'district') {
        params.association_id = props.parent_entity?.id
        params.district_id    = props.current_entity?.id
        params.club_id        = row.club_id ?? row.id
    }

    router.get(route('union.reports.assistance'), params, { preserveScroll: true })
}

const navToCrumb = (crumb) => {
    if (crumb.level === 'union') {
        router.get(route('union.reports.assistance'), { month: selectedMonth.value, year: selectedYear.value })
        return
    }
    router.get(route('union.reports.assistance'), { month: selectedMonth.value, year: selectedYear.value, level: crumb.level, ...crumb.params })
}

const downloadCsv = () => {
    const qs = new URLSearchParams(currentParams()).toString()
    window.location.href = route('union.reports.assistance.csv') + (qs ? '?' + qs : '')
}

// ── Display helpers ───────────────────────────────────────────
const clubTypeLabel = (t) => ({ adventurers: 'Aventureros', pathfinders: 'Conquistadores', master_guide: 'Guía Mayor' })[t] ?? t

const attendanceColor = (pct) => {
    if (pct === null || pct === undefined) return '#e5e7eb'
    if (pct >= 75) return '#22c55e'
    if (pct >= 50) return '#facc15'
    return '#f87171'
}

const attendanceTextColor = (pct) => {
    if (pct === null || pct === undefined) return 'text-gray-400'
    if (pct >= 75) return 'text-green-700'
    if (pct >= 50) return 'text-yellow-700'
    return 'text-red-700'
}

const levelLabel = computed(() => ({
    union: 'Asociaciones', association: 'Distritos', district: 'Clubes', club: 'Sesiones',
})[props.level] ?? '')

const rowIsDrillable = computed(() => props.level !== 'club')

const showConReporteModal = ref(false)

const rowSearch = ref('')
const filteredRows = computed(() => {
    const q = rowSearch.value.trim().toLowerCase()
    if (!q) return props.rows
    return props.rows.filter(r => r.name?.toLowerCase().includes(q) || r.club_name?.toLowerCase().includes(q))
})

const monthLabel = computed(() => months.find(m => m.value === props.month)?.label ?? '')
</script>

<template>
    <PathfinderLayout>
        <template #title>Reporte de asistencia</template>

        <div class="space-y-5">

            <!-- Header card -->
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ union.name }}</h2>
                        <p class="mt-0.5 text-sm text-gray-500">
                            Asistencia mensual promedio por club ·
                            <span class="font-medium text-gray-700">{{ monthLabel }} {{ year }}</span>
                        </p>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                        @click="downloadCsv"
                    >
                        ↓ Descargar CSV
                    </button>
                </div>

                <!-- Month / Year filter -->
                <div class="mt-4 flex flex-wrap items-end gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Mes</label>
                        <select
                            v-model="selectedMonth"
                            class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                        >
                            <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Año</label>
                        <select
                            v-model="selectedYear"
                            class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                        >
                            <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
                        </select>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800"
                        @click="applyFilter"
                    >
                        Consultar
                    </button>
                </div>

                <!-- Breadcrumb -->
                <nav v-if="breadcrumb.length > 1" class="mt-4 flex flex-wrap items-center gap-1 text-sm">
                    <template v-for="(crumb, i) in breadcrumb" :key="i">
                        <span v-if="i > 0" class="text-gray-300">/</span>
                        <button
                            type="button"
                            :class="[
                                'rounded px-1.5 py-0.5 transition-colors',
                                i === breadcrumb.length - 1
                                    ? 'font-semibold text-gray-900 cursor-default'
                                    : 'text-blue-600 hover:underline',
                            ]"
                            :disabled="i === breadcrumb.length - 1"
                            @click="navToCrumb(crumb)"
                        >
                            {{ crumb.label }}
                        </button>
                    </template>
                </nav>
            </div>

            <!-- ── Rows (union / association / district level) ── -->
            <div v-if="level !== 'club'" class="space-y-3">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-semibold text-gray-700">{{ levelLabel }}</p>
                    <input
                        v-model="rowSearch"
                        type="search"
                        placeholder="Buscar…"
                        class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 w-48"
                    />
                </div>

                <div v-if="!filteredRows.length" class="rounded-2xl border border-dashed border-gray-200 p-8 text-center text-sm text-gray-400">
                    Sin datos para el período seleccionado.
                </div>

                <div v-else class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    {{ level === 'district' ? 'Club' : (level === 'association' ? 'Distrito' : 'Asociación') }}
                                </th>
                                <th v-if="level === 'district'" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Tipo</th>
                                <th v-if="level !== 'district'" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Clubes</th>
                                <th v-if="level !== 'district'" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    <span class="inline-flex items-center justify-end gap-1">
                                        Con reporte
                                        <button type="button" class="text-gray-400 hover:text-blue-600 transition-colors" @click.stop="showConReporteModal = true">
                                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                            </svg>
                                        </button>
                                    </span>
                                </th>
                                <th v-if="level === 'district'" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Inscritos</th>
                                <th v-if="level === 'district'" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Sesiones</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 min-w-[180px]">Asistencia promedio</th>
                                <th class="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr
                                v-for="row in filteredRows"
                                :key="row.id ?? row.club_id"
                                class="group transition-colors"
                                :class="rowIsDrillable ? 'hover:bg-blue-50 cursor-pointer' : 'hover:bg-gray-50'"
                                @click="rowIsDrillable && drillInto(row)"
                            >
                                <td class="px-6 py-4">
                                    <p class="text-sm font-medium text-gray-900">{{ row.name ?? row.club_name }}</p>
                                </td>
                                <td v-if="level === 'district'" class="px-4 py-4">
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ clubTypeLabel(row.club_type) }}</span>
                                </td>
                                <td v-if="level !== 'district'" class="px-4 py-4 text-right text-sm text-gray-600">{{ row.total_clubs ?? '—' }}</td>
                                <td v-if="level !== 'district'" class="px-4 py-4 text-right text-sm text-gray-600">{{ row.clubs_reporting ?? '—' }}</td>
                                <td v-if="level === 'district'" class="px-4 py-4 text-right text-sm text-gray-600">{{ row.enrolled ?? 0 }}</td>
                                <td v-if="level === 'district'" class="px-4 py-4 text-right text-sm text-gray-600">{{ row.session_count ?? 0 }}</td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="flex-1 h-2.5 rounded-full bg-gray-100 overflow-hidden">
                                            <div
                                                class="h-2.5 rounded-full transition-all duration-500"
                                                :style="{ width: (row.avg_attendance_pct != null ? row.avg_attendance_pct : 0) + '%', backgroundColor: attendanceColor(row.avg_attendance_pct) }"
                                            />
                                        </div>
                                        <span :class="['text-sm font-semibold w-12 text-right shrink-0', attendanceTextColor(row.avg_attendance_pct)]">
                                            {{ row.avg_attendance_pct != null ? row.avg_attendance_pct + '%' : '—' }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-right">
                                    <span
                                        v-if="rowIsDrillable"
                                        class="text-xs text-blue-500 opacity-0 group-hover:opacity-100 transition-opacity select-none"
                                    >
                                        Ver detalle →
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── Sessions (club level) ── -->
            <div v-else class="space-y-3">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-semibold text-gray-700">
                            {{ current_entity?.name }}
                            <span v-if="current_entity?.club_type" class="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-normal text-gray-500">
                                {{ clubTypeLabel(current_entity.club_type) }}
                            </span>
                        </p>
                        <p class="text-xs text-gray-400">{{ sessions.length }} sesiones en {{ monthLabel }} {{ year }}</p>
                    </div>
                </div>

                <div v-if="!sessions.length" class="rounded-2xl border border-dashed border-gray-200 p-8 text-center text-sm text-gray-400">
                    Sin sesiones registradas para este período.
                </div>

                <div v-else class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Fecha</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Inscritos</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Presentes</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 min-w-[180px]">Asistencia</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="(s, i) in sessions" :key="i" class="hover:bg-gray-50">
                                <td class="px-6 py-3 text-sm font-medium text-gray-900">{{ s.date }}</td>
                                <td class="px-4 py-3 text-right text-sm text-gray-600">{{ s.enrolled }}</td>
                                <td class="px-4 py-3 text-right text-sm text-gray-700 font-semibold">{{ s.present }}</td>
                                <td class="px-6 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="flex-1 h-2.5 rounded-full bg-gray-100 overflow-hidden">
                                            <div
                                                class="h-2.5 rounded-full transition-all duration-500"
                                                :style="{ width: (s.attendance_pct != null ? s.attendance_pct : 0) + '%', backgroundColor: attendanceColor(s.attendance_pct) }"
                                            />
                                        </div>
                                        <span :class="['text-sm font-semibold w-12 text-right shrink-0', attendanceTextColor(s.attendance_pct)]">
                                            {{ s.attendance_pct != null ? s.attendance_pct + '%' : '—' }}
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="bg-gray-50 border-t border-gray-200">
                            <tr>
                                <td class="px-6 py-3 text-xs font-semibold text-gray-500 uppercase">Promedio</td>
                                <td class="px-4 py-3 text-right text-sm text-gray-600">{{ sessions[0]?.enrolled ?? '—' }}</td>
                                <td class="px-4 py-3 text-right text-sm font-semibold text-gray-700">
                                    {{ sessions.length ? Math.round(sessions.reduce((s, r) => s + r.present, 0) / sessions.length) : '—' }}
                                </td>
                                <td class="px-6 py-3">
                                    <span :class="['text-sm font-semibold', attendanceTextColor(sessions.length ? Math.round(sessions.reduce((s,r) => s + (r.attendance_pct ?? 0), 0) / sessions.length) : null)]">
                                        {{ sessions.length
                                            ? Math.round(sessions.reduce((s, r) => s + (r.attendance_pct ?? 0), 0) / sessions.length) + '%'
                                            : '—' }}
                                    </span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

        </div>

        <!-- Con reporte info modal -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition ease-out duration-150"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition ease-in duration-100"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="showConReporteModal" class="fixed inset-0 z-50 flex items-center justify-center p-4" @click.self="showConReporteModal = false">
                    <div class="absolute inset-0 bg-black/30" @click="showConReporteModal = false" />
                    <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-xl border border-gray-200 p-6">
                        <div class="flex items-start justify-between gap-4 mb-4">
                            <div class="flex items-center gap-2">
                                <div class="flex-shrink-0 w-8 h-8 rounded-full bg-blue-50 flex items-center justify-center">
                                    <svg class="w-4 h-4 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <h3 class="text-sm font-semibold text-gray-900">¿Qué es "Con reporte"?</h3>
                            </div>
                            <button type="button" class="text-gray-400 hover:text-gray-600 transition-colors" @click="showConReporteModal = false">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <p class="text-sm text-gray-600 leading-relaxed">
                            Indica cuántos clubes registraron al menos una sesión de asistencia durante el mes seleccionado.
                        </p>
                        <ul class="mt-3 space-y-2 text-sm text-gray-500">
                            <li class="flex items-start gap-2">
                                <span class="mt-0.5 w-1.5 h-1.5 rounded-full bg-blue-400 shrink-0 mt-1.5" />
                                Un promedio bajo puede significar mala asistencia <em>o</em> simplemente que pocos clubes enviaron su reporte.
                            </li>
                            <li class="flex items-start gap-2">
                                <span class="mt-0.5 w-1.5 h-1.5 rounded-full bg-blue-400 shrink-0 mt-1.5" />
                                Compare este número con el total de clubes para evaluar la cobertura del reporte.
                            </li>
                        </ul>
                        <div class="mt-5 text-right">
                            <button
                                type="button"
                                class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 transition-colors"
                                @click="showConReporteModal = false"
                            >
                                Entendido
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

    </PathfinderLayout>
</template>
