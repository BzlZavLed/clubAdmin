<script setup>
import { computed, ref, onMounted, onBeforeUnmount, watch } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useGeneral } from '@/Composables/useGeneral'
import { useLocale } from '@/Composables/useLocale'
import {
    previewWorkplan,
    confirmWorkplan,
    createWorkplanEvent,
    updateWorkplanEvent,
    deleteWorkplanEvent,
    createClassPlan,
    updateClassPlanStatus,
    fetchStaffRecord,
    updateClassPlan,
    exportWorkplanToMyChurchAdmin,
    deleteWorkplan
} from '@/Services/api'
import { ArrowDownTrayIcon, CalendarDaysIcon, TableCellsIcon } from '@heroicons/vue/24/outline'
import WorkplanCalendar from '@/Components/WorkplanCalendar.vue'

const props = defineProps({
    auth_user: Object,
    workplan: Object,
    clubs: {
        type: Array,
        default: () => []
    },
    selected_club_id: {
        type: [String, Number, null],
        default: null
    },
    integration_config: {
        type: Object,
        default: null
    },
    inherited_events: {
        type: Array,
        default: () => []
    },
    local_objectives: {
        type: Array,
        default: () => []
    },
    class_requirements_by_class: {
        type: Object,
        default: () => ({})
    }
})
const { showToast } = useGeneral()
const { tr } = useLocale()

const isDirector = computed(() => props.auth_user?.profile_type === 'club_director')
const isSuperadmin = computed(() => props.auth_user?.profile_type === 'superadmin')
const isStaff = computed(() => props.auth_user?.profile_type === 'club_personal')
const isReadOnly = computed(() => !isDirector.value)
const canSelectClub = computed(() => props.clubs?.length > 1)
const selectedClubId = ref(props.selected_club_id || props.auth_user?.club_id || (props.clubs?.[0]?.id ?? ''))
const hasClubSelected = computed(() => Boolean(selectedClubId.value))

const defaultTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone
const todayIso = new Date().toISOString().slice(0, 10)

const trimTime = (val) => (val ? String(val).slice(0, 5) : '')

const baseForm = () => ({
    start_date: props.workplan?.start_date ?? todayIso,
    end_date: props.workplan?.end_date ?? todayIso,
    timezone: props.workplan?.timezone ?? defaultTimeZone,
    default_sabbath_location: props.workplan?.default_sabbath_location ?? '',
    default_sunday_location: props.workplan?.default_sunday_location ?? '',
    default_sabbath_start_time: trimTime(props.workplan?.default_sabbath_start_time),
    default_sabbath_end_time: trimTime(props.workplan?.default_sabbath_end_time),
    default_sunday_start_time: trimTime(props.workplan?.default_sunday_start_time),
    default_sunday_end_time: trimTime(props.workplan?.default_sunday_end_time)
})

const form = ref(baseForm())
const recurrence = ref({
    sabbath: (props.workplan?.rules ?? []).filter(r => r.meeting_type === 'sabbath').map(r => r.nth_week),
    sunday: (props.workplan?.rules ?? []).filter(r => r.meeting_type === 'sunday').map(r => r.nth_week)
})

if (recurrence.value.sabbath.length === 0 && recurrence.value.sunday.length === 0) {
    recurrence.value.sabbath = [1]
}

const normalizeEvents = (list = []) => list.map(ev => {
    const sourceType = ev.source_type || ''
    const isInherited = sourceType.includes('AssociationWorkplanEvent') || sourceType.includes('DistrictWorkplanEvent')
    return {
        ...ev,
        classPlans: ev.classPlans || ev.class_plans || [],
        _inherited: isInherited,
        _source_level: sourceType.includes('District') ? 'district' : (sourceType.includes('Association') ? 'association' : null),
    }
})

const events = ref(normalizeEvents(props.workplan?.events ?? []))
const windowWidth = ref(typeof window !== 'undefined' ? window.innerWidth : 1024)
const currentPage = ref(1)
const perPage = 10

const previewDiff = ref({ adds: [], removals: [] })
const showDiffModal = ref(false)

const eventModalOpen = ref(false)
const editingEvent = ref(null)
const eventForm = ref({
    date: todayIso,
    end_date: '',
    start_time: '',
    end_time: '',
    meeting_type: 'special',
    title: '',
    description: '',
    location: '',
    department_id: null,
    objective_id: null,
    local_objective_id: null,
    objective_key: '',
})
const locationSuggestions = ref([])
const locationLoading = ref(false)
let locationTimer = null
const planLocationSuggestions = ref([])
const planLocationLoading = ref(false)
let planLocationTimer = null

const nthOptions = [1, 2, 3, 4, 5]

const isMobile = computed(() => windowWidth.value < 768)
const isExpired = computed(() => {
    if (!props.workplan?.end_date) return false
    return normalizeDate(props.workplan.end_date) < todayIso
})

const sortedEvents = computed(() => {
    const list = [...events.value]
    list.sort((a, b) => {
        const dateA = normalizeDate(a.date)
        const dateB = normalizeDate(b.date)
        if (dateA === dateB) {
            const timeA = a.start_time || ''
            const timeB = b.start_time || ''
            return timeA.localeCompare(timeB)
        }
        return dateA.localeCompare(dateB)
    })
    return list
})

const totalPages = computed(() => Math.max(1, Math.ceil(sortedEvents.value.length / perPage)))

const pagedEvents = computed(() => {
    const start = (currentPage.value - 1) * perPage
    return sortedEvents.value.slice(start, start + perPage)
})

function nextPage() {
    if (currentPage.value < totalPages.value) currentPage.value++
}

function prevPage() {
    if (currentPage.value > 1) currentPage.value--
}

watch(sortedEvents, () => {
    if (currentPage.value > totalPages.value) currentPage.value = totalPages.value
})

watch(() => eventForm.value.meeting_type, (val) => {
    if (val !== 'special') {
        eventForm.value.end_date = ''
        return
    }
    if (!eventForm.value.end_date) {
        eventForm.value.end_date = eventForm.value.date
    }
})

watch(() => eventForm.value.date, (val) => {
    if (!val || eventForm.value.meeting_type !== 'special') return
    if (!eventForm.value.end_date || eventForm.value.end_date < val) {
        eventForm.value.end_date = val
    }
})

const pdfHref = computed(() => {
    if (!hasClubSelected.value) return '#'
    const params = { club_id: selectedClubId.value }
    return isDirector.value ? route('club.workplan.pdf', params) : route('club.personal.workplan.pdf', params)
})

const icsHref = computed(() => {
    if (!hasClubSelected.value) return '#'
    const params = { club_id: selectedClubId.value }
    return isDirector.value ? route('club.workplan.ics', params) : route('club.personal.workplan.ics', params)
})

const tablePdfHref = computed(() => {
    if (!hasClubSelected.value || !isDirector.value) return '#'
    const params = { club_id: selectedClubId.value }
    return safeRoute('club.workplan.table.pdf', params, '/club-director/workplan/table-pdf')
})

const needsApprovalOnly = ref(false)
const statusFilter = ref('all')

function safeRoute(name, params = {}, fallbackPath = '#') {
    try {
        if (typeof route === 'function' && window?.Ziggy?.routes?.[name]) {
            return route(name, params)
        }
    } catch (e) {
        // swallow and use fallback
    }
    const qs = new URLSearchParams(params).toString()
    if (!fallbackPath || fallbackPath === '#') return '#'
    return qs ? `${fallbackPath}?${qs}` : fallbackPath
}

const plansPdfHref = computed(() => {
    if (!hasClubSelected.value) return '#'
    const params = { club_id: selectedClubId.value }
    const classId = isDirector.value ? classFilter.value : userClassId.value
    if (classId) params.class_id = classId
    if (needsApprovalOnly.value) params.needs_approval = 1
    if (statusFilter.value && statusFilter.value !== 'all') params.status = statusFilter.value
    if (isDirector.value) {
        return safeRoute('club.workplan.class-plans.pdf', params, '/club-director/workplan/class-plans/pdf')
    }
    if (props.auth_user?.profile_type === 'club_personal') {
        return safeRoute('club.personal.workplan.class-plans.pdf', params, '/club-personal/workplan/class-plans/pdf')
    }
    return '#'
})

const showIcsHelp = ref(false)
const selectedEvent = ref(null)
const inheritedEventModal = ref(null)
const workplanModalOpen = ref(false)

const allCalendarEvents = computed(() => [
    ...events.value,
    ...props.inherited_events,
])
const exportModalOpen = ref(false)
const exportLoading = ref(false)
const deletingWorkplan = ref(false)
const exportResponse = ref(null)
const exportError = ref(null)
const exportResponseOpen = ref(false)
const objectiveModalOpen = ref(false)
const objectiveAssignments = ref({})
const bulkObjectiveId = ref('')
const objectiveSaving = ref(false)
const objectiveTab = ref('recurrent')
const exportForm = ref({
    calendar_year: new Date().getFullYear(),
    plan_name: '',
    publish_to_feed: true,
    church_slug: '',
    department_id: ''
})

const exportSummary = computed(() => {
    const data = exportResponse.value || exportError.value
    if (!data) return null
    return data.error || data
})

const exportMessage = computed(() => {
    const data = exportResponse.value || exportError.value
    return data?.message || ''
})
const planForm = ref({
    workplan_event_id: null,
    class_id: null,
    investiture_requirement_id: null,
    type: 'plan',
    title: '',
    description: '',
    requested_date: '',
    location_override: ''
})

const planEvents = computed(() => {
    return [...events.value]
        .filter(ev => ['sabbath', 'sunday', 'special'].includes(ev.meeting_type))
        .sort((a, b) => normalizeDate(a.date).localeCompare(normalizeDate(b.date)))
})

const upcomingPlanEvents = computed(() => {
    return planEvents.value.filter(ev => normalizeDate(ev.date) >= todayIso)
})

const staffProfile = ref(null)
const editingPlanId = ref(null)
const requestModalOpen = ref(false)
const requestNote = ref('')
const requestPlan = ref(null)
const classFilter = ref('')
const userClassId = computed(() => {
    const au = props.auth_user || {}
    if (au.assigned_class_id) return au.assigned_class_id
    if (au.staff?.assigned_class) return au.staff.assigned_class
    if (au.staff_class?.id) return au.staff_class.id
    if (Array.isArray(au.assigned_classes) && au.assigned_classes.length) {
        const first = au.assigned_classes[0]
        return typeof first === 'object' ? first.id : first
    }
    if (staffProfile.value?.assigned_class) return staffProfile.value.assigned_class
    return null
})

const allPlans = computed(() => {
    const list = planEvents.value.flatMap(ev => (ev.classPlans || []).map(plan => ({
        ...plan,
        _event: ev
    })))
    return list.sort((a, b) => {
        const da = normalizeDate(a._event?.date || a.requested_date)
        const db = normalizeDate(b._event?.date || b.requested_date)
        return da.localeCompare(db)
    })
})

const classesOptions = computed(() => {
    const map = new Map()
    allPlans.value.forEach(p => {
        const id = p.class_id || p.class?.id
        const name = p.class?.class_name
        if (id && name && !map.has(id)) {
            map.set(id, name)
        }
    })
    return Array.from(map.entries()).map(([id, name]) => ({ id, name }))
})

const selectedClassRequirements = computed(() => {
    const classId = planForm.value.class_id || userClassId.value
    if (!classId) return []
    const entry = props.class_requirements_by_class?.[String(classId)]
    return entry?.requirements || []
})

const departmentsOptions = computed(() => props.integration_config?.departments || [])
const externalObjectivesOptions = computed(() =>
    (props.integration_config?.objectives || []).map(obj => ({
        ...obj,
        key: `external:${obj.id}`,
        source: 'external',
        external_objective_id: obj.id,
    }))
)
const localObjectivesOptions = computed(() =>
    (props.local_objectives || []).map(obj => ({
        ...obj,
        key: `local:${obj.id}`,
        source: 'local',
    }))
)
const objectivesOptions = computed(() => [
    ...externalObjectivesOptions.value,
    ...localObjectivesOptions.value,
])

const getObjectiveKeyFromEvent = (ev) => {
    if (ev?.local_objective_id) return `local:${ev.local_objective_id}`
    if (ev?.objective_id) return `external:${ev.objective_id}`
    return ''
}

const resolveObjectiveOption = (objectiveKey) => {
    if (!objectiveKey) return null
    return objectivesOptions.value.find(option => String(option.key) === String(objectiveKey)) || null
}

const parseObjectiveSelection = (objectiveKey) => {
    const option = resolveObjectiveOption(objectiveKey)
    if (!option) {
        return {
            objective_id: null,
            local_objective_id: null,
        }
    }

    if (option.source === 'local') {
        return {
            objective_id: option.external_objective_id || null,
            local_objective_id: option.id,
        }
    }

    return {
        objective_id: option.id,
        local_objective_id: null,
    }
}

const getEventDepartmentId = (ev) => {
    return ev.department_id || exportForm.value.department_id || ''
}

const getDepartmentName = (departmentId) => {
    const dept = departmentsOptions.value.find(d => String(d.id) === String(departmentId))
    return dept?.name || '—'
}

const objectiveMatchesDepartment = (objectiveId, departmentId) => {
    const obj = resolveObjectiveOption(objectiveId)
    if (!obj) return false
    if (obj.source === 'local' && !obj.department_id) return true
    if (!departmentId) return obj.source === 'local'
    if (obj.source === 'local' && !obj.department_id) return true
    if (!obj) return false
    return String(obj.department_id) === String(departmentId)
}

const objectivesForDepartment = (departmentId) => {
    if (!departmentId) return objectivesOptions.value
    return objectivesOptions.value.filter(o =>
        !o.department_id || String(o.department_id) === String(departmentId)
    )
}

const missingObjectiveEvents = computed(() => {
    return events.value.filter(ev => {
        const deptId = getEventDepartmentId(ev)
        const objectiveKey = getObjectiveKeyFromEvent(ev)
        if (!objectiveKey) return true
        if (!deptId) return true
        return !objectiveMatchesDepartment(objectiveKey, deptId)
    })
})

const missingRecurrentEvents = computed(() => {
    return missingObjectiveEvents.value.filter(ev => ev.meeting_type !== 'special')
})

const missingSpecialEvents = computed(() => {
    return missingObjectiveEvents.value.filter(ev => ev.meeting_type === 'special')
})

const filteredPlans = computed(() => {
    const defaultClass = props.auth_user?.staff?.assigned_class
        || props.auth_user?.staff_class?.id
        || (Array.isArray(props.auth_user?.assigned_classes) ? props.auth_user.assigned_classes[0] : null)
    const targetClass = isDirector.value ? classFilter.value : (userClassId.value || classFilter.value || defaultClass)
    return allPlans.value.filter(p => {
        if (!targetClass && !isDirector.value) return false
        if (!targetClass) return true
        const planClassId = p.class_id || p.class?.id
        return String(planClassId || '') === String(targetClass)
    }).filter(p => {
        if (needsApprovalOnly.value && !p.requires_approval) return false
        if (statusFilter.value === 'approved') return p.status === 'approved'
        if (statusFilter.value === 'rejected') return p.status === 'rejected'
        if (statusFilter.value === 'pending') return ['submitted', 'changes_requested'].includes(p.status)
        return true
    })
})

const planDetailOpen = ref(false)
const planDetail = ref(null)

const classDisplayName = computed(() => {
    const fromOptions = classesOptions.value.find(o => String(o.id) === String(userClassId.value))?.name
    if (fromOptions) return fromOptions
    if (staffProfile.value?.assigned_class_name) return staffProfile.value.assigned_class_name
    if (props.auth_user?.staff?.class?.class_name) return props.auth_user.staff.class.class_name
    if (props.auth_user?.staff_class?.class_name) return props.auth_user.staff_class.class_name
    if (Array.isArray(props.auth_user?.assigned_classes) && props.auth_user.assigned_classes.length) {
        return props.auth_user.assigned_classes[0]
    }
    return '—'
})

function normalizeDate(val) {
    if (!val) return ''
    const raw = String(val)
    return raw.includes('T') ? raw.slice(0, 10) : raw
}

function onClubChange() {
    if (!selectedClubId.value) return
    window.location.assign(
        safeRoute('club.workplan', { club_id: selectedClubId.value }, '/club-director/workplan')
    )
}

function formatTime(val) {
    if (!val) return ''
    const [h, m] = val.split(':')
    const dt = new Date()
    dt.setHours(Number(h), Number(m))
    return dt.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
}

function formatDateTime(ev) {
    if (!ev?.date) return ''
    const raw = String(ev.date)
    const datePart = raw.includes('T') ? raw.slice(0, 10) : raw
    const endRaw = ev?.end_date ? String(ev.end_date) : ''
    const endDatePart = endRaw.includes('T') ? endRaw.slice(0, 10) : endRaw
    if (endDatePart && endDatePart !== datePart) {
        return `${datePart} → ${endDatePart}`
    }
    const time = ev?.start_time ? ev.start_time.slice(0, 5) : ''
    return time ? `${datePart} ${time}` : datePart
}

function formatTimeRange(ev) {
    const start = ev?.start_time ? formatTime(ev.start_time) : ''
    const end = ev?.end_time ? formatTime(ev.end_time) : ''
    if (start && end) return `${start} - ${end}`
    return start || end || ''
}

function toggleNth(type, nth) {
    const list = recurrence.value[type]
    if (list.includes(nth)) {
        recurrence.value[type] = list.filter(n => n !== nth)
    } else {
        recurrence.value[type] = [...list, nth].sort()
    }
}

function buildRulesPayload() {
    const rules = []
    recurrence.value.sabbath.forEach(nth => rules.push({ meeting_type: 'sabbath', nth_week: nth }))
    recurrence.value.sunday.forEach(nth => rules.push({ meeting_type: 'sunday', nth_week: nth }))
    return rules
}

async function handlePreview() {
    if (!hasClubSelected.value) return
    try {
        const payload = { ...form.value, rules: buildRulesPayload() }
        const data = await previewWorkplan(payload)
        previewDiff.value = {
            adds: data.adds || [],
            removals: data.removals || []
        }
        showDiffModal.value = true
    } catch (error) {
        console.error(error)
        showToast(tr('No se pudo previsualizar los cambios', 'Could not preview changes'), 'error')
    }
}

async function applyChanges() {
    if (!hasClubSelected.value) return
    try {
        const payload = { ...form.value, rules: buildRulesPayload() }
        const { workplan } = await confirmWorkplan(payload)
        events.value = normalizeEvents(workplan.events || [])
        form.value = {
            ...form.value,
            start_date: workplan.start_date,
            end_date: workplan.end_date,
            default_sabbath_location: workplan.default_sabbath_location || '',
            default_sunday_location: workplan.default_sunday_location || '',
            default_sabbath_start_time: trimTime(workplan.default_sabbath_start_time),
            default_sabbath_end_time: trimTime(workplan.default_sabbath_end_time),
            default_sunday_start_time: trimTime(workplan.default_sunday_start_time),
            default_sunday_end_time: trimTime(workplan.default_sunday_end_time),
            timezone: workplan.timezone || form.value.timezone,
        }
        recurrence.value = {
            sabbath: (workplan.rules || []).filter(r => r.meeting_type === 'sabbath').map(r => r.nth_week),
            sunday: (workplan.rules || []).filter(r => r.meeting_type === 'sunday').map(r => r.nth_week),
        }
        showDiffModal.value = false
        showToast(tr('Plan de trabajo actualizado', 'Workplan updated'))
    } catch (error) {
        console.error(error)
        showToast(tr('No se pudieron aplicar los cambios', 'Could not apply changes'), 'error')
    }
}

function openEventModal(ev = null, date = null) {
    if (isReadOnly.value || !hasClubSelected.value) return
    editingEvent.value = ev
    const baseDate = normalizeDate(ev?.date) || date || todayIso
    eventForm.value = {
        date: baseDate,
        end_date: normalizeDate(ev?.end_date) || '',
        start_time: trimTime(ev?.start_time),
        end_time: trimTime(ev?.end_time),
        meeting_type: ev?.meeting_type || 'special',
        title: ev?.title || '',
        description: ev?.description || '',
        location: ev?.location || defaultLocation(ev?.meeting_type || 'special'),
        department_id: ev?.department_id ?? null,
        objective_id: ev?.objective_id ?? null,
        local_objective_id: ev?.local_objective_id ?? null,
        objective_key: getObjectiveKeyFromEvent(ev),
    }
    locationSuggestions.value = []
    eventModalOpen.value = true
}

function openWorkplanModal() {
    if (!hasClubSelected.value || isReadOnly.value) return
    workplanModalOpen.value = true
}

function resolveCalendarYear() {
    const raw = form.value.start_date || todayIso
    const year = Number(String(raw).slice(0, 4))
    return Number.isFinite(year) ? year : new Date().getFullYear()
}

function defaultPlanName(year) {
    const clubName = props.clubs.find(c => String(c.id) === String(selectedClubId.value))?.club_name || tr('Club', 'Club')
    return tr(`Plan anual ${clubName} ${year}`, `Annual plan ${clubName} ${year}`)
}

function openExportModal() {
    if (!hasClubSelected.value || !isDirector.value) return
    if (!props.workplan?.id) {
    showToast(tr('Crea un plan de trabajo antes de exportar', 'Create a workplan before exporting'), 'warning')
        return
    }
    const defaultSlug = props.integration_config?.church_slug || props.integration_config?.church?.slug || ''
    const defaultDepartment = departmentsOptions.value?.[0]?.id || ''
    const year = resolveCalendarYear()
    exportForm.value = {
        calendar_year: year,
        plan_name: defaultPlanName(year),
        publish_to_feed: true,
        church_slug: defaultSlug,
        department_id: defaultDepartment
    }
    exportResponse.value = null
    exportError.value = null
    exportResponseOpen.value = false
    exportModalOpen.value = true
}

function openObjectiveModal() {
    objectiveAssignments.value = {}
    missingObjectiveEvents.value.forEach(ev => {
        const deptId = getEventDepartmentId(ev)
        const objectiveKey = getObjectiveKeyFromEvent(ev)
        const matches = objectiveMatchesDepartment(objectiveKey, deptId)
        objectiveAssignments.value[ev.id] = matches ? objectiveKey : ''
    })
    bulkObjectiveId.value = ''
    objectiveTab.value = 'recurrent'
    objectiveModalOpen.value = true
}

function applyObjectiveToAll(list) {
    if (!bulkObjectiveId.value) return
    list.forEach(ev => {
        const deptId = getEventDepartmentId(ev)
        if (objectiveMatchesDepartment(bulkObjectiveId.value, deptId)) {
            objectiveAssignments.value[ev.id] = bulkObjectiveId.value
        }
    })
}

async function saveObjectivesAndExport() {
    if (objectiveSaving.value) return
    const missing = missingObjectiveEvents.value
    const incomplete = missing.find(ev => !objectiveAssignments.value[ev.id])
    if (incomplete) {
        showToast(tr('Selecciona un objetivo para cada evento', 'Select an objective for each event'), 'warning')
        return
    }
    objectiveSaving.value = true
    try {
        for (const ev of missing) {
            const objectiveSelection = parseObjectiveSelection(objectiveAssignments.value[ev.id] || null)
            const payload = {
                date: normalizeDate(ev.date),
                start_time: trimTime(ev.start_time),
                end_time: trimTime(ev.end_time),
                meeting_type: ev.meeting_type,
                title: ev.title,
                description: ev.description,
                location: ev.location,
                department_id: ev.department_id || null,
                objective_id: objectiveSelection.objective_id,
                local_objective_id: objectiveSelection.local_objective_id,
            }
            const { event } = await updateWorkplanEvent(ev.id, payload)
            events.value = events.value.map(e => e.id === event.id ? normalizeEvents([event])[0] : e)
        }
        objectiveModalOpen.value = false
        await submitExport()
    } catch (error) {
        console.error(error)
        showToast(tr('No se pudieron guardar los objetivos', 'Could not save objectives'), 'error')
    } finally {
        objectiveSaving.value = false
    }
}

async function submitExport() {
    if (!hasClubSelected.value || !isDirector.value) return
    if (missingRecurrentEvents.value.length) {
        openObjectiveModal()
        return
    }
    if (missingSpecialEvents.value.length) {
        openObjectiveModal()
        return
    }
    exportResponse.value = null
    exportError.value = null
    exportResponseOpen.value = false
    exportLoading.value = true
    try {
        const invalidEvents = events.value.filter((ev) => {
            const startDate = normalizeDate(ev.date)
            const endDate = normalizeDate(ev.end_date || ev.date)
            if (!startDate || !endDate) return false
            if (endDate !== startDate) return false
            const startTime = ev.start_time ? ev.start_time.slice(0, 5) : ''
            const endTime = ev.end_time ? ev.end_time.slice(0, 5) : ''
            if (!endTime) return false
            if (!startTime) return true
            return endTime <= startTime
        }).map((ev) => ({
            incoming_external_id: `workplan-event-${ev.id}`,
            incoming_title: ev.title,
            incoming_start_at: `${normalizeDate(ev.date)}T${ev.start_time ? ev.start_time.slice(0, 5) : '00:00'}`,
            incoming_end_at: `${normalizeDate(ev.end_date || ev.date)}T${ev.end_time ? ev.end_time.slice(0, 5) : '00:00'}`,
            conflict_type: 'invalid_range',
            message: 'End time must be after start time.',
            conflicts: [],
        }))
        if (invalidEvents.length) {
            exportError.value = {
                message: tr('Hay eventos con hora final invalida. Corrige la hora o usa un rango de fechas.', 'Some events have an invalid end time. Correct the time or use a date range.'),
                conflicts: invalidEvents,
                imported: 0,
                skipped: invalidEvents.length,
                successes: [],
                overrides: [],
            }
            exportResponseOpen.value = true
            exportLoading.value = false
            return
        }
        const payload = {
            calendar_year: exportForm.value.calendar_year,
            plan_name: exportForm.value.plan_name,
            publish_to_feed: exportForm.value.publish_to_feed,
            department_id: exportForm.value.department_id || null,
        }
        if (exportForm.value.church_slug) {
            payload.church_slug = exportForm.value.church_slug
        }
        const data = await exportWorkplanToMyChurchAdmin(payload)
        exportResponse.value = data
        exportResponseOpen.value = true
        showToast(tr(`Exportados ${data.sent_events || data.imported || 0} eventos`, `Exported ${data.sent_events || data.imported || 0} events`))
    } catch (error) {
        console.error(error)
        const message = error?.response?.data?.message || tr('Fallo la exportacion', 'Export failed')
        exportError.value = error?.response?.data || { message }
        exportResponseOpen.value = true
        showToast(message, 'error')
    } finally {
        exportLoading.value = false
    }
}

async function createWorkplanNow() {
    if (!hasClubSelected.value || isReadOnly.value) return
    try {
        const payload = { ...form.value, rules: buildRulesPayload() }
        const { workplan } = await confirmWorkplan(payload)
        events.value = normalizeEvents(workplan.events || [])
        form.value = {
            ...form.value,
            start_date: workplan.start_date,
            end_date: workplan.end_date,
            default_sabbath_location: workplan.default_sabbath_location || '',
            default_sunday_location: workplan.default_sunday_location || '',
            default_sabbath_start_time: trimTime(workplan.default_sabbath_start_time),
            default_sabbath_end_time: trimTime(workplan.default_sabbath_end_time),
            default_sunday_start_time: trimTime(workplan.default_sunday_start_time),
            default_sunday_end_time: trimTime(workplan.default_sunday_end_time),
            timezone: workplan.timezone || form.value.timezone,
        }
        recurrence.value = {
            sabbath: (workplan.rules || []).filter(r => r.meeting_type === 'sabbath').map(r => r.nth_week),
            sunday: (workplan.rules || []).filter(r => r.meeting_type === 'sunday').map(r => r.nth_week),
        }
        workplanModalOpen.value = false
        showToast(tr('Plan de trabajo creado', 'Workplan created'))
        // Refresh to sync props and state (ensures no stale defaults)
        window.location.reload()
    } catch (error) {
        console.error(error)
        showToast(tr('No se pudo crear el plan de trabajo', 'Could not create the workplan'), 'error')
    }
}

async function handleDeleteWorkplan() {
    if (!hasClubSelected.value || !props.workplan?.id) return
    const confirmDelete = confirm(tr('¿Eliminar el plan de trabajo actual? Esto borrara todos los eventos programados del club.', 'Delete the current workplan? This will remove all scheduled club events.'))
    if (!confirmDelete) return
    deletingWorkplan.value = true
    try {
        await deleteWorkplan(selectedClubId.value)
        showToast(tr('Plan de trabajo eliminado', 'Workplan deleted'))
        const redirect = safeRoute('club.workplan', { club_id: selectedClubId.value }, '/club-director/workplan')
        window.location.assign(redirect)
    } catch (error) {
        console.error(error)
        showToast(tr('No se pudo eliminar el plan de trabajo', 'Could not delete the workplan'), 'error')
    } finally {
        deletingWorkplan.value = false
    }
}


function defaultLocation(type) {
    if (type === 'sabbath') return form.value.default_sabbath_location || ''
    if (type === 'sunday') return form.value.default_sunday_location || ''
    return ''
}

async function saveEvent() {
    if (isReadOnly.value) return
    if (!hasClubSelected.value) return
    try {
        if (eventForm.value.end_date && eventForm.value.end_date < eventForm.value.date) {
            showToast(tr('La fecha final no puede ser anterior a la fecha inicial', 'The end date cannot be before the start date'), 'warning')
            return
        }
        const payload = {
            ...eventForm.value,
            end_date: eventForm.value.meeting_type === 'special' ? (eventForm.value.end_date || null) : null,
            department_id: eventForm.value.department_id || null,
            objective_id: parseObjectiveSelection(eventForm.value.objective_key).objective_id,
            local_objective_id: parseObjectiveSelection(eventForm.value.objective_key).local_objective_id,
        }
        if (editingEvent.value) {
            const { event } = await updateWorkplanEvent(editingEvent.value.id, payload)
            events.value = events.value.map(e => e.id === event.id ? normalizeEvents([event])[0] : e)
            showToast(tr('Evento actualizado', 'Event updated'))
        } else {
            const { event } = await createWorkplanEvent(payload)
            events.value = [...events.value, normalizeEvents([event])[0]]
            showToast(tr('Evento agregado', 'Event added'))
        }
        eventModalOpen.value = false
        editingEvent.value = null
    } catch (error) {
        console.error(error)
        showToast(tr('No se pudo guardar el evento', 'Could not save the event'), 'error')
    }
}

const searchLocations = (query) => {
    if (locationTimer) clearTimeout(locationTimer)
    if (!query || query.length < 3) {
        locationSuggestions.value = []
        return
    }
    locationTimer = setTimeout(async () => {
        locationLoading.value = true
        try {
            const resp = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&limit=6`, {
                headers: { 'Accept-Language': 'en', 'User-Agent': 'club-portal/1.0' }
            })
            const data = await resp.json()
            locationSuggestions.value = (data || []).map(item => ({
                label: item.display_name,
                value: item.display_name,
            }))
        } catch (err) {
            console.error('Fallo la busqueda de ubicacion', err)
            locationSuggestions.value = []
        } finally {
            locationLoading.value = false
        }
    }, 400)
}

const applyLocation = (item) => {
    eventForm.value.location = item.value
    locationSuggestions.value = []
}

const closeLocationSuggestions = () => {
    locationSuggestions.value = []
}

const handleLocationBlur = () => {
    setTimeout(() => {
        locationSuggestions.value = []
    }, 150)
}

const searchPlanLocation = (query) => {
    if (planLocationTimer) clearTimeout(planLocationTimer)
    if (!query || query.length < 3) {
        planLocationSuggestions.value = []
        return
    }
    planLocationTimer = setTimeout(async () => {
        planLocationLoading.value = true
        try {
            const resp = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&limit=6`, {
                headers: { 'Accept-Language': 'en', 'User-Agent': 'club-portal/1.0' }
            })
            const data = await resp.json()
            planLocationSuggestions.value = (data || []).map(item => ({
                label: item.display_name,
                value: item.display_name,
            }))
        } catch (err) {
            console.error('Fallo la busqueda de ubicacion', err)
            planLocationSuggestions.value = []
        } finally {
            planLocationLoading.value = false
        }
    }, 400)
}

const applyPlanLocation = (item) => {
    planForm.value.location_override = item.value
    planLocationSuggestions.value = []
}

async function removeEvent(ev) {
    if (isReadOnly.value) return
    if (!hasClubSelected.value) return
    if (!confirm(tr('¿Eliminar este evento?', 'Delete this event?'))) return
    try {
        await deleteWorkplanEvent(ev.id)
        events.value = events.value.filter(e => e.id !== ev.id)
        showToast(tr('Evento eliminado', 'Event deleted'))
    } catch (error) {
        console.error(error)
        showToast(tr('No se pudo eliminar el evento', 'Could not delete the event'), 'error')
    }
}

function badgeColor(type) {
    if (type === 'sabbath') return 'bg-indigo-100 text-indigo-800'
    if (type === 'sunday') return 'bg-teal-100 text-teal-800'
    return 'bg-amber-100 text-amber-800'
}

function planStatusClass(status) {
    if (status === 'approved') return 'bg-green-100 text-green-800 border border-green-200'
    if (status === 'rejected') return 'bg-red-100 text-red-800 border border-red-200'
    if (status === 'submitted') return 'bg-amber-100 text-amber-800 border border-amber-200'
    if (status === 'changes_requested') return 'bg-amber-50 text-amber-800 border border-amber-200'
    return 'bg-gray-100 text-gray-700 border border-gray-200'
}

function addOrUpdatePlanInEvents(plan) {
    const normalizedPlan = {
        ...plan,
        investitureRequirement: plan.investitureRequirement || plan.investiture_requirement || null
    }
    events.value = events.value.map(ev => {
        if (ev.id !== normalizedPlan.workplan_event_id) return ev
        const existing = ev.classPlans || []
        const updatedPlans = existing.some(p => p.id === normalizedPlan.id)
            ? existing.map(p => (p.id === normalizedPlan.id ? normalizedPlan : p))
            : [...existing, normalizedPlan]
        return { ...ev, classPlans: updatedPlans }
    })
}

function selectMeeting(ev) {
    selectedEvent.value = ev
    editingPlanId.value = null
    planForm.value = {
        workplan_event_id: ev.id,
        class_id: userClassId.value,
        investiture_requirement_id: null,
        type: 'plan',
        title: '',
        description: '',
        requested_date: normalizeDate(ev.date),
        location_override: ''
    }
}

async function savePlan() {
    if (!isStaff.value) return
    if (!selectedEvent.value) {
        showToast(tr('Selecciona una reunion primero', 'Select a meeting first'), 'warning')
        return
    }
    if (!planForm.value.class_id && userClassId.value) {
        planForm.value.class_id = userClassId.value
    }
    try {
        const { plan } = editingPlanId.value
            ? await updateClassPlan(editingPlanId.value, planForm.value)
            : await createClassPlan(planForm.value)
        const enriched = {
            ...plan,
            staff: plan.staff || (props.auth_user?.name ? { name: props.auth_user.name } : undefined),
            class: plan.class || (staffProfile.value?.assigned_class_name ? { class_name: staffProfile.value.assigned_class_name } : undefined)
        }
        addOrUpdatePlanInEvents(enriched)
        showToast(editingPlanId.value ? tr('Plan de clase actualizado', 'Class plan updated') : tr('Plan de clase enviado', 'Class plan submitted'))
        editingPlanId.value = null
        planForm.value = {
            workplan_event_id: selectedEvent.value.id,
            class_id: userClassId.value,
            investiture_requirement_id: null,
            type: 'plan',
            title: '',
            description: '',
            requested_date: normalizeDate(selectedEvent.value.date),
            location_override: ''
        }
    } catch (e) {
        console.error(e)
        showToast(tr('No se pudo guardar el plan de clase', 'Could not save the class plan'), 'error')
    }
}

async function updatePlanStatus(plan, status) {
    if (!isDirector.value) return
    try {
        const payload = typeof status === 'string' ? { status } : status
        const { plan: updated } = await updateClassPlanStatus(plan.id, payload)
        addOrUpdatePlanInEvents(updated)
        if (planDetail.value?.id === plan.id) {
            planDetail.value = { ...updated, _event: planDetail.value._event }
        }
        showToast(tr(`Plan ${payload.status}`, `Plan ${payload.status}`))
    } catch (e) {
        console.error(e)
        showToast(tr('No se pudo actualizar el estado', 'Could not update the status'), 'error')
    }
}

function meetingCardClass(ev) {
    const targetClass =
        userClassId.value ||
        props.auth_user?.staff?.assigned_class ||
        props.auth_user?.staff_class?.id ||
        (isDirector.value ? classFilter.value : null)

    const plans = (ev.classPlans || []).filter(p => {
        if (!targetClass) return false
        const pid = p.class_id || p.class?.id
        return String(pid || '') === String(targetClass)
    })
    const hasApproved = plans.some(p => p.status === 'approved' || p.requires_approval === false)
    const needsApproval = plans.some(p => p.requires_approval && p.status === 'submitted')
    const base = hasApproved
        ? 'bg-green-50 border-green-200'
        : needsApproval
            ? 'bg-yellow-50 border-yellow-200'
            : 'bg-red-50 border-red-100'
    const selected = selectedEvent.value?.id === ev.id ? 'ring-2 ring-blue-500' : ''
    return `${base} ${selected}`
}

function openPlanDetail(plan) {
    planDetail.value = plan
    planDetailOpen.value = true
}

function editPlan(plan) {
    const ev = plan._event || events.value.find(e => e.id === plan.workplan_event_id)
    if (ev) {
        selectMeeting(ev)
    }
    editingPlanId.value = plan.id
    planForm.value = {
        workplan_event_id: plan.workplan_event_id,
        class_id: plan.class_id || userClassId.value,
        investiture_requirement_id: plan.investiture_requirement_id || plan.investitureRequirement?.id || plan.investiture_requirement?.id || null,
        type: plan.type || 'plan',
        title: plan.title || '',
        description: plan.description || '',
        requested_date: normalizeDate(plan.requested_date || ev?.date),
        location_override: plan.location_override || ''
    }
    showToast(tr('Edita el plan a la derecha y guarda.', 'Edit the plan on the right and save.'))
}

function openRequestModal(plan) {
    requestPlan.value = plan
    requestNote.value = plan.request_note || ''
    requestModalOpen.value = true
}

async function submitRequestNote() {
    if (!requestPlan.value) return
    await updatePlanStatus(requestPlan.value, { status: 'changes_requested', request_note: requestNote.value })
    requestModalOpen.value = false
    requestPlan.value = null
    requestNote.value = ''
}

onMounted(() => {
    const handler = () => { windowWidth.value = window.innerWidth }
    handler()
    window.addEventListener('resize', handler)
    onBeforeUnmount(() => window.removeEventListener('resize', handler))

    if (isStaff.value) {
        fetchStaffRecord().then(data => {
            staffProfile.value = data.staffRecord || null
        }).catch(err => console.error('No se pudo cargar el perfil del personal', err))
    }
})

watch(upcomingPlanEvents, (list) => {
    if (!selectedEvent.value && list.length) {
        selectMeeting(list[0])
        return
    }
    if (selectedEvent.value && list.length && !list.some(ev => ev.id === selectedEvent.value.id)) {
        selectMeeting(list[0])
    }
    if (!list.length) selectedEvent.value = null
})

watch(isStaff, (val) => {
    if (val && userClassId.value) {
        classFilter.value = userClassId.value
    }
})

watch(userClassId, (val) => {
    if (isStaff.value && val) {
        classFilter.value = val
    }
})

watch(() => planForm.value.class_id, (newClassId, oldClassId) => {
    if (!newClassId || String(newClassId) !== String(oldClassId)) {
        planForm.value.investiture_requirement_id = null
    }
})
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ tr('Plan de trabajo', 'Workplan') }}</template>
        <div class="px-6 py-4 space-y-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="space-y-2">
                    <h1 class="text-2xl font-semibold text-gray-900">{{ tr('Plan de trabajo del club', 'Club Workplan') }}</h1>
                    <p class="text-sm text-gray-600">{{ tr('Calendario de reuniones sabaticas y dominicales del club con eventos especiales.', 'Calendar for club Sabbath and Sunday meetings with special events.') }}</p>
                    <div class="flex items-center gap-2">
                        <label class="text-sm text-gray-700">{{ tr('Club', 'Club') }}</label>
                        <template v-if="canSelectClub">
                            <select v-model="selectedClubId" class="border rounded px-3 py-1 text-sm" @change="onClubChange">
                                <option value="">{{ tr('Selecciona un club', 'Select a club') }}</option>
                                <option v-for="club in clubs" :key="club.id" :value="club.id">
                                    {{ club.club_name }}{{ club.church_name ? ` - ${club.church_name}` : '' }}
                                </option>
                            </select>
                        </template>
                        <template v-else>
                            <span class="text-sm font-semibold text-gray-800">
                                {{ clubs.find(c => String(c.id) === String(selectedClubId))?.club_name || '—' }} -  {{ classDisplayName }}
                            </span>
                        </template>
                    </div>
                </div>
                <div class="flex flex-col items-start gap-2">
                    <div class="flex flex-wrap gap-2 items-center">
                        <a :href="hasClubSelected ? pdfHref : '#'" target="_blank" class="p-2 rounded-md bg-white border text-sm text-gray-800 hover:bg-gray-50 inline-flex items-center gap-1" :class="!hasClubSelected && 'opacity-50 pointer-events-none'">
                            <ArrowDownTrayIcon class="w-4 h-4" />
                            <span class="sr-only">{{ tr('Descargar PDF', 'Download PDF') }}</span>
                        </a>
                        <a :href="tablePdfHref" target="_blank" class="p-2 rounded-md bg-white border text-sm text-gray-800 hover:bg-gray-50 inline-flex items-center gap-1" :class="(!hasClubSelected || !isDirector) && 'opacity-50 pointer-events-none'">
                            <TableCellsIcon class="w-4 h-4" />
                            <span>{{ tr('Tabla', 'Table') }}</span>
                        </a>
                        <a :href="hasClubSelected ? icsHref : '#'" target="_blank" class="p-2 rounded-md bg-white border text-sm text-gray-800 hover:bg-gray-50 inline-flex items-center gap-1" :class="!hasClubSelected && 'opacity-50 pointer-events-none'">
                            <CalendarDaysIcon class="w-4 h-4" />
                            <span class="sr-only">{{ tr('Descargar ICS', 'Download ICS') }}</span>
                        </a>
                        <button
                            v-if="isDirector && props.workplan?.id"
                            class="px-3 py-2 text-sm rounded-md bg-emerald-600 text-white"
                            :class="!hasClubSelected && 'opacity-50 pointer-events-none'"
                            type="button"
                            @click="openExportModal"
                        >
                            {{ tr('Exportar a mychurchadmin.net', 'Export to mychurchadmin.net') }}
                        </button>
                        <button
                            v-if="isDirector && props.workplan?.id"
                            class="px-3 py-2 text-sm rounded-md bg-red-600 text-white"
                            :class="(!hasClubSelected || deletingWorkplan) && 'opacity-50 pointer-events-none'"
                            type="button"
                            @click="handleDeleteWorkplan"
                        >
                            {{ tr('Eliminar calendario', 'Delete calendar') }}
                        </button>
                    </div>
                    <button class="text-sm text-blue-600 hover:underline" @click="showIcsHelp = true" type="button">{{ tr('¿Como agregar?', 'How to add?') }}</button>
                </div>
            </div>

            <div v-if="hasClubSelected" class="space-y-6">
                <div v-if="!props.workplan?.id" class="bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 rounded flex items-center justify-between">
                    <div>
                        <p class="font-semibold">{{ tr('No hay plan de trabajo para este club.', 'There is no workplan for this club.') }}</p>
                        <p class="text-sm">{{ tr('Define el rango de fechas y valores predeterminados para comenzar.', 'Define the date range and defaults to get started.') }}</p>
                    </div>
                    <button class="px-3 py-2 bg-amber-600 text-white rounded text-sm" @click="openWorkplanModal">{{ tr('Crear plan de trabajo', 'Create workplan') }}</button>
                </div>

                <div class="bg-white shadow-sm rounded-lg p-4 border">
                    <WorkplanCalendar
                        :events="allCalendarEvents"
                        :is-read-only="isReadOnly"
                        :can-add="!isReadOnly"
                        @add="date => openEventModal(null, date)"
                        @edit="ev => ev._inherited ? (inheritedEventModal = ev) : openEventModal(ev)"
                    />
                    <div v-if="props.inherited_events.length" class="mt-2 flex items-center gap-3 text-[11px] text-gray-500">
                        <span class="flex items-center gap-1">
                            <span class="inline-block w-3 h-3 rounded" style="background:#faf5ff;border-left:3px solid #a855f7"></span>
                            {{ tr('Eventos de asociación', 'Association events') }}
                        </span>
                        <span class="flex items-center gap-1">
                            <span class="inline-block w-3 h-3 rounded" style="background:#f0fdf4;border-left:3px solid #2dd4bf"></span>
                            {{ tr('Eventos de distrito', 'District events') }}
                        </span>
                    </div>
                </div>
            </div>

            <div v-if="hasClubSelected" class="bg-white shadow-sm rounded-lg p-4 border space-y-4">
                <div v-if="isStaff" class="space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2">
                        <div>
                            <h3 class="font-semibold text-gray-800">{{ tr('Planes de clase en dias de reunion', 'Class plans on meeting days') }}</h3>
                            <p class="text-sm text-gray-600">{{ tr('Elige una reunion sabatica/dominical y agrega tu plan de clase o salida.', 'Choose a Sabbath/Sunday meeting and add your class plan or outing.') }}</p>
                        </div>
                        <div class="text-xs text-gray-500">{{ tr('Selecciona una reunion para comenzar.', 'Select a meeting to start.') }}</div>
                    </div>

                    <div class="grid md:grid-cols-2 gap-4">
                        <div class="rounded-lg p-3 border bg-white space-y-2">
                            <h4 class="font-semibold text-gray-800 text-sm">{{ tr('Fechas de reunion', 'Meeting dates') }}</h4>
                            <div v-if="upcomingPlanEvents.length" class="space-y-2 max-h-[360px] overflow-y-auto pr-1">
                                <button
                                    v-for="ev in upcomingPlanEvents"
                                    :key="'plan-ev-' + ev.id"
                                    class="w-full text-left rounded-md p-3 transition shadow-sm"
                                    :class="meetingCardClass(ev)"
                                    @click="selectMeeting(ev)"
                                >
                                    <div class="flex justify-between items-start gap-2">
                                        <div class="space-y-0.5">
                                            <div class="text-xs text-gray-500">{{ normalizeDate(ev.date) }}</div>
                                            <div class="font-semibold text-gray-900 truncate">{{ ev.title }}</div>
                                            <div class="text-xs text-gray-600 capitalize">{{ ev.meeting_type }}</div>
                                            <div class="text-[11px] text-gray-600">{{ formatTimeRange(ev) || tr('Todo el dia', 'All day') }}</div>
                                            <div class="text-[11px] text-gray-600">{{ tr('Ubicacion', 'Location') }}: {{ ev.location || '—' }}</div>
                                        </div>
                                        <span v-if="ev.is_generated" class="text-[10px] px-2 py-0.5 rounded-full border border-black text-black bg-white inline-flex items-center justify-center">A</span>
                                    </div>
                                </button>
                            </div>
                            <div v-else class="text-sm text-gray-600 space-y-2">
                                <div>{{ tr('No hay reuniones programadas en el rango.', 'There are no meetings scheduled in the range.') }}</div>
                                <button
                                    class="px-3 py-1 border rounded text-sm text-blue-600 inline-flex items-center gap-1"
                                    type="button"
                                    @click="openEventModal()"
                                >
                                    {{ tr('Crear una reunion', 'Create a meeting') }}
                                </button>
                            </div>
                        </div>

                        <div class="rounded-lg p-4 border bg-gray-50 border-gray-200">
                            <div class="flex items-center justify-between mb-2">
                                <h4 class="font-semibold text-gray-800 text-sm">{{ tr('Crear plan de clase', 'Create class plan') }}</h4>
                                <span v-if="editingPlanId" class="text-xs text-amber-700 bg-amber-100 border border-amber-200 px-2 py-0.5 rounded">{{ tr('Editando', 'Editing') }}</span>
                            </div>
                            <div v-if="selectedEvent" class="space-y-3">
                                <div class="text-xs text-gray-600 bg-white border rounded p-2">
                                    {{ tr('Reunion', 'Meeting') }}: {{ normalizeDate(selectedEvent.date) }} · {{ selectedEvent.title }} ({{ selectedEvent.meeting_type }})
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-700">{{ tr('Titulo', 'Title') }}</label>
                                    <input v-model="planForm.title" class="w-full border rounded px-3 py-2 text-sm" :disabled="!isStaff" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-700">{{ tr('Objetivo / Descripcion', 'Objective / Description') }}</label>
                                    <textarea v-model="planForm.description" rows="3" class="w-full border rounded px-3 py-2 text-sm" :disabled="!isStaff"></textarea>
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-700">{{ tr('Requisito de investidura (opcional)', 'Investiture requirement (optional)') }}</label>
                                    <select
                                        v-model="planForm.investiture_requirement_id"
                                        class="w-full border rounded px-3 py-2 text-sm"
                                        :disabled="!isStaff || !selectedClassRequirements.length"
                                    >
                                        <option :value="null">{{ tr('Sin requisito vinculado', 'No linked requirement') }}</option>
                                        <option
                                            v-for="req in selectedClassRequirements"
                                            :key="`req-${req.id}`"
                                            :value="req.id"
                                        >
                                            {{ req.sort_order ? `${req.sort_order}. ` : '' }}{{ req.title }}
                                        </option>
                                    </select>
                                    <p v-if="!selectedClassRequirements.length" class="text-xs text-gray-500 mt-1">
                                        {{ tr('Esta clase no tiene requisitos activos configurados.', 'This class has no active requirements configured.') }}
                                    </p>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-sm text-gray-700">{{ tr('Tipo', 'Type') }}</label>
                                        <select v-model="planForm.type" class="w-full border rounded px-3 py-2 text-sm" :disabled="!isStaff">
                                            <option value="plan">{{ tr('Plan (en sitio)', 'Plan (on site)') }}</option>
                                            <option value="outing">{{ tr('Salida (requiere aprobacion)', 'Outing (requires approval)') }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm text-gray-700">{{ tr('Fecha', 'Date') }}</label>
                                        <input type="date" v-model="planForm.requested_date" class="w-full border rounded px-3 py-2 text-sm" :disabled="!isStaff" />
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-700">{{ tr('Ubicacion alternativa (para salidas)', 'Alternate location (for outings)') }}</label>
                                    <input
                                        v-model="planForm.location_override"
                                        class="w-full border rounded px-3 py-2 text-sm"
                                        :disabled="!isStaff"
                                        :placeholder="tr('Opcional', 'Optional')"
                                        @input="searchPlanLocation(planForm.location_override)"
                                    />
                                    <div v-if="planLocationSuggestions.length"
                                        class="mt-1 border rounded bg-white shadow-sm max-h-40 overflow-y-auto text-sm">
                                        <button v-for="(opt, idx) in planLocationSuggestions" :key="idx" type="button"
                                            class="w-full text-left px-3 py-2 hover:bg-gray-100"
                                            @click="applyPlanLocation(opt)">
                                            {{ opt.label }}
                                        </button>
                                        <div v-if="planLocationLoading" class="px-3 py-2 text-xs text-gray-500">{{ tr('Buscando...', 'Searching...') }}</div>
                                    </div>
                                </div>
                                <div class="flex justify-end">
                                    <button class="px-4 py-2 bg-blue-600 text-white rounded text-sm disabled:opacity-50" :disabled="!isStaff" @click="savePlan">
                                        {{ tr('Guardar plan', 'Save plan') }}
                                    </button>
                                </div>
                            </div>
                            <div v-else class="text-sm text-gray-600">{{ tr('Selecciona una reunion para crear un plan.', 'Select a meeting to create a plan.') }}</div>
                        </div>
                    </div>
                </div>

                <div class="border-t pt-4">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <h4 class="font-semibold text-gray-800">{{ tr('Planes por clases', 'Plans by class') }}</h4>
                            <span class="text-xs text-gray-500">{{ tr('Actualizaciones de estado en tiempo real.', 'Real-time status updates.') }}</span>
                        </div>
                        <div class="flex flex-wrap gap-2 items-center">
                            <template v-if="isDirector">
                                <label class="text-sm text-gray-700">{{ tr('Clase', 'Class') }}</label>
                                <select v-model="classFilter" class="border rounded px-3 py-1 text-sm">
                                    <option value="">{{ tr('Todas', 'All') }}</option>
                                    <option v-for="opt in classesOptions" :key="opt.id" :value="opt.id">{{ opt.name }}</option>
                                </select>
                                <label class="text-sm text-gray-700">{{ tr('Requiere aprobacion', 'Requires approval') }}</label>
                                <input type="checkbox" v-model="needsApprovalOnly" class="mr-2">
                                <label class="text-sm text-gray-700">{{ tr('Estado', 'Status') }}</label>
                                <select v-model="statusFilter" class="border rounded px-3 py-1 text-sm">
                                    <option value="all">{{ tr('Todos', 'All') }}</option>
                                    <option value="pending">{{ tr('Pendiente', 'Pending') }}</option>
                                    <option value="approved">{{ tr('Aprobado', 'Approved') }}</option>
                                    <option value="rejected">{{ tr('Rechazado', 'Rejected') }}</option>
                                </select>
                            </template>
                            <template v-else>
                                <span class="text-sm text-gray-700">{{ tr('Clase', 'Class') }}: {{ classDisplayName }}</span>
                            </template>
                            <a :href="plansPdfHref" target="_blank" class="px-3 py-1 text-sm bg-white border rounded inline-flex items-center gap-1" :class="!hasClubSelected && 'opacity-50 pointer-events-none'">
                                {{ tr('Exportar PDF', 'Export PDF') }}
                            </a>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-gray-500">
                                <tr>
                                    <th class="py-2 pr-4">{{ tr('Fecha', 'Date') }}</th>
                                    <th class="py-2 pr-4">{{ tr('Clase', 'Class') }}</th>
                                    <th class="py-2 pr-4">{{ tr('Tipo', 'Type') }}</th>
                                    <th class="py-2 pr-4">{{ tr('Personal', 'Staff') }}</th>
                                    <th class="py-2 pr-4">{{ tr('Estado', 'Status') }}</th>
                                    <th class="py-2 pr-4"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="plan in filteredPlans" :key="`plan-row-${plan.id}`" class="border-t">
                                    <td class="py-2 pr-4">{{ normalizeDate(plan._event?.date || plan.requested_date) }}</td>
                                    <td class="py-2 pr-4">{{ plan.class?.class_name || '—' }}</td>
                                    <td class="py-2 pr-4 capitalize">{{ plan.type }}</td>
                                    <td class="py-2 pr-4">{{ plan.staff?.name || plan.staff?.user?.name || '—' }}</td>
                                    <td class="py-2 pr-4">
                                        <span class="text-[11px] px-2 py-0.5 rounded-full inline-block" :class="planStatusClass(plan.status)">
                                            {{ plan.status }}
                                        </span>
                                    </td>
                                    <td class="py-2 pr-4 text-right">
                                        <div class="flex justify-end gap-2">
                                            <button class="text-blue-600 text-sm" @click="openPlanDetail(plan)">{{ tr('Ver', 'View') }}</button>
                                            <button
                                                v-if="isStaff && (plan.status === 'rejected' || plan.status === 'changes_requested')"
                                                class="text-amber-700 text-sm"
                                                @click="editPlan(plan)"
                                            >
                                                {{ tr('Actualizar', 'Update') }}
                                            </button>
                                            <template v-if="isDirector">
                                                <button class="text-amber-700 text-sm" @click="openRequestModal(plan)">{{ tr('Solicitar actualizacion', 'Request update') }}</button>
                                                <button v-if="plan.status === 'submitted' || plan.status === 'changes_requested'" class="text-red-600 text-sm" @click="updatePlanStatus(plan, 'rejected')">{{ tr('Rechazar', 'Reject') }}</button>
                                            </template>
                                        </div>
                                        <div v-if="plan.request_note" class="text-[11px] text-amber-800 bg-amber-50 border border-amber-200 rounded mt-2 p-2 text-left">
                                            {{ tr('Nota', 'Note') }}: {{ plan.request_note }}
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="filteredPlans.length === 0">
                                    <td colspan="6" class="py-3 text-center text-gray-500">{{ tr('No hay planes enviados.', 'No submitted plans.') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div v-if="hasClubSelected" class="bg-white shadow-sm rounded-lg p-4 border space-y-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <h3 class="font-semibold text-gray-800">{{ tr('Plan de trabajo actual', 'Current workplan') }}</h3>
                            <p class="text-sm text-gray-600">{{ form.start_date }} → {{ form.end_date }} ({{ form.timezone || tr('Sin zona horaria', 'No time zone') }})</p>
                            <p class="text-xs text-gray-500">{{ tr('Predeterminados', 'Defaults') }}: {{ tr('Sabado', 'Sabbath') }} {{ form.default_sabbath_start_time || '—' }}-{{ form.default_sabbath_end_time || '—' }}, {{ tr('Domingo', 'Sunday') }} {{ form.default_sunday_start_time || '—' }}-{{ form.default_sunday_end_time || '—' }}</p>
                            <p v-if="isExpired" class="text-xs text-red-600 font-semibold mt-1">{{ tr('El rango actual ha finalizado. El calendario es solo lectura; configura el siguiente plan.', 'The current range has ended. The calendar is read-only; configure the next plan.') }}</p>
                        </div>
                    </div>

                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold text-gray-800">{{ tr('Lista de eventos', 'Event list') }}</h3>
                    <button v-if="!isReadOnly" class="px-3 py-2 text-sm rounded-md bg-amber-100 text-amber-800 border border-amber-200" @click="openEventModal()">{{ tr('Agregar evento especial', 'Add special event') }}</button>
                </div>
                <div v-if="isMobile" class="space-y-3">
                    <div v-for="ev in pagedEvents" :key="ev.id" class="border rounded-lg p-3 bg-white shadow-sm">
                        <div class="flex items-center justify-between">
                            <div class="text-sm font-semibold text-gray-900">{{ ev.title }}</div>
                            <span class="text-xs px-2 py-0.5 rounded border" :class="badgeColor(ev.meeting_type)">{{ ev.meeting_type }}</span>
                        </div>
                        <div class="text-sm text-gray-700 mt-1">{{ formatDateTime(ev) }}</div>
                        <div class="text-xs text-gray-600">{{ formatTimeRange(ev) }}</div>
                        <div class="text-xs text-gray-600">{{ tr('Ubicacion', 'Location') }}: {{ ev.location || '—' }}</div>
                        <div class="flex items-center justify-between mt-2">
                            <span v-if="ev.is_generated" class="text-[10px] px-2 py-0.5 rounded-full border border-black text-black bg-white">A</span>
                            <div class="flex gap-3" v-if="!isReadOnly">
                                <button class="text-blue-600 text-sm" @click="openEventModal(ev)">{{ tr('Editar', 'Edit') }}</button>
                                <button class="text-red-600 text-sm" @click="removeEvent(ev)">{{ tr('Eliminar', 'Delete') }}</button>
                            </div>
                        </div>
                    </div>
                    <div v-if="events.length === 0" class="text-sm text-gray-500 text-center py-4">{{ tr('No hay eventos.', 'There are no events.') }}</div>
                    <div v-else class="flex items-center justify-between text-sm text-gray-700">
                        <button class="px-3 py-1 border rounded" :disabled="currentPage === 1" @click="prevPage">{{ tr('Anterior', 'Previous') }}</button>
                        <span>{{ tr('Pagina', 'Page') }} {{ currentPage }} / {{ totalPages }}</span>
                        <button class="px-3 py-1 border rounded" :disabled="currentPage === totalPages" @click="nextPage">{{ tr('Siguiente', 'Next') }}</button>
                    </div>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-2 pr-4">{{ tr('Fecha', 'Date') }}</th>
                                <th class="py-2 pr-4">{{ tr('Tipo', 'Type') }}</th>
                                <th class="py-2 pr-4">{{ tr('Titulo', 'Title') }}</th>
                                <th class="py-2 pr-4">{{ tr('Hora', 'Time') }}</th>
                                <th class="py-2 pr-4">{{ tr('Ubicacion', 'Location') }}</th>
                                <th class="py-2 pr-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="ev in pagedEvents" :key="ev.id" class="border-t">
                                <td class="py-2 pr-4">{{ formatDateTime(ev) }}</td>
                                <td class="py-2 pr-4 capitalize">{{ ev.meeting_type }}</td>
                                <td class="py-2 pr-4">{{ ev.title }}</td>
                                <td class="py-2 pr-4">
                                    <span v-if="ev.start_time">{{ formatTime(ev.start_time) }}</span>
                                    <span v-if="ev.end_time"> - {{ formatTime(ev.end_time) }}</span>
                                </td>
                                <td class="py-2 pr-4">{{ ev.location || '—' }}</td>
                                <td class="py-2 pr-4 text-right">
                                    <button v-if="!isReadOnly" class="text-blue-600 text-sm mr-2" @click="openEventModal(ev)">{{ tr('Editar', 'Edit') }}</button>
                                    <button v-if="!isReadOnly" class="text-red-600 text-sm" @click="removeEvent(ev)">{{ tr('Eliminar', 'Delete') }}</button>
                                </td>
                            </tr>
                            <tr v-if="events.length === 0">
                                <td colspan="6" class="py-4 text-center text-gray-500">{{ tr('No hay eventos.', 'There are no events.') }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="events.length" class="flex items-center justify-between text-sm text-gray-700 mt-3">
                        <button class="px-3 py-1 border rounded" :disabled="currentPage === 1" @click="prevPage">{{ tr('Anterior', 'Previous') }}</button>
                        <span>{{ tr('Pagina', 'Page') }} {{ currentPage }} / {{ totalPages }}</span>
                        <button class="px-3 py-1 border rounded" :disabled="currentPage === totalPages" @click="nextPage">{{ tr('Siguiente', 'Next') }}</button>
                    </div>
                </div>
            </div>

            <div v-else class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded">
                <p>{{ tr('Selecciona un club para ver o administrar su plan de trabajo.', 'Select a club to view or manage its workplan.') }}</p>
                <div v-if="canSelectClub && clubs.length" class="mt-2">
                    <p class="text-sm font-medium">{{ tr('Clubes disponibles', 'Available clubs') }}:</p>
                    <div class="mt-1 max-w-md">
                        <select
                            v-model="selectedClubId"
                            class="w-full border rounded px-3 py-2 text-sm bg-white"
                            @change="onClubChange"
                        >
                            <option value="">{{ tr('Selecciona un club', 'Select a club') }}</option>
                            <option v-for="club in clubs" :key="club.id" :value="club.id">
                                {{ isSuperadmin ? `${club.club_name} - ${club.church_name || tr('Sin iglesia', 'No church')}` : club.club_name }}
                            </option>
                        </select>
                    </div>
                </div>
                <p v-else-if="!canSelectClub" class="mt-2 text-sm">
                    {{ tr('No hay un club activo disponible para esta vista.', 'There is no active club available for this view.') }}
                </p>
                <p v-else class="mt-2 text-sm">{{ tr('No hay clubes creados en el sistema.', 'There are no clubs created in the system.') }}</p>
            </div>

            <!-- Diff modal -->
            <div v-if="showDiffModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg max-w-xl w-full p-5">
                    <h4 class="text-lg font-semibold mb-3">{{ tr('Previsualizar cambios', 'Preview changes') }}</h4>
                    <div class="space-y-3 max-h-[60vh] overflow-y-auto">
                        <div>
                            <h5 class="font-medium text-gray-800 mb-1">{{ tr('Para agregar', 'To add') }}</h5>
                            <ul class="space-y-1 text-sm text-gray-700">
                                <li v-for="(item, idx) in previewDiff.adds" :key="`add-${idx}`">
                                    {{ item.date }} — {{ item.meeting_type }} ({{ item.title }})
                                </li>
                                <li v-if="previewDiff.adds.length === 0" class="text-gray-400">{{ tr('Sin adiciones', 'No additions') }}</li>
                            </ul>
                        </div>
                        <div>
                            <h5 class="font-medium text-gray-800 mb-1">{{ tr('Para eliminar', 'To remove') }}</h5>
                            <p class="text-sm text-gray-700" v-if="previewDiff.removals.length">{{ previewDiff.removals.length }} {{ tr('reuniones generadas se eliminaran (las ediciones manuales se mantienen).', 'generated meetings will be removed (manual edits are kept).') }}</p>
                            <p class="text-sm text-gray-400" v-else>{{ tr('Sin eliminaciones', 'No removals') }}</p>
                        </div>
                    </div>
                    <div class="mt-4 flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="showDiffModal = false">{{ tr('Cancelar', 'Cancel') }}</button>
                        <button class="px-4 py-2 bg-red-600 text-white rounded" @click="applyChanges">{{ tr('Aplicar', 'Apply') }}</button>
                    </div>
                </div>
            </div>

            <!-- Event modal -->
            <div v-if="eventModalOpen" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-5">
                    <h4 class="text-lg font-semibold mb-3">{{ editingEvent ? tr('Editar evento', 'Edit event') : tr('Agregar evento', 'Add event') }}</h4>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="col-span-2">
                            <label class="block text-sm text-gray-600 mb-1">{{ tr('Titulo', 'Title') }}</label>
                            <input type="text" v-model="eventForm.title" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">{{ tr('Fecha inicio', 'Start date') }}</label>
                            <input type="date" v-model="eventForm.date" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">{{ tr('Tipo', 'Type') }}</label>
                            <select v-model="eventForm.meeting_type" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="sabbath">{{ tr('Sabado', 'Sabbath') }}</option>
                                <option value="sunday">{{ tr('Domingo', 'Sunday') }}</option>
                                <option value="special">{{ tr('Especial', 'Special') }}</option>
                            </select>
                        </div>
                        <div v-if="eventForm.meeting_type === 'special'" class="col-span-2">
                            <label class="block text-sm text-gray-600 mb-1">{{ tr('Fecha fin', 'End date') }}</label>
                            <input type="date" v-model="eventForm.end_date" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">{{ tr('Hora de inicio', 'Start time') }}</label>
                            <input type="time" v-model="eventForm.start_time" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">{{ tr('Hora de fin', 'End time') }}</label>
                            <input type="time" v-model="eventForm.end_time" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-sm text-gray-600 mb-1">{{ tr('Ubicacion', 'Location') }}</label>
                            <div class="relative">
                                <input type="text" v-model="eventForm.location"
                                    class="w-full border rounded px-3 py-2 text-sm"
                                    @input="searchLocations(eventForm.location)"
                                    @blur="handleLocationBlur"
                                    @keydown.esc="closeLocationSuggestions"
                                    autocomplete="off">
                                <div v-if="locationLoading" class="absolute right-2 top-2 text-[11px] text-gray-500">…</div>
                                <div v-if="locationSuggestions.length"
                                    class="absolute z-30 mt-1 w-full bg-white border rounded shadow text-sm max-h-48 overflow-y-auto">
                                    <button type="button"
                                        class="w-full text-left px-3 py-2 text-gray-700 hover:bg-gray-100"
                                        @mousedown.prevent="closeLocationSuggestions">
                                        {{ tr('Usar direccion escrita', 'Use typed address') }}
                                    </button>
                                    <button v-for="(opt, idx) in locationSuggestions" :key="idx" type="button"
                                        class="w-full text-left px-3 py-2 hover:bg-gray-100"
                                        @mousedown.prevent="applyLocation(opt)">
                                        {{ opt.label }}
                                    </button>
                                </div>
                            </div>
                            <p class="text-[11px] text-gray-500 mt-1">{{ tr('Busqueda con OpenStreetMap (1 req/seg).', 'Search with OpenStreetMap (1 req/sec).') }}</p>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">{{ tr('Departamento', 'Department') }}</label>
                            <select v-model="eventForm.department_id" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="">{{ tr('Seleccionar', 'Select') }}</option>
                                <option v-for="dept in departmentsOptions" :key="`dept-${dept.id}`" :value="dept.id">
                                    {{ dept.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">{{ tr('Objetivo', 'Objective') }}</label>
                            <select v-model="eventForm.objective_key" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="">{{ tr('Seleccionar', 'Select') }}</option>
                                <option v-for="obj in objectivesOptions" :key="`obj-${obj.key}`" :value="obj.key">
                                    {{ obj.source === 'local' ? `[Local] ${obj.name}` : obj.name }}
                                </option>
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-sm text-gray-600 mb-1">{{ tr('Descripcion', 'Description') }}</label>
                            <textarea v-model="eventForm.description" rows="3" class="w-full border rounded px-3 py-2 text-sm"></textarea>
                        </div>
                    </div>
                    <div class="mt-4 flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="eventModalOpen = false">{{ tr('Cancelar', 'Cancel') }}</button>
                        <button class="px-4 py-2 bg-red-600 text-white rounded" @click="saveEvent">{{ editingEvent ? tr('Guardar cambios', 'Save changes') : tr('Agregar evento', 'Add event') }}</button>
                    </div>
                </div>
            </div>

            <!-- Export modal -->
            <div v-if="exportModalOpen" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg w-full max-w-3xl p-5 space-y-4">
                    <div class="flex items-start justify-between gap-2">
                        <h4 class="text-lg font-semibold">{{ tr('Exportar a mychurchadmin.net', 'Export to mychurchadmin.net') }}</h4>
                        <button class="text-gray-500" @click="exportModalOpen = false">✕</button>
                    </div>
                    <div class="text-sm text-gray-700">
                        {{ tr('Esto enviara los eventos del plan de trabajo al sistema de calendario externo.', 'This will send workplan events to the external calendar system.') }}
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">{{ tr('Ano del calendario', 'Calendar year') }}</label>
                            <input type="number" min="2000" v-model.number="exportForm.calendar_year" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div class="flex items-end gap-2">
                            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" v-model="exportForm.publish_to_feed">
                                {{ tr('Publicar en el feed', 'Publish to feed') }}
                            </label>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-sm text-gray-600 mb-1">{{ tr('Departamento', 'Department') }}</label>
                            <select v-model="exportForm.department_id" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="">{{ tr('Seleccionar', 'Select') }}</option>
                                <option v-for="dept in departmentsOptions" :key="`export-dept-${dept.id}`" :value="dept.id">
                                    {{ dept.name }}
                                </option>
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-sm text-gray-600 mb-1">{{ tr('Nombre del plan', 'Plan name') }}</label>
                            <input type="text" v-model="exportForm.plan_name" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-sm text-gray-600 mb-1">{{ tr('Slug de iglesia (opcional)', 'Church slug (optional)') }}</label>
                            <input type="text" v-model="exportForm.church_slug" class="w-full border rounded px-3 py-2 text-sm" placeholder="iglesia-x">
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="exportModalOpen = false">{{ tr('Cancelar', 'Cancel') }}</button>
                        <button class="px-4 py-2 bg-emerald-600 text-white rounded disabled:opacity-60" @click="submitExport">
                            {{ exportLoading ? tr('Exportando...', 'Exporting...') : tr('Exportar', 'Export') }}
                        </button>
                    </div>
                    <button
                        v-if="!exportResponseOpen && (exportResponse || exportError)"
                        class="text-xs text-blue-600 underline"
                        @click="exportResponseOpen = true"
                    >
                        {{ tr('Mostrar resumen de exportacion', 'Show export summary') }}
                    </button>
                    <div v-if="exportResponseOpen && (exportResponse || exportError)" class="border rounded bg-gray-50 p-3 text-xs text-gray-700 max-h-80 overflow-y-auto">
                        <div class="flex items-center justify-between mb-2">
                            <div class="font-semibold">{{ tr('Resumen de exportacion', 'Export summary') }}</div>
                            <button class="text-xs text-gray-500" @click="exportResponseOpen = false">{{ tr('Ocultar', 'Hide') }}</button>
                        </div>
                        <div v-if="exportError" class="text-[11px] text-red-700 mb-2">
                            <div class="font-semibold mb-1">{{ tr('Error de exportacion', 'Export error') }}</div>
                            <div>{{ exportMessage || tr('Fallo la exportacion', 'Export failed') }}</div>
                        </div>
                        <div v-if="exportSummary" class="space-y-3">
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-[11px]">
                                <div class="bg-white border rounded p-2">
                                    <div class="text-gray-500">{{ tr('Estado', 'Status') }}</div>
                                    <div class="font-semibold">{{ exportSummary.status || 'ok' }}</div>
                                </div>
                                <div class="bg-white border rounded p-2">
                                    <div class="text-gray-500">{{ tr('Importados', 'Imported') }}</div>
                                    <div class="font-semibold">{{ exportSummary.imported ?? exportSummary.sent_events ?? 0 }}</div>
                                </div>
                                <div class="bg-white border rounded p-2">
                                    <div class="text-gray-500">{{ tr('Omitidos', 'Skipped') }}</div>
                                    <div class="font-semibold">{{ exportSummary.skipped ?? 0 }}</div>
                                </div>
                                <div class="bg-white border rounded p-2">
                                    <div class="text-gray-500">{{ tr('Conflictos', 'Conflicts') }}</div>
                                    <div class="font-semibold">{{ exportSummary.conflicts?.length || 0 }}</div>
                                </div>
                            </div>
                            <div v-if="exportSummary.conflicts?.length" class="border rounded bg-white">
                                <div class="px-2 py-1 text-[11px] font-semibold text-gray-600 border-b">{{ tr('Conflictos', 'Conflicts') }}</div>
                                <div class="max-h-48 overflow-y-auto">
                                    <table class="min-w-full text-[11px]">
                                        <thead class="text-left text-gray-500">
                                            <tr>
                                                <th class="py-1 px-2">{{ tr('Evento', 'Event') }}</th>
                                                <th class="py-1 px-2">{{ tr('Problema', 'Problem') }}</th>
                                                <th class="py-1 px-2">{{ tr('Tipo', 'Type') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(conflict, idx) in exportSummary.conflicts" :key="`conflict-${idx}`" class="border-t">
                                                <td class="py-1 px-2">{{ conflict.incoming_title }}</td>
                                                <td class="py-1 px-2">{{ conflict.message }}</td>
                                                <td class="py-1 px-2">{{ conflict.conflict_type }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div v-if="exportSummary.successes?.length" class="border rounded bg-white">
                                <div class="px-2 py-1 text-[11px] font-semibold text-gray-600 border-b">{{ tr('Eventos importados', 'Imported events') }}</div>
                                <div class="max-h-32 overflow-y-auto">
                                    <table class="min-w-full text-[11px]">
                                        <thead class="text-left text-gray-500">
                                            <tr>
                                                <th class="py-1 px-2">{{ tr('Titulo', 'Title') }}</th>
                                                <th class="py-1 px-2">{{ tr('Inicio', 'Start') }}</th>
                                                <th class="py-1 px-2">{{ tr('Estado', 'Status') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="row in exportSummary.successes" :key="`success-${row.external_id}`" class="border-t">
                                                <td class="py-1 px-2">{{ row.title }}</td>
                                                <td class="py-1 px-2">{{ row.start_at }}</td>
                                                <td class="py-1 px-2">{{ row.review_status || tr('pendiente', 'pending') }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Objective check modal (recurrent) -->
            <div v-if="objectiveModalOpen" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg w-full max-w-3xl p-5 space-y-4">
                    <div class="flex items-start justify-between gap-2">
                        <h4 class="text-lg font-semibold">{{ tr('Asignar objetivos antes de exportar', 'Assign objectives before exporting') }}</h4>
                        <button class="text-gray-500" @click="objectiveModalOpen = false">✕</button>
                    </div>
                    <div class="text-sm text-gray-700">
                        {{ tr('Estos eventos recurrentes no tienen objetivo o tienen uno incorrecto. Asigna uno a cada evento o aplica un objetivo general a todos.', 'These events are missing an objective or have the wrong one. Assign one to each event or apply a general objective to all.') }}
                    </div>
                    <div class="flex flex-col sm:flex-row sm:items-end gap-3">
                        <div class="flex-1">
                            <label class="block text-sm text-gray-600 mb-1">{{ tr('Objetivo general', 'General objective') }}</label>
                            <select v-model="bulkObjectiveId" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="">{{ tr('Seleccionar', 'Select') }}</option>
                                <option v-for="obj in objectivesOptions" :key="`bulk-obj-${obj.key}`" :value="obj.key">
                                    {{ obj.source === 'local' ? `[Local] ${obj.name}` : obj.name }}
                                </option>
                            </select>
                        </div>
                        <button
                            class="px-3 py-2 border rounded text-sm"
                            type="button"
                            @click="applyObjectiveToAll(objectiveTab === 'special' ? missingSpecialEvents : missingRecurrentEvents)"
                        >
                            {{ tr('Aplicar a todos', 'Apply to all') }}
                        </button>
                    </div>
                    <div class="border rounded">
                        <div class="border-b bg-gray-50 flex text-sm">
                            <button
                                class="px-3 py-2"
                                :class="objectiveTab === 'recurrent' ? 'font-semibold text-gray-900' : 'text-gray-600'"
                                type="button"
                                @click="objectiveTab = 'recurrent'"
                            >
                                {{ tr('Eventos recurrentes', 'Recurring events') }} ({{ missingRecurrentEvents.length }})
                            </button>
                            <button
                                class="px-3 py-2"
                                :class="objectiveTab === 'special' ? 'font-semibold text-gray-900' : 'text-gray-600'"
                                type="button"
                                @click="objectiveTab = 'special'"
                            >
                                {{ tr('Eventos especiales', 'Special events') }} ({{ missingSpecialEvents.length }})
                            </button>
                        </div>
                        <div class="max-h-[320px] overflow-y-auto">
                            <table class="min-w-full text-sm">
                                <thead class="text-left text-gray-500">
                                    <tr>
                                        <th class="py-2 px-3">{{ tr('Evento', 'Event') }}</th>
                                        <th class="py-2 px-3">{{ tr('Fecha', 'Date') }}</th>
                                        <th class="py-2 px-3">{{ tr('Departamento', 'Department') }}</th>
                                        <th class="py-2 px-3">{{ tr('Objetivo', 'Objective') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="ev in (objectiveTab === 'special' ? missingSpecialEvents : missingRecurrentEvents)"
                                        :key="`obj-ev-${ev.id}`"
                                        class="border-t"
                                    >
                                        <td class="py-2 px-3">{{ ev.title }}</td>
                                        <td class="py-2 px-3">{{ normalizeDate(ev.date) }}</td>
                                        <td class="py-2 px-3">{{ getDepartmentName(getEventDepartmentId(ev)) }}</td>
                                        <td class="py-2 px-3">
                                            <select v-model="objectiveAssignments[ev.id]" class="w-full border rounded px-2 py-1 text-sm">
                                                <option value="">{{ tr('Seleccionar', 'Select') }}</option>
                                                <option
                                                    v-for="obj in objectivesForDepartment(getEventDepartmentId(ev))"
                                                    :key="`obj-${ev.id}-${obj.key}`"
                                                    :value="obj.key"
                                                >
                                                    {{ obj.source === 'local' ? `[Local] ${obj.name}` : obj.name }}
                                                </option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr v-if="objectiveTab === 'recurrent' && missingRecurrentEvents.length === 0">
                                        <td colspan="4" class="py-3 text-center text-gray-500">{{ tr('No hay objetivos pendientes.', 'There are no pending objectives.') }}</td>
                                    </tr>
                                    <tr v-else-if="objectiveTab === 'special' && missingSpecialEvents.length === 0">
                                        <td colspan="4" class="py-3 text-center text-gray-500">{{ tr('No hay objetivos pendientes.', 'There are no pending objectives.') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="objectiveModalOpen = false">{{ tr('Cancelar', 'Cancel') }}</button>
                        <button class="px-4 py-2 bg-emerald-600 text-white rounded disabled:opacity-60" :disabled="objectiveSaving" @click="saveObjectivesAndExport">
                            {{ objectiveSaving ? tr('Guardando...', 'Saving...') : tr('Guardar y exportar', 'Save and export') }}
                        </button>
                    </div>
                </div>
            </div>
            <!-- Plan detail modal -->
            <div v-if="planDetailOpen" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-5 space-y-4">
                    <div class="flex items-center justify-between">
                        <h4 class="text-lg font-semibold">{{ tr('Detalles del plan', 'Plan details') }}</h4>
                        <button class="text-gray-500" @click="planDetailOpen = false">✕</button>
                    </div>
                    <div v-if="planDetail" class="space-y-2 text-sm text-gray-700">
                        <div class="text-xs text-gray-600">
                            {{ tr('Reunion', 'Meeting') }}: {{ normalizeDate(planDetail._event?.date) }} · {{ planDetail._event?.title }} ({{ planDetail._event?.meeting_type }})
                        </div>
                        <div><span class="font-semibold">{{ tr('Titulo', 'Title') }}:</span> {{ planDetail.title }}</div>
                        <div><span class="font-semibold">{{ tr('Tipo', 'Type') }}:</span> {{ planDetail.type }}</div>
                        <div v-if="planDetail.description"><span class="font-semibold">{{ tr('Descripcion', 'Description') }}:</span> {{ planDetail.description }}</div>
                        <div><span class="font-semibold">{{ tr('Fecha solicitada', 'Requested date') }}:</span> {{ normalizeDate(planDetail.requested_date) || '—' }}</div>
                        <div><span class="font-semibold">{{ tr('Ubicacion alternativa', 'Alternate location') }}:</span> {{ planDetail.location_override || '—' }}</div>
                        <div><span class="font-semibold">{{ tr('Clase', 'Class') }}:</span> {{ planDetail.class?.class_name || '—' }}</div>
                        <div><span class="font-semibold">{{ tr('Requisito vinculado', 'Linked requirement') }}:</span> {{ planDetail.investitureRequirement?.title || planDetail.investiture_requirement?.title || '—' }}</div>
                        <div><span class="font-semibold">{{ tr('Personal', 'Staff') }}:</span> {{ planDetail.staff?.name || planDetail.staff?.user?.name || '—' }}</div>
                        <div>
                            <span class="font-semibold">{{ tr('Estado', 'Status') }}:</span>
                            <span class="text-[11px] px-2 py-0.5 rounded-full inline-block" :class="planStatusClass(planDetail.status)">{{ planDetail.status }}</span>
                            <span class="ml-2 text-xs text-gray-500" v-if="planDetail.requires_approval">({{ tr('Requiere aprobacion', 'Requires approval') }})</span>
                        </div>
                        <div v-if="planDetail.request_note" class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded p-2">
                            <span class="font-semibold">{{ tr('Nota del director', 'Director note') }}:</span> {{ planDetail.request_note }}
                        </div>
                    </div>
                    <div class="flex justify-end gap-2" v-if="isDirector && planDetail?.requires_approval && planDetail.status === 'submitted'">
                        <button class="px-3 py-2 border rounded text-red-700 border-red-500" @click="updatePlanStatus(planDetail, 'rejected')">{{ tr('Rechazar', 'Reject') }}</button>
                        <button class="px-3 py-2 border rounded text-green-700 border-green-500" @click="updatePlanStatus(planDetail, 'approved')">{{ tr('Aprobar', 'Approve') }}</button>
                    </div>
                    <div class="flex justify-end" v-else>
                        <button class="px-4 py-2 border rounded" @click="planDetailOpen = false">{{ tr('Cerrar', 'Close') }}</button>
                    </div>
                </div>
            </div>

            <!-- ICS Help Modal -->
            <div v-if="showIcsHelp" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-5 space-y-4">
                    <h4 class="text-lg font-semibold">{{ tr('Agregar ICS a tu calendario', 'Add ICS to your calendar') }}</h4>
                    <div class="space-y-2 text-sm text-gray-700">
                        <p class="font-medium text-gray-800">iOS (iPhone/iPad)</p>
                        <ol class="list-decimal list-inside space-y-1">
                            <li>{{ tr('Toca el enlace "Descargar ICS".', 'Tap the "Download ICS" link.') }}</li>
                            <li>{{ tr('Elige "Abrir en Calendario" (o compartir a Calendario).', 'Choose "Open in Calendar" (or share to Calendar).') }}</li>
                            <li>{{ tr('Toca "Agregar todos" o selecciona los eventos a importar.', 'Tap "Add all" or select the events to import.') }}</li>
                        </ol>
                        <p class="font-medium text-gray-800 pt-2">Google Calendar (web)</p>
                        <ol class="list-decimal list-inside space-y-1">
                            <li>{{ tr('Descarga el archivo .ics.', 'Download the .ics file.') }}</li>
                            <li>{{ tr('Ve a calendar.google.com -> "Otros calendarios" -> "Importar".', 'Go to calendar.google.com -> "Other calendars" -> "Import".') }}</li>
                            <li>{{ tr('Sube el archivo .ics y elige el calendario.', 'Upload the .ics file and choose the calendar.') }}</li>
                        </ol>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="showIcsHelp = false">{{ tr('Cerrar', 'Close') }}</button>
                    </div>
                </div>
            </div>

            <!-- Request update modal -->
            <div v-if="requestModalOpen" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-5 space-y-4">
                    <div class="flex items-start justify-between gap-2">
                        <h4 class="text-lg font-semibold">{{ tr('Solicitar actualizacion', 'Request update') }}</h4>
                        <button class="text-gray-500" @click="requestModalOpen = false">✕</button>
                    </div>
                    <div class="text-sm text-gray-700">
                        {{ tr('Agrega una nota para enviar al personal. El estado cambiara a "cambios solicitados".', 'Add a note for staff. The status will change to "changes requested".') }}
                    </div>
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">{{ tr('Motivo / cambios solicitados', 'Reason / requested changes') }}</label>
                        <textarea v-model="requestNote" rows="4" class="w-full border rounded px-3 py-2 text-sm"></textarea>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="requestModalOpen = false">{{ tr('Cancelar', 'Cancel') }}</button>
                        <button class="px-4 py-2 bg-amber-600 text-white rounded" @click="submitRequestNote">{{ tr('Enviar solicitud', 'Send request') }}</button>
                    </div>
                </div>
            </div>

            <!-- Create workplan modal -->
            <div v-if="workplanModalOpen" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg max-w-2xl w-full p-6 space-y-4">
                    <h4 class="text-lg font-semibold">{{ tr('Crear plan de trabajo', 'Create workplan') }}</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm text-gray-700">{{ tr('Fecha de inicio', 'Start date') }}</label>
                            <input type="date" v-model="form.start_date" class="w-full border rounded px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700">{{ tr('Fecha de fin', 'End date') }}</label>
                            <input type="date" v-model="form.end_date" class="w-full border rounded px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700">{{ tr('Zona horaria', 'Time zone') }}</label>
                            <input v-model="form.timezone" class="w-full border rounded px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700">{{ tr('Ubicacion sabatica', 'Sabbath location') }}</label>
                            <input v-model="form.default_sabbath_location" class="w-full border rounded px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700">{{ tr('Sabado inicio/fin', 'Sabbath start/end') }}</label>
                            <div class="flex gap-2">
                                <input type="time" v-model="form.default_sabbath_start_time" class="w-full border rounded px-3 py-2 text-sm" />
                                <input type="time" v-model="form.default_sabbath_end_time" class="w-full border rounded px-3 py-2 text-sm" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700">{{ tr('Ubicacion dominical', 'Sunday location') }}</label>
                            <input v-model="form.default_sunday_location" class="w-full border rounded px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700">{{ tr('Domingo inicio/fin', 'Sunday start/end') }}</label>
                            <div class="flex gap-2">
                                <input type="time" v-model="form.default_sunday_start_time" class="w-full border rounded px-3 py-2 text-sm" />
                                <input type="time" v-model="form.default_sunday_end_time" class="w-full border rounded px-3 py-2 text-sm" />
                            </div>
                        </div>
                        <div class="sm:col-span-2 space-y-3">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">{{ tr('Recurrencia sabatica (n en el mes)', 'Sabbath recurrence (nth week of the month)') }}</label>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="n in nthOptions"
                                        :key="'sab-'+n"
                                        type="button"
                                        class="px-3 py-1 rounded border text-sm"
                                        :class="recurrence.sabbath.includes(n) ? 'bg-amber-600 text-white border-amber-700' : 'bg-white text-gray-700'"
                                        @click="toggleNth('sabbath', n)"
                                    >
                                        {{ n }}{{ ['th','st','nd','rd'][n%10 > 3 ? 0 : n%10] || 'th' }} {{ tr('Sabado', 'Sabbath') }}
                                    </button>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">{{ tr('Recurrencia dominical (n en el mes)', 'Sunday recurrence (nth week of the month)') }}</label>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="n in nthOptions"
                                        :key="'sun-'+n"
                                        type="button"
                                        class="px-3 py-1 rounded border text-sm"
                                        :class="recurrence.sunday.includes(n) ? 'bg-teal-600 text-white border-teal-700' : 'bg-white text-gray-700'"
                                        @click="toggleNth('sunday', n)"
                                    >
                                        {{ n }}{{ ['th','st','nd','rd'][n%10 > 3 ? 0 : n%10] || 'th' }} {{ tr('Domingo', 'Sunday') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="workplanModalOpen = false">{{ tr('Cancelar', 'Cancel') }}</button>
                        <button class="px-4 py-2 bg-blue-600 text-white rounded" @click="createWorkplanNow">{{ tr('Guardar', 'Save') }}</button>
                    </div>
                </div>
            </div>

        <!-- Inherited event info modal -->
        <Teleport to="body">
            <div v-if="inheritedEventModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" @click.self="inheritedEventModal = null">
                <div class="bg-white rounded-lg shadow-lg max-w-md w-full p-6 space-y-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <h4 class="text-base font-semibold text-gray-900">{{ inheritedEventModal.title }}</h4>
                            <span
                                class="inline-block mt-1 text-[11px] font-semibold px-2 py-0.5 rounded"
                                :class="inheritedEventModal._source_level === 'district' ? 'bg-teal-100 text-teal-700' : 'bg-purple-100 text-purple-700'"
                            >
                                {{ inheritedEventModal._source_level === 'district' ? tr('Evento de distrito', 'District event') : tr('Evento de asociación', 'Association event') }}
                            </span>
                        </div>
                        <button class="text-gray-400 hover:text-gray-600 text-lg leading-none" @click="inheritedEventModal = null">✕</button>
                    </div>
                    <dl class="space-y-2 text-sm">
                        <div class="flex gap-2">
                            <dt class="text-gray-500 w-24 shrink-0">{{ tr('Fecha', 'Date') }}</dt>
                            <dd class="text-gray-900">{{ inheritedEventModal.date }}{{ inheritedEventModal.end_date && inheritedEventModal.end_date !== inheritedEventModal.date ? ' — ' + inheritedEventModal.end_date : '' }}</dd>
                        </div>
                        <div v-if="inheritedEventModal.start_time" class="flex gap-2">
                            <dt class="text-gray-500 w-24 shrink-0">{{ tr('Hora', 'Time') }}</dt>
                            <dd class="text-gray-900">{{ inheritedEventModal.start_time }}{{ inheritedEventModal.end_time ? ' – ' + inheritedEventModal.end_time : '' }}</dd>
                        </div>
                        <div v-if="inheritedEventModal.location" class="flex gap-2">
                            <dt class="text-gray-500 w-24 shrink-0">{{ tr('Lugar', 'Place') }}</dt>
                            <dd class="text-gray-900">{{ inheritedEventModal.location }}</dd>
                        </div>
                        <div v-if="inheritedEventModal.description" class="flex gap-2">
                            <dt class="text-gray-500 w-24 shrink-0">{{ tr('Descripción', 'Description') }}</dt>
                            <dd class="text-gray-900 whitespace-pre-line">{{ inheritedEventModal.description }}</dd>
                        </div>
                        <div v-if="inheritedEventModal.is_mandatory" class="flex gap-2">
                            <dt class="text-gray-500 w-24 shrink-0">{{ tr('Obligatorio', 'Required') }}</dt>
                            <dd class="text-gray-900">{{ tr('Sí', 'Yes') }}</dd>
                        </div>
                    </dl>
                    <div class="flex justify-end">
                        <button class="px-4 py-2 border rounded text-sm" @click="inheritedEventModal = null">{{ tr('Cerrar', 'Close') }}</button>
                    </div>
                </div>
            </div>
        </Teleport>

        </div>
    </PathfinderLayout>
</template>
