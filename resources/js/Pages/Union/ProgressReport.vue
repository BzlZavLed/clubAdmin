<script setup>
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

const props = defineProps({
    union:              { type: Object, required: true },
    level:              { type: String, default: 'union' },
    club_type_filter:   { type: String, default: null },
    carpeta_year:       { type: Object, default: null },
    breadcrumb:         { type: Array, default: () => [] },
    rows:               { type: Array, default: () => [] },
    members:             { type: Array, default: () => [] },
    current_entity:      { type: Object, default: null },
    parent_entity:       { type: Object, default: null },
    grandparent_entity:  { type: Object, default: null },
    requirements_report: { type: Array, default: () => [] },
})

// ── Club type filter ──────────────────────────────────────────
const clubTypes = [
    { value: null,           label: 'Todos' },
    { value: 'pathfinders',  label: 'Conquistadores' },
    { value: 'adventurers',  label: 'Aventureros' },
    { value: 'master_guide', label: 'Guías Mayores' },
]

const selectedType = ref(props.club_type_filter ?? null)
const openSections = ref({
    progress: true,
    requirements: true,
})

const toggleSection = (key) => {
    openSections.value[key] = !openSections.value[key]
}

const isSectionOpen = (key) => openSections.value[key] !== false

const applyFilter = (type) => {
    selectedType.value = type
    const params = currentParams()
    if (type) params.club_type = type
    else delete params.club_type
    router.get(route('union.reports.progress'), params, { preserveScroll: true })
}

// ── Navigation helpers ────────────────────────────────────────
const currentParams = () => {
    const p = {}
    if (props.level !== 'union') p.level = props.level
    if (props.current_entity) {
        if (props.level === 'association') p.association_id = props.current_entity.id
        if (props.level === 'district')    p.district_id   = props.current_entity.id
        if (props.level === 'club')        p.club_id       = props.current_entity.id
    }
    if (props.parent_entity) {
        if (props.level === 'district') p.association_id = props.parent_entity.id
        if (props.level === 'club')     p.district_id    = props.parent_entity.id
    }
    if (props.grandparent_entity && props.level === 'club') {
        p.association_id = props.grandparent_entity.id
    }
    if (selectedType.value) p.club_type = selectedType.value
    return p
}

const drillInto = (row) => {
    const levelMap = { union: 'association', association: 'district', district: 'club' }
    const idKey    = { union: 'association_id', association: 'district_id', district: 'club_id' }
    const nextLevel = levelMap[props.level]
    if (!nextLevel) return

    const params = { level: nextLevel, [idKey[props.level]]: row.id }

    if (props.level === 'association') {
        params.association_id = props.current_entity?.id ?? row.id
        params.district_id    = row.id
    }
    if (props.level === 'district') {
        params.association_id = props.parent_entity?.id
        params.district_id    = props.current_entity?.id
        params.club_id        = row.id
    }
    if (props.level === 'union') {
        params.association_id = row.id
    }

    if (selectedType.value) params.club_type = selectedType.value
    router.get(route('union.reports.progress'), params, { preserveScroll: true })
}

const navToCrumb = (crumb) => {
    if (crumb.level === 'union') {
        router.get(route('union.reports.progress'), selectedType.value ? { club_type: selectedType.value } : {})
        return
    }
    const params = { level: crumb.level, ...crumb.params }
    if (selectedType.value) params.club_type = selectedType.value
    router.get(route('union.reports.progress'), params)
}

const downloadCsv = () => {
    const params = { ...currentParams() }
    const qs = new URLSearchParams(params).toString()
    window.location.href = route('union.reports.progress.csv') + (qs ? '?' + qs : '')
}

// ── Display helpers ────────────────────────────────────────────
const clubTypeLabel = (t) => ({ adventurers: 'Aventureros', pathfinders: 'Conquistadores', master_guide: 'Guía Mayor' })[t] ?? t

const progressBarColor = (pct) => {
    if (pct === null || pct === undefined) return '#e5e7eb'
    if (pct >= 75) return '#22c55e'
    if (pct >= 45) return '#facc15'
    return '#f87171'
}

const progressTextColor = (pct) => {
    if (pct === null || pct === undefined) return 'text-gray-400'
    if (pct >= 75) return 'text-green-700'
    if (pct >= 45) return 'text-yellow-700'
    return 'text-red-700'
}

const levelLabel = computed(() => ({
    union: 'Asociaciones', association: 'Distritos', district: 'Clubes', club: 'Miembros',
})[props.level] ?? '')

const memberSearch = ref('')
const filteredMembers = computed(() => {
    const q = memberSearch.value.trim().toLowerCase()
    if (!q) return props.members
    return props.members.filter(m => m.name?.toLowerCase().includes(q) || m.class_name?.toLowerCase().includes(q))
})

const rowSearch = ref('')
const filteredRows = computed(() => {
    const q = rowSearch.value.trim().toLowerCase()
    if (!q) return props.rows
    return props.rows.filter(r => r.name?.toLowerCase().includes(q) || r.club_name?.toLowerCase().includes(q) || r.church_name?.toLowerCase().includes(q))
})

const canDrillIn = computed(() => props.level !== 'club')
const rowIsDrillable = canDrillIn

// ── Requirements report ───────────────────────────────────────
const reqSearch = ref('')
const reqGrouped = computed(() => {
    const q = reqSearch.value.trim().toLowerCase()
    const items = q
        ? props.requirements_report.filter(r =>
            r.title?.toLowerCase().includes(q) ||
            r.class_name?.toLowerCase().includes(q)
          )
        : props.requirements_report
    const groups = {}
    for (const r of items) {
        const k = r.club_type + '||' + r.class_name
        if (!groups[k]) groups[k] = { club_type: r.club_type, class_name: r.class_name, items: [] }
        groups[k].items.push(r)
    }
    return Object.values(groups)
})

const requirementsReportTitle = computed(() => {
    if (props.level === 'club') return 'Progreso de miembros'
    return `Progreso por ${levelLabel.value.toLowerCase()}`
})
</script>

<template>
    <PathfinderLayout>
        <template #title>Reportes de requisitos</template>

        <div class="space-y-5">

            <!-- Header card -->
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ union.name }}</h2>
                        <p class="mt-0.5 text-sm text-gray-500">
                            Reportes de requisitos de carpeta de investidura
                            <span v-if="carpeta_year" class="font-medium text-gray-700"> · {{ carpeta_year.year }}</span>
                        </p>
                        <div v-if="!carpeta_year" class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                            No hay un ciclo de carpeta publicado. Publique un ciclo anual para ver el progreso.
                        </div>
                    </div>
                    <button
                        v-if="carpeta_year"
                        type="button"
                        class="shrink-0 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                        @click="downloadCsv"
                    >
                        ↓ Descargar CSV
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

            <template v-if="carpeta_year">

                <!-- Club type filter tabs -->
                <div class="flex flex-wrap gap-1">
                    <button
                        v-for="ct in clubTypes"
                        :key="ct.value ?? 'all'"
                        type="button"
                        :class="[
                            'rounded-full px-3 py-1 text-xs font-medium transition-colors border',
                            (selectedType === ct.value)
                                ? 'bg-red-700 text-white border-red-700'
                                : 'bg-white text-gray-600 border-gray-300 hover:border-red-400 hover:text-red-700',
                        ]"
                        @click="applyFilter(ct.value)"
                    >
                        {{ ct.label }}
                    </button>
                </div>

                <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-4 px-5 py-4 text-left hover:bg-gray-50"
                        @click="toggleSection('progress')"
                    >
                        <div>
                            <p class="text-sm font-semibold text-gray-800">{{ requirementsReportTitle }}</p>
                            <p class="text-xs text-gray-400">
                                {{ level === 'club' ? `${members.length} miembros con clase asignada` : `${rows.length} registros disponibles` }}
                            </p>
                        </div>
                        <span class="text-lg leading-none text-gray-400">{{ isSectionOpen('progress') ? '−' : '+' }}</span>
                    </button>

                    <div v-show="isSectionOpen('progress')" class="border-t border-gray-100 p-5">
                        <!-- ── Rows (union / association / district level) ── -->
                        <div v-if="level !== 'club'" class="space-y-3">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-semibold text-gray-700">{{ levelLabel }}</p>
                                <input
                                    v-model="rowSearch"
                                    type="search"
                                    placeholder="Buscar…"
                                    class="w-48 rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                />
                            </div>

                            <div v-if="!filteredRows.length" class="rounded-2xl border border-dashed border-gray-200 p-8 text-center text-sm text-gray-400">
                                Sin datos.
                            </div>

                            <div v-else class="overflow-hidden rounded-2xl border border-gray-200 bg-white">
                                <table class="min-w-full divide-y divide-gray-100">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                {{ level === 'district' ? 'Club' : (level === 'association' ? 'Distrito' : 'Asociación') }}
                                            </th>
                                            <th v-if="level === 'district'" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Tipo</th>
                                            <th v-if="level !== 'district'" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Clubes</th>
                                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Miembros</th>
                                            <th class="min-w-[180px] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Progreso</th>
                                            <th class="px-4 py-3" />
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr
                                            v-for="row in filteredRows"
                                            :key="row.id"
                                            class="group transition-colors"
                                            :class="rowIsDrillable ? 'hover:bg-blue-50 cursor-pointer' : 'hover:bg-gray-50'"
                                            @click="rowIsDrillable && drillInto(row)"
                                        >
                                            <td class="px-6 py-4">
                                                <p class="text-sm font-medium text-gray-900">{{ row.name ?? row.club_name }}</p>
                                                <p v-if="row.church_name" class="text-xs text-gray-400">{{ row.church_name }}</p>
                                            </td>
                                            <td v-if="level === 'district'" class="px-4 py-4">
                                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ clubTypeLabel(row.club_type) }}</span>
                                            </td>
                                            <td v-if="level !== 'district'" class="px-4 py-4 text-right text-sm text-gray-600">
                                                {{ row.total_clubs ?? '—' }}
                                            </td>
                                            <td class="px-4 py-4 text-right text-sm text-gray-600">{{ row.member_count ?? row.total_members ?? 0 }}</td>
                                            <td class="px-6 py-4">
                                                <div class="flex items-center gap-3">
                                                    <div class="h-2.5 flex-1 overflow-hidden rounded-full bg-gray-100">
                                                        <div
                                                            class="h-2.5 rounded-full transition-all duration-500"
                                                            :style="{ width: (row.progress_pct != null ? row.progress_pct : 0) + '%', backgroundColor: progressBarColor(row.progress_pct) }"
                                                        />
                                                    </div>
                                                    <span :class="['text-sm font-semibold w-12 text-right shrink-0', progressTextColor(row.progress_pct)]">
                                                        {{ row.progress_pct !== null && row.progress_pct !== undefined ? row.progress_pct + '%' : '—' }}
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="px-4 py-4 text-right">
                                                <span
                                                    v-if="rowIsDrillable"
                                                    class="select-none text-xs text-blue-500 opacity-0 transition-opacity group-hover:opacity-100"
                                                >
                                                    Ver detalle →
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- ── Members (club level) ── -->
                        <div v-else class="space-y-3">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-gray-700">
                                        {{ current_entity?.name }}
                                        <span v-if="current_entity?.club_type" class="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-normal text-gray-500">
                                            {{ clubTypeLabel(current_entity.club_type) }}
                                        </span>
                                    </p>
                                    <p class="text-xs text-gray-400">{{ members.length }} miembros con clase asignada</p>
                                </div>
                                <input
                                    v-model="memberSearch"
                                    type="search"
                                    placeholder="Buscar miembro…"
                                    class="w-48 rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                />
                            </div>

                            <div v-if="!filteredMembers.length" class="rounded-2xl border border-dashed border-gray-200 p-8 text-center text-sm text-gray-400">
                                Sin miembros con clase asignada.
                            </div>

                            <div v-else class="overflow-hidden rounded-2xl border border-gray-200 bg-white">
                                <table class="min-w-full divide-y divide-gray-100">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Miembro</th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Clase</th>
                                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Completados</th>
                                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Total</th>
                                            <th class="min-w-[180px] px-6 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Progreso</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr v-for="member in filteredMembers" :key="member.id">
                                            <td class="px-6 py-3 text-sm font-medium text-gray-900">{{ member.name }}</td>
                                            <td class="px-4 py-3 text-sm text-gray-600">{{ member.class_name }}</td>
                                            <td class="px-4 py-3 text-right text-sm text-gray-700">{{ member.fulfilled }}</td>
                                            <td class="px-4 py-3 text-right text-sm text-gray-400">{{ member.total }}</td>
                                            <td class="px-6 py-3">
                                                <div class="flex items-center gap-3">
                                                    <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-100">
                                                        <div
                                                            class="h-2 rounded-full transition-all duration-500"
                                                            :style="{ width: (member.progress_pct != null ? member.progress_pct : 0) + '%', backgroundColor: progressBarColor(member.progress_pct) }"
                                                        />
                                                    </div>
                                                    <span :class="['text-sm font-semibold w-12 text-right shrink-0', progressTextColor(member.progress_pct)]">
                                                        {{ member.progress_pct !== null && member.progress_pct !== undefined ? member.progress_pct + '%' : '—' }}
                                                    </span>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ── Requirements effectiveness ── -->
                <section v-if="requirements_report.length" class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-4 px-5 py-4 text-left hover:bg-gray-50"
                        @click="toggleSection('requirements')"
                    >
                        <div>
                            <p class="text-sm font-semibold text-gray-800">Efectividad de requisitos</p>
                            <p class="text-xs text-gray-400">Cuántos miembros han completado cada requisito</p>
                        </div>
                        <span class="text-lg leading-none text-gray-400">{{ isSectionOpen('requirements') ? '−' : '+' }}</span>
                    </button>

                    <div v-show="isSectionOpen('requirements')" class="space-y-3 border-t border-gray-100 p-5">
                        <div class="flex justify-end">
                            <input
                                v-model="reqSearch"
                                type="search"
                                placeholder="Buscar requisito…"
                                class="w-52 rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            />
                        </div>

                        <div v-for="group in reqGrouped" :key="group.club_type + group.class_name" class="overflow-hidden rounded-2xl border border-gray-200 bg-white">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between gap-3 border-b border-gray-100 bg-gray-50 px-5 py-3 text-left hover:bg-gray-100"
                                @click="toggleSection(`requirements-${group.club_type}-${group.class_name}`)"
                            >
                                <span class="flex items-center gap-2">
                                    <span class="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-medium text-gray-600">{{ clubTypeLabel(group.club_type) }}</span>
                                    <span class="text-sm font-semibold text-gray-700">{{ group.class_name }}</span>
                                    <span class="text-xs text-gray-400">({{ group.items.length }})</span>
                                </span>
                                <span class="text-lg leading-none text-gray-400">
                                    {{ isSectionOpen(`requirements-${group.club_type}-${group.class_name}`) ? '−' : '+' }}
                                </span>
                            </button>
                            <div v-show="isSectionOpen(`requirements-${group.club_type}-${group.class_name}`)" class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-100">
                                    <thead class="bg-gray-50/50">
                                        <tr>
                                            <th class="px-5 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Requisito</th>
                                            <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Completaron</th>
                                            <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Total</th>
                                            <th class="min-w-[160px] px-5 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Adopción</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr v-for="req in group.items" :key="req.id" class="hover:bg-gray-50">
                                            <td class="px-5 py-3 text-sm text-gray-800">{{ req.title }}</td>
                                            <td class="px-4 py-3 text-right text-sm font-semibold text-gray-700">{{ req.completed }}</td>
                                            <td class="px-4 py-3 text-right text-sm text-gray-400">{{ req.total_members }}</td>
                                            <td class="px-5 py-3">
                                                <div class="flex items-center gap-2">
                                                    <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-100">
                                                        <div
                                                            class="h-2 rounded-full transition-all duration-500"
                                                            :style="{ width: (req.pct != null ? req.pct : 0) + '%', backgroundColor: progressBarColor(req.pct) }"
                                                        />
                                                    </div>
                                                    <span :class="['text-xs font-semibold w-10 text-right shrink-0', progressTextColor(req.pct)]">
                                                        {{ req.pct != null ? req.pct + '%' : '—' }}
                                                    </span>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>

            </template>

        </div>
    </PathfinderLayout>
</template>
