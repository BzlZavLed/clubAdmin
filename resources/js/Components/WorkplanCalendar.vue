<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

const props = defineProps({
    events: {
        type: Array,
        default: () => []
    },
    isReadOnly: {
        type: Boolean,
        default: false
    },
    canAdd: {
        type: Boolean,
        default: false
    },
    initialDate: {
        type: String,
        default: () => new Date().toISOString().slice(0, 10)
    },
    pdfHref: {
        type: String,
        default: ''
    },
    icsHref: {
        type: String,
        default: ''
    }
})

const emit = defineEmits(['add', 'edit'])

const monthCursor = ref(props.initialDate || new Date().toISOString().slice(0, 10))
const windowWidth = ref(typeof window !== 'undefined' ? window.innerWidth : 1024)
const todayIso = new Date().toISOString().slice(0, 10)

const isMobile = computed(() => windowWidth.value < 768)

const eventsByDate = computed(() => {
    const grouped = {}
    for (const ev of props.events) {
        const start = normalizeDate(ev.date)
        const end = normalizeDate(ev.end_date || ev.date)
        if (!start) continue
        const occurrences = expandDateRange(start, end)
        const isMultiDay = Boolean(end && start && end !== start)
        occurrences.forEach((dateStr) => {
            if (!grouped[dateStr]) grouped[dateStr] = []
            grouped[dateStr].push({
                ...ev,
                _occurrence_key: `${ev.id || 'event'}-${dateStr}`,
                _occurrence_date: dateStr,
                _range_start: dateStr === start,
                _range_end: dateStr === end,
                _is_multi_day: isMultiDay,
            })
        })
    }
    Object.values(grouped).forEach(list => list.sort((a, b) => {
        if (a.start_time === b.start_time) return (a.title || '').localeCompare(b.title || '')
        if (!a.start_time) return 1
        if (!b.start_time) return -1
        return a.start_time.localeCompare(b.start_time)
    }))
    return grouped
})

const monthLabel = computed(() => {
    const date = new Date(monthCursor.value)
    return date.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })
})

const weekLabel = computed(() => {
    const start = startOfWeek(new Date(monthCursor.value))
    const end = new Date(start)
    end.setDate(start.getDate() + 6)
    const fmt = (d) => d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
    return `${fmt(start)} – ${fmt(end)}`
})

const periodLabel = computed(() => isMobile.value ? weekLabel.value : monthLabel.value)
const hasDownloads = computed(() => Boolean(props.pdfHref || props.icsHref))

const calendarCells = computed(() => {
    const date = new Date(monthCursor.value)
    const year = date.getFullYear()
    const monthIndex = date.getMonth()
    const firstDay = new Date(year, monthIndex, 1)
    const startDay = firstDay.getDay()
    const daysInMonth = new Date(year, monthIndex + 1, 0).getDate()
    const cells = []
    for (let i = 0; i < startDay; i++) cells.push(null)
    for (let day = 1; day <= daysInMonth; day++) {
        const dateStr = formatDate(year, monthIndex + 1, day)
        cells.push({
            label: day,
            date: dateStr,
            events: eventsByDate.value[dateStr] ?? []
        })
    }
    return cells
})

const weekCells = computed(() => {
    const start = startOfWeek(new Date(monthCursor.value))
    const cells = []
    for (let i = 0; i < 7; i++) {
        const d = new Date(start)
        d.setDate(start.getDate() + i)
        const dateStr = formatDate(d.getFullYear(), d.getMonth() + 1, d.getDate())
        cells.push({
            label: d.getDate(),
            date: dateStr,
            events: eventsByDate.value[dateStr] ?? []
        })
    }
    return cells
})

function normalizeDate(val) {
    if (!val) return ''
    const raw = String(val)
    return raw.includes('T') ? raw.slice(0, 10) : raw
}

function formatDate(year, month, day) {
    const m = String(month).padStart(2, '0')
    const d = String(day).padStart(2, '0')
    return `${year}-${m}-${d}`
}

function expandDateRange(startStr, endStr) {
    if (!startStr) return []
    const start = new Date(`${startStr}T00:00:00`)
    const end = new Date(`${endStr || startStr}T00:00:00`)
    if (Number.isNaN(start.getTime())) return []
    if (Number.isNaN(end.getTime()) || end < start) return [startStr]
    const dates = []
    const cursor = new Date(start)
    while (cursor <= end) {
        dates.push(formatDate(cursor.getFullYear(), cursor.getMonth() + 1, cursor.getDate()))
        cursor.setDate(cursor.getDate() + 1)
    }
    return dates
}

function startOfWeek(date) {
    const d = new Date(date)
    const day = d.getDay()
    d.setDate(d.getDate() - day)
    d.setHours(0, 0, 0, 0)
    return d
}

function formatTime(val) {
    if (!val) return ''
    const [h, m] = val.split(':')
    const dt = new Date()
    dt.setHours(Number(h), Number(m))
    return dt.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
}

function formatTimeRange(ev) {
    const start = ev?.start_time ? formatTime(ev.start_time) : ''
    const end = ev?.end_time ? formatTime(ev.end_time) : ''
    if (start && end) return `${start} - ${end}`
    return start || end || ''
}

function formatRangeLabel(ev) {
    if (!ev?._is_multi_day || !ev?._range_start) return ''
    const end = normalizeDate(ev.end_date || ev.date)
    if (!end || end === normalizeDate(ev.date)) return ''
    return `→ ${end}`
}

function rangePillClass(ev) {
    if (!ev?._is_multi_day) return 'rounded'
    if (ev._range_start && ev._range_end) return 'rounded'
    if (ev._range_start) return 'rounded-l'
    if (ev._range_end) return 'rounded-r'
    return 'rounded-none'
}

function rangeBarClass(ev) {
    return `${badgeColor(ev.meeting_type)} ${rangePillClass(ev)}`
}

function isToday(dateStr) {
    return normalizeDate(dateStr) === todayIso
}

function badgeColor(type) {
    if (type === 'sabbath') return 'bg-indigo-100 text-indigo-800'
    if (type === 'sunday') return 'bg-teal-100 text-teal-800'
    return 'bg-amber-100 text-amber-800'
}

function shiftMonth(delta) {
    const dt = new Date(monthCursor.value)
    dt.setDate(1)
    dt.setMonth(dt.getMonth() + delta)
    monthCursor.value = dt.toISOString().slice(0, 10)
}

function shiftWeek(delta) {
    const dt = new Date(monthCursor.value)
    dt.setDate(dt.getDate() + delta * 7)
    monthCursor.value = dt.toISOString().slice(0, 10)
}

const localizedDateLabel = (dateStr) => {
    return new Date(dateStr).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })
}

function handleCellClick(cell, event) {
    if (!cell || !props.canAdd || props.isReadOnly) return
    const target = event?.target
    if (target?.closest?.('[data-calendar-action]')) return
    emit('add', cell.date)
}

onMounted(() => {
    const handler = () => { windowWidth.value = window.innerWidth }
    handler()
    window.addEventListener('resize', handler)
    onBeforeUnmount(() => window.removeEventListener('resize', handler))
})

watch(() => props.events, (newVal) => {
    if (!newVal?.length) return
    if (!monthCursor.value) {
        monthCursor.value = normalizeDate(newVal[0].date)
    }
}, { immediate: true })
</script>

<template>
    <div class="bg-white shadow-sm rounded-lg p-4 border overflow-x-auto">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-2 gap-2">
            <h2 class="font-semibold text-gray-800">Calendar</h2>
            <div class="flex flex-wrap items-center gap-2 justify-end">
                <div v-if="hasDownloads" class="flex items-center gap-2">
                    <a
                        v-if="props.pdfHref"
                        :href="props.pdfHref"
                        target="_blank"
                        class="px-2 py-1 border rounded text-sm text-gray-800 hover:bg-gray-50"
                    >
                        PDF
                    </a>
                    <a
                        v-if="props.icsHref"
                        :href="props.icsHref"
                        target="_blank"
                        class="px-2 py-1 border rounded text-sm text-gray-800 hover:bg-gray-50"
                    >
                        ICS
                    </a>
                </div>
                <div class="flex items-center gap-2">
                    <button class="px-2 py-1 border rounded" @click="isMobile ? shiftWeek(-1) : shiftMonth(-1)">Prev</button>
                    <button class="px-2 py-1 border rounded" @click="isMobile ? shiftWeek(1) : shiftMonth(1)">Next</button>
                </div>
            </div>
        </div>
        <div class="mb-3 text-sm font-medium text-gray-700">{{ periodLabel }}</div>

        <!-- Mobile: week view cards -->
        <div v-if="isMobile" class="space-y-2 w-full">
            <div class="grid grid-cols-1 gap-2 w-full">
                <div
                    v-for="(cell, idx) in weekCells"
                    :key="idx"
                    class="border rounded-lg p-3 bg-white"
                    :class="isToday(cell.date) && 'ring-2 ring-blue-300 bg-blue-50/40'"
                    @click="handleCellClick(cell, $event)"
                >
                    <div class="flex items-center justify-between text-[12px] text-gray-700 mb-1">
                        <span class="font-semibold text-gray-900">
                            {{ localizedDateLabel(cell.date) }}
                            <span v-if="isToday(cell.date)" class="ml-1 inline-block w-2 h-2 rounded-full bg-blue-500"></span>
                        </span>
                        <button v-if="canAdd && !isReadOnly" class="text-blue-600 text-sm" data-calendar-action @click.stop="emit('add', cell.date)">+</button>
                    </div>
                    <div class="space-y-1">
                        <div
                            v-for="ev in cell.events"
                            :key="ev._occurrence_key || ev.id"
                            class="text-[11px] px-2 py-1 border cursor-pointer"
                            :class="rangeBarClass(ev)"
                            data-calendar-action
                            @click="emit('edit', ev)"
                        >
                            <div v-if="!ev._is_multi_day || ev._range_start">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-semibold truncate">{{ ev.title }}</span>
                                    <span
                                        v-if="ev.is_generated"
                                        class="inline-flex items-center justify-center text-[10px] font-semibold w-5 h-5 rounded-full border border-black text-black bg-white"
                                    >
                                        A
                                    </span>
                                </div>
                                <div class="text-[10px] text-gray-700 leading-tight truncate min-w-0">
                                    {{ formatTimeRange(ev) }} <span v-if="formatRangeLabel(ev)">{{ formatRangeLabel(ev) }}</span>
                                </div>
                            </div>
                            <div v-else class="h-2"></div>
                        </div>
                        <div v-if="cell.events.length === 0" class="text-[11px] text-gray-500">No events</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Desktop: month grid -->
        <div v-else class="min-w-[720px]">
            <div class="grid grid-cols-7 text-[11px] font-semibold text-gray-500 mb-2">
                <div class="text-center">Sun</div>
                <div class="text-center">Mon</div>
                <div class="text-center">Tue</div>
                <div class="text-center">Wed</div>
                <div class="text-center">Thu</div>
                <div class="text-center">Fri</div>
                <div class="text-center">Sat</div>
            </div>
            <div class="grid grid-cols-7 gap-2">
                <div
                    v-for="(cell, idx) in calendarCells"
                    :key="idx"
                    class="min-h-[95px] sm:min-h-[110px] border rounded-lg p-2 bg-white"
                    :class="cell?.date && isToday(cell.date) && 'ring-2 ring-blue-300 bg-blue-50/40'"
                    @click="handleCellClick(cell, $event)"
                >
                    <div v-if="cell" class="flex flex-col h-full">
                        <div class="flex items-center justify-between text-[11px] text-gray-500 mb-1">
                            <span class="font-medium text-gray-800">
                                {{ cell.label }}
                                <span v-if="isToday(cell.date)" class="ml-1 inline-block w-2 h-2 rounded-full bg-blue-500"></span>
                            </span>
                            <button v-if="canAdd && !isReadOnly" class="text-blue-600" data-calendar-action @click.stop="emit('add', cell.date)">+</button>
                        </div>
                        <div class="space-y-1">
                            <div
                                v-for="ev in cell.events"
                                :key="ev._occurrence_key || ev.id"
                                class="text-[11px] sm:text-xs px-2 py-1 border cursor-pointer"
                                :class="rangeBarClass(ev)"
                                data-calendar-action
                                @click="emit('edit', ev)"
                            >
                                <div v-if="!ev._is_multi_day || ev._range_start">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-semibold truncate">{{ ev.title }}</span>
                                        <span
                                            v-if="ev.is_generated"
                                            class="inline-flex items-center justify-center text-[10px] font-semibold w-5 h-5 rounded-full border border-black text-black bg-white"
                                        >
                                            A
                                        </span>
                                    </div>
                                    <div class="text-[10px] sm:text-[11px] text-gray-700 leading-tight truncate min-w-0">
                                        {{ formatTimeRange(ev) }} <span v-if="formatRangeLabel(ev)">{{ formatRangeLabel(ev) }}</span>
                                    </div>
                                </div>
                                <div v-else class="h-2"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
