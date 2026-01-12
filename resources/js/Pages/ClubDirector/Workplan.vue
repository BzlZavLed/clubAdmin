<script setup>
import { computed, ref, onMounted, onBeforeUnmount, watch } from 'vue'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import { useGeneral } from '@/Composables/useGeneral'
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
    exportWorkplanToMyChurchAdmin
} from '@/Services/api'
import { ArrowDownTrayIcon, CalendarDaysIcon } from '@heroicons/vue/24/outline'
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
    }
})
const { showToast } = useGeneral()

const isDirector = computed(() => props.auth_user?.profile_type === 'club_director')
const isStaff = computed(() => props.auth_user?.profile_type === 'club_personal')
const isReadOnly = computed(() => !isDirector.value)
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

const normalizeEvents = (list = []) => list.map(ev => ({
    ...ev,
    classPlans: ev.classPlans || ev.class_plans || []
}))

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
    start_time: '',
    end_time: '',
    meeting_type: 'special',
    title: '',
    description: '',
    location: '',
    department_id: null,
    objective_id: null,
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
const workplanModalOpen = ref(false)
const exportModalOpen = ref(false)
const exportLoading = ref(false)
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
const planForm = ref({
    workplan_event_id: null,
    class_id: null,
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

const departmentsOptions = computed(() => props.integration_config?.departments || [])
const objectivesOptions = computed(() => props.integration_config?.objectives || [])

const getEventDepartmentId = (ev) => {
    return ev.department_id || exportForm.value.department_id || ''
}

const getDepartmentName = (departmentId) => {
    const dept = departmentsOptions.value.find(d => String(d.id) === String(departmentId))
    return dept?.name || '—'
}

const objectiveMatchesDepartment = (objectiveId, departmentId) => {
    if (!objectiveId || !departmentId) return false
    const obj = objectivesOptions.value.find(o => String(o.id) === String(objectiveId))
    if (!obj) return false
    return String(obj.department_id) === String(departmentId)
}

const objectivesForDepartment = (departmentId) => {
    if (!departmentId) return objectivesOptions.value
    return objectivesOptions.value.filter(o => String(o.department_id) === String(departmentId))
}

const missingObjectiveEvents = computed(() => {
    return events.value.filter(ev => {
        const deptId = getEventDepartmentId(ev)
        if (!ev.objective_id) return true
        if (!deptId) return true
        return !objectiveMatchesDepartment(ev.objective_id, deptId)
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
        showToast('Failed to preview changes', 'error')
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
        showToast('Workplan updated')
    } catch (error) {
        console.error(error)
        showToast('Failed to apply changes', 'error')
    }
}

function openEventModal(ev = null, date = null) {
    if (isReadOnly.value || !hasClubSelected.value) return
    editingEvent.value = ev
    eventForm.value = {
        date: normalizeDate(ev?.date) || date || todayIso,
        start_time: trimTime(ev?.start_time),
        end_time: trimTime(ev?.end_time),
        meeting_type: ev?.meeting_type || 'special',
        title: ev?.title || '',
        description: ev?.description || '',
        location: ev?.location || defaultLocation(ev?.meeting_type || 'special'),
        department_id: ev?.department_id ?? null,
        objective_id: ev?.objective_id ?? null,
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
    const clubName = props.clubs.find(c => String(c.id) === String(selectedClubId.value))?.club_name || 'Club'
    return `Plan anual ${clubName} ${year}`
}

function openExportModal() {
    if (!hasClubSelected.value || !isDirector.value) return
    if (!props.workplan?.id) {
        showToast('Create a workplan before exporting', 'warning')
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
        const matches = objectiveMatchesDepartment(ev.objective_id, deptId)
        objectiveAssignments.value[ev.id] = matches ? ev.objective_id : ''
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
        showToast('Select an objective for every event', 'warning')
        return
    }
    objectiveSaving.value = true
    try {
        for (const ev of missing) {
            const payload = {
                date: normalizeDate(ev.date),
                start_time: trimTime(ev.start_time),
                end_time: trimTime(ev.end_time),
                meeting_type: ev.meeting_type,
                title: ev.title,
                description: ev.description,
                location: ev.location,
                department_id: ev.department_id || null,
                objective_id: objectiveAssignments.value[ev.id] || null,
            }
            const { event } = await updateWorkplanEvent(ev.id, payload)
            events.value = events.value.map(e => e.id === event.id ? normalizeEvents([event])[0] : e)
        }
        objectiveModalOpen.value = false
        await submitExport()
    } catch (error) {
        console.error(error)
        showToast('Failed to save objectives', 'error')
    } finally {
        objectiveSaving.value = false
    }
}

async function submitExport() {
    if (!hasClubSelected.value || !isDirector.value || exportLoading.value) return
    if (missingRecurrentEvents.value.length) {
        openObjectiveModal()
        return
    }
    if (missingSpecialEvents.value.length) {
        openObjectiveModal()
        return
    }
    exportLoading.value = true
    try {
        const payload = {
            calendar_year: exportForm.value.calendar_year,
            plan_name: exportForm.value.plan_name,
            publish_to_feed: exportForm.value.publish_to_feed,
            department_id: exportForm.value.department_id || null,
        }
        if (exportForm.value.church_slug) {
            payload.church_slug = exportForm.value.church_slug
        }
        console.log('Export payload', payload)
        const data = await exportWorkplanToMyChurchAdmin(payload)
        console.log('Export response', data)
        exportResponse.value = data
        exportResponseOpen.value = true
        showToast(`Exported ${data.sent_events || data.imported || 0} events`)
    } catch (error) {
        console.error(error)
        const message = error?.response?.data?.message || 'Export failed'
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
        showToast('Workplan created')
        // Refresh to sync props and state (ensures no stale defaults)
        window.location.reload()
    } catch (error) {
        console.error(error)
        showToast('Failed to create workplan', 'error')
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
        const payload = {
            ...eventForm.value,
            department_id: eventForm.value.department_id || null,
            objective_id: eventForm.value.objective_id || null,
        }
        if (editingEvent.value) {
            const { event } = await updateWorkplanEvent(editingEvent.value.id, payload)
            events.value = events.value.map(e => e.id === event.id ? normalizeEvents([event])[0] : e)
            showToast('Event updated')
        } else {
            const { event } = await createWorkplanEvent(payload)
            events.value = [...events.value, normalizeEvents([event])[0]]
            showToast('Event added')
        }
        eventModalOpen.value = false
        editingEvent.value = null
    } catch (error) {
        console.error(error)
        showToast('Could not save event', 'error')
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
            console.error('Location search failed', err)
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
            console.error('Location search failed', err)
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
    if (!confirm('Delete this event?')) return
    try {
        await deleteWorkplanEvent(ev.id)
        events.value = events.value.filter(e => e.id !== ev.id)
        showToast('Event deleted')
    } catch (error) {
        console.error(error)
        showToast('Failed to delete event', 'error')
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
    events.value = events.value.map(ev => {
        if (ev.id !== plan.workplan_event_id) return ev
        const existing = ev.classPlans || []
        const updatedPlans = existing.some(p => p.id === plan.id)
            ? existing.map(p => (p.id === plan.id ? plan : p))
            : [...existing, plan]
        return { ...ev, classPlans: updatedPlans }
    })
}

function selectMeeting(ev) {
    selectedEvent.value = ev
    editingPlanId.value = null
    planForm.value = {
        workplan_event_id: ev.id,
        class_id: userClassId.value,
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
        showToast('Select a meeting first', 'warning')
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
        showToast(editingPlanId.value ? 'Class plan updated' : 'Class plan submitted')
        editingPlanId.value = null
        planForm.value = {
            workplan_event_id: selectedEvent.value.id,
            class_id: userClassId.value,
            type: 'plan',
            title: '',
            description: '',
            requested_date: normalizeDate(selectedEvent.value.date),
            location_override: ''
        }
    } catch (e) {
        console.error(e)
        showToast('Failed to save class plan', 'error')
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
        showToast(`Plan ${payload.status}`)
    } catch (e) {
        console.error(e)
        showToast('Failed to update status', 'error')
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
        type: plan.type || 'plan',
        title: plan.title || '',
        description: plan.description || '',
        requested_date: normalizeDate(plan.requested_date || ev?.date),
        location_override: plan.location_override || ''
    }
    showToast('Edit the plan on the right and save.')
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
        }).catch(err => console.error('Failed to load staff profile', err))
    }
})

watch(planEvents, (list) => {
    if (!selectedEvent.value && list.length) {
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
</script>

<template>
    <PathfinderLayout>
        <template #title>Workplan</template>
        <div class="px-6 py-4 space-y-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="space-y-2">
                    <h1 class="text-2xl font-semibold text-gray-900">Club Workplan</h1>
                    <p class="text-sm text-gray-600">Calendar of club-wide Sabbath and Sunday meetings with special events.</p>
                    <div class="flex items-center gap-2">
                        <label class="text-sm text-gray-700">Club</label>
                        <template v-if="isDirector">
                            <select v-model="selectedClubId" class="border rounded px-3 py-1 text-sm">
                                <option value="">Select a club</option>
                                <option v-for="club in clubs" :key="club.id" :value="club.id">{{ club.club_name }}</option>
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
                            <span class="sr-only">Download PDF</span>
                        </a>
                        <a :href="hasClubSelected ? icsHref : '#'" target="_blank" class="p-2 rounded-md bg-white border text-sm text-gray-800 hover:bg-gray-50 inline-flex items-center gap-1" :class="!hasClubSelected && 'opacity-50 pointer-events-none'">
                            <CalendarDaysIcon class="w-4 h-4" />
                            <span class="sr-only">Download ICS</span>
                        </a>
                        <button
                            v-if="isDirector"
                            class="px-3 py-2 text-sm rounded-md bg-emerald-600 text-white"
                            :class="!hasClubSelected && 'opacity-50 pointer-events-none'"
                            type="button"
                            @click="openExportModal"
                        >
                            Exportar a mychurchadmin.net
                        </button>
                    </div>
                    <button class="text-sm text-blue-600 hover:underline" @click="showIcsHelp = true" type="button">How to add?</button>
                </div>
            </div>

            <div v-if="hasClubSelected" class="space-y-6">
                <div v-if="!props.workplan?.id" class="bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 rounded flex items-center justify-between">
                    <div>
                        <p class="font-semibold">No workplan created for this club.</p>
                        <p class="text-sm">Set the date range and defaults to start planning.</p>
                    </div>
                    <button class="px-3 py-2 bg-amber-600 text-white rounded text-sm" @click="openWorkplanModal">Create workplan</button>
                </div>

                <div class="bg-white shadow-sm rounded-lg p-4 border">
                    <WorkplanCalendar
                        :events="events"
                        :is-read-only="isReadOnly"
                        :can-add="!isReadOnly"
                        @add="date => openEventModal(null, date)"
                        @edit="openEventModal"
                    />
                </div>
            </div>

            <div v-if="hasClubSelected" class="bg-white shadow-sm rounded-lg p-4 border space-y-4">
                <div v-if="isStaff" class="space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2">
                        <div>
                            <h3 class="font-semibold text-gray-800">Class plans on meeting days</h3>
                            <p class="text-sm text-gray-600">Pick a scheduled Sabbath/Sunday meeting, then add your class plan or outing.</p>
                        </div>
                        <div class="text-xs text-gray-500">Select a meeting to start planning.</div>
                    </div>

                    <div class="grid md:grid-cols-2 gap-4">
                        <div class="rounded-lg p-3 border bg-white space-y-2">
                            <h4 class="font-semibold text-gray-800 text-sm">Meeting dates</h4>
                            <div v-if="planEvents.length" class="space-y-2 max-h-[360px] overflow-y-auto pr-1">
                                <button
                                    v-for="ev in planEvents"
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
                                            <div class="text-[11px] text-gray-600">{{ formatTimeRange(ev) || 'All day' }}</div>
                                            <div class="text-[11px] text-gray-600">Location: {{ ev.location || '—' }}</div>
                                        </div>
                                        <span v-if="ev.is_generated" class="text-[10px] px-2 py-0.5 rounded-full border border-black text-black bg-white inline-flex items-center justify-center">A</span>
                                    </div>
                                </button>
                            </div>
                            <div v-else class="text-sm text-gray-600 space-y-2">
                                <div>No scheduled meetings in range.</div>
                                <button
                                    class="px-3 py-1 border rounded text-sm text-blue-600 inline-flex items-center gap-1"
                                    type="button"
                                    @click="openEventModal()"
                                >
                                    Create a meeting
                                </button>
                            </div>
                        </div>

                        <div class="rounded-lg p-4 border bg-gray-50 border-gray-200">
                            <div class="flex items-center justify-between mb-2">
                                <h4 class="font-semibold text-gray-800 text-sm">Create class plan</h4>
                                <span v-if="editingPlanId" class="text-xs text-amber-700 bg-amber-100 border border-amber-200 px-2 py-0.5 rounded">Editing</span>
                            </div>
                            <div v-if="selectedEvent" class="space-y-3">
                                <div class="text-xs text-gray-600 bg-white border rounded p-2">
                                    Meeting: {{ normalizeDate(selectedEvent.date) }} · {{ selectedEvent.title }} ({{ selectedEvent.meeting_type }})
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-700">Title</label>
                                    <input v-model="planForm.title" class="w-full border rounded px-3 py-2 text-sm" :disabled="!isStaff" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-700">Objective / Description</label>
                                    <textarea v-model="planForm.description" rows="3" class="w-full border rounded px-3 py-2 text-sm" :disabled="!isStaff"></textarea>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-sm text-gray-700">Type</label>
                                        <select v-model="planForm.type" class="w-full border rounded px-3 py-2 text-sm" :disabled="!isStaff">
                                            <option value="plan">Plan (on-site)</option>
                                            <option value="outing">Outing (needs approval)</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm text-gray-700">Date</label>
                                        <input type="date" v-model="planForm.requested_date" class="w-full border rounded px-3 py-2 text-sm" :disabled="!isStaff" />
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-700">Location override (for outings)</label>
                                    <input
                                        v-model="planForm.location_override"
                                        class="w-full border rounded px-3 py-2 text-sm"
                                        :disabled="!isStaff"
                                        placeholder="Optional"
                                        @input="searchPlanLocation(planForm.location_override)"
                                    />
                                    <div v-if="planLocationSuggestions.length"
                                        class="mt-1 border rounded bg-white shadow-sm max-h-40 overflow-y-auto text-sm">
                                        <button v-for="(opt, idx) in planLocationSuggestions" :key="idx" type="button"
                                            class="w-full text-left px-3 py-2 hover:bg-gray-100"
                                            @click="applyPlanLocation(opt)">
                                            {{ opt.label }}
                                        </button>
                                        <div v-if="planLocationLoading" class="px-3 py-2 text-xs text-gray-500">Searching…</div>
                                    </div>
                                </div>
                                <div class="flex justify-end">
                                    <button class="px-4 py-2 bg-blue-600 text-white rounded text-sm disabled:opacity-50" :disabled="!isStaff" @click="savePlan">
                                        Save plan
                                    </button>
                                </div>
                            </div>
                            <div v-else class="text-sm text-gray-600">Select a meeting to create a plan.</div>
                        </div>
                    </div>
                </div>

                <div class="border-t pt-4">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <h4 class="font-semibold text-gray-800">Plans summary</h4>
                            <span class="text-xs text-gray-500">Status updates shown in real-time.</span>
                        </div>
                        <div class="flex flex-wrap gap-2 items-center">
                            <template v-if="isDirector">
                                <label class="text-sm text-gray-700">Class</label>
                                <select v-model="classFilter" class="border rounded px-3 py-1 text-sm">
                                    <option value="">All</option>
                                    <option v-for="opt in classesOptions" :key="opt.id" :value="opt.id">{{ opt.name }}</option>
                                </select>
                                <label class="text-sm text-gray-700">Needs approval</label>
                                <input type="checkbox" v-model="needsApprovalOnly" class="mr-2">
                                <label class="text-sm text-gray-700">Status</label>
                                <select v-model="statusFilter" class="border rounded px-3 py-1 text-sm">
                                    <option value="all">All</option>
                                    <option value="pending">Pending</option>
                                    <option value="approved">Approved</option>
                                    <option value="rejected">Rejected</option>
                                </select>
                            </template>
                            <template v-else>
                                <span class="text-sm text-gray-700">Class: {{ classDisplayName }}</span>
                            </template>
                            <a :href="plansPdfHref" target="_blank" class="px-3 py-1 text-sm bg-white border rounded inline-flex items-center gap-1" :class="!hasClubSelected && 'opacity-50 pointer-events-none'">
                                Export PDF
                            </a>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-gray-500">
                                <tr>
                                    <th class="py-2 pr-4">Date</th>
                                    <th class="py-2 pr-4">Class</th>
                                    <th class="py-2 pr-4">Type</th>
                                    <th class="py-2 pr-4">Staff</th>
                                    <th class="py-2 pr-4">Status</th>
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
                                            <button class="text-blue-600 text-sm" @click="openPlanDetail(plan)">Open</button>
                                            <button
                                                v-if="isStaff && (plan.status === 'rejected' || plan.status === 'changes_requested')"
                                                class="text-amber-700 text-sm"
                                                @click="editPlan(plan)"
                                            >
                                                Update
                                            </button>
                                            <template v-if="isDirector">
                                                <button class="text-amber-700 text-sm" @click="openRequestModal(plan)">Request update</button>
                                                <button v-if="plan.status === 'submitted' || plan.status === 'changes_requested'" class="text-red-600 text-sm" @click="updatePlanStatus(plan, 'rejected')">Reject</button>
                                            </template>
                                        </div>
                                        <div v-if="plan.request_note" class="text-[11px] text-amber-800 bg-amber-50 border border-amber-200 rounded mt-2 p-2 text-left">
                                            Note: {{ plan.request_note }}
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="filteredPlans.length === 0">
                                    <td colspan="6" class="py-3 text-center text-gray-500">No plans submitted yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div v-if="hasClubSelected" class="bg-white shadow-sm rounded-lg p-4 border space-y-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <h3 class="font-semibold text-gray-800">Current workplan</h3>
                            <p class="text-sm text-gray-600">{{ form.start_date }} → {{ form.end_date }} ({{ form.timezone || 'No TZ set' }})</p>
                            <p class="text-xs text-gray-500">Defaults: Sabbath {{ form.default_sabbath_start_time || '—' }}-{{ form.default_sabbath_end_time || '—' }}, Sunday {{ form.default_sunday_start_time || '—' }}-{{ form.default_sunday_end_time || '—' }}</p>
                            <p v-if="isExpired" class="text-xs text-red-600 font-semibold mt-1">Current range has finished. Calendar is view-only; configure the next plan.</p>
                        </div>
                    </div>

                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold text-gray-800">Events list</h3>
                    <button v-if="!isReadOnly" class="px-3 py-2 text-sm rounded-md bg-amber-100 text-amber-800 border border-amber-200" @click="openEventModal()">Add special event</button>
                </div>
                <div v-if="isMobile" class="space-y-3">
                    <div v-for="ev in pagedEvents" :key="ev.id" class="border rounded-lg p-3 bg-white shadow-sm">
                        <div class="flex items-center justify-between">
                            <div class="text-sm font-semibold text-gray-900">{{ ev.title }}</div>
                            <span class="text-xs px-2 py-0.5 rounded border" :class="badgeColor(ev.meeting_type)">{{ ev.meeting_type }}</span>
                        </div>
                        <div class="text-sm text-gray-700 mt-1">{{ formatDateTime(ev) }}</div>
                        <div class="text-xs text-gray-600">{{ formatTimeRange(ev) }}</div>
                        <div class="text-xs text-gray-600">Location: {{ ev.location || '—' }}</div>
                        <div class="flex items-center justify-between mt-2">
                            <span v-if="ev.is_generated" class="text-[10px] px-2 py-0.5 rounded-full border border-black text-black bg-white">A</span>
                            <div class="flex gap-3" v-if="!isReadOnly">
                                <button class="text-blue-600 text-sm" @click="openEventModal(ev)">Edit</button>
                                <button class="text-red-600 text-sm" @click="removeEvent(ev)">Delete</button>
                            </div>
                        </div>
                    </div>
                    <div v-if="events.length === 0" class="text-sm text-gray-500 text-center py-4">No events yet.</div>
                    <div v-else class="flex items-center justify-between text-sm text-gray-700">
                        <button class="px-3 py-1 border rounded" :disabled="currentPage === 1" @click="prevPage">Prev</button>
                        <span>Page {{ currentPage }} / {{ totalPages }}</span>
                        <button class="px-3 py-1 border rounded" :disabled="currentPage === totalPages" @click="nextPage">Next</button>
                    </div>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-2 pr-4">Date</th>
                                <th class="py-2 pr-4">Type</th>
                                <th class="py-2 pr-4">Title</th>
                                <th class="py-2 pr-4">Time</th>
                                <th class="py-2 pr-4">Location</th>
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
                                    <button v-if="!isReadOnly" class="text-blue-600 text-sm mr-2" @click="openEventModal(ev)">Edit</button>
                                    <button v-if="!isReadOnly" class="text-red-600 text-sm" @click="removeEvent(ev)">Delete</button>
                                </td>
                            </tr>
                            <tr v-if="events.length === 0">
                                <td colspan="6" class="py-4 text-center text-gray-500">No events yet.</td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="events.length" class="flex items-center justify-between text-sm text-gray-700 mt-3">
                        <button class="px-3 py-1 border rounded" :disabled="currentPage === 1" @click="prevPage">Prev</button>
                        <span>Page {{ currentPage }} / {{ totalPages }}</span>
                        <button class="px-3 py-1 border rounded" :disabled="currentPage === totalPages" @click="nextPage">Next</button>
                    </div>
                </div>
            </div>

            <div v-else class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded">
                Select a club to view or manage its workplan.
            </div>

            <!-- Diff modal -->
            <div v-if="showDiffModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg max-w-xl w-full p-5">
                    <h4 class="text-lg font-semibold mb-3">Preview changes</h4>
                    <div class="space-y-3 max-h-[60vh] overflow-y-auto">
                        <div>
                            <h5 class="font-medium text-gray-800 mb-1">To add</h5>
                            <ul class="space-y-1 text-sm text-gray-700">
                                <li v-for="(item, idx) in previewDiff.adds" :key="`add-${idx}`">
                                    {{ item.date }} — {{ item.meeting_type }} ({{ item.title }})
                                </li>
                                <li v-if="previewDiff.adds.length === 0" class="text-gray-400">No additions</li>
                            </ul>
                        </div>
                        <div>
                            <h5 class="font-medium text-gray-800 mb-1">To remove</h5>
                            <p class="text-sm text-gray-700" v-if="previewDiff.removals.length">{{ previewDiff.removals.length }} generated meeting(s) will be removed (manual edits stay safe).</p>
                            <p class="text-sm text-gray-400" v-else>No removals</p>
                        </div>
                    </div>
                    <div class="mt-4 flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="showDiffModal = false">Cancel</button>
                        <button class="px-4 py-2 bg-red-600 text-white rounded" @click="applyChanges">Apply</button>
                    </div>
                </div>
            </div>

            <!-- Event modal -->
            <div v-if="eventModalOpen" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-5">
                    <h4 class="text-lg font-semibold mb-3">{{ editingEvent ? 'Edit event' : 'Add event' }}</h4>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="col-span-2">
                            <label class="block text-sm text-gray-600 mb-1">Title</label>
                            <input type="text" v-model="eventForm.title" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Date</label>
                            <input type="date" v-model="eventForm.date" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Type</label>
                            <select v-model="eventForm.meeting_type" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="sabbath">Sabbath</option>
                                <option value="sunday">Sunday</option>
                                <option value="special">Special</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Start time</label>
                            <input type="time" v-model="eventForm.start_time" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">End time</label>
                            <input type="time" v-model="eventForm.end_time" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-sm text-gray-600 mb-1">Location</label>
                            <div class="relative">
                                <input type="text" v-model="eventForm.location"
                                    class="w-full border rounded px-3 py-2 text-sm"
                                    @input="searchLocations(eventForm.location)" autocomplete="off">
                                <div v-if="locationLoading" class="absolute right-2 top-2 text-[11px] text-gray-500">…</div>
                                <div v-if="locationSuggestions.length"
                                    class="absolute z-30 mt-1 w-full bg-white border rounded shadow text-sm max-h-48 overflow-y-auto">
                                    <button v-for="(opt, idx) in locationSuggestions" :key="idx" type="button"
                                        class="w-full text-left px-3 py-2 hover:bg-gray-100"
                                        @click="applyLocation(opt)">
                                        {{ opt.label }}
                                    </button>
                                </div>
                            </div>
                            <p class="text-[11px] text-gray-500 mt-1">Search powered by OpenStreetMap (1 req/sec).</p>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Department</label>
                            <select v-model="eventForm.department_id" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="">Select</option>
                                <option v-for="dept in departmentsOptions" :key="`dept-${dept.id}`" :value="dept.id">
                                    {{ dept.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Objective</label>
                            <select v-model="eventForm.objective_id" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="">Select</option>
                                <option v-for="obj in objectivesOptions" :key="`obj-${obj.id}`" :value="obj.id">
                                    {{ obj.name }}
                                </option>
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-sm text-gray-600 mb-1">Description</label>
                            <textarea v-model="eventForm.description" rows="3" class="w-full border rounded px-3 py-2 text-sm"></textarea>
                        </div>
                    </div>
                    <div class="mt-4 flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="eventModalOpen = false">Cancel</button>
                        <button class="px-4 py-2 bg-red-600 text-white rounded" @click="saveEvent">{{ editingEvent ? 'Save changes' : 'Add event' }}</button>
                    </div>
                </div>
            </div>

            <!-- Export modal -->
            <div v-if="exportModalOpen" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg w-full max-w-3xl p-5 space-y-4">
                    <div class="flex items-start justify-between gap-2">
                        <h4 class="text-lg font-semibold">Exportar a mychurchadmin.net</h4>
                        <button class="text-gray-500" @click="exportModalOpen = false">✕</button>
                    </div>
                    <div class="text-sm text-gray-700">
                        This will send the current workplan events to the external calendar system.
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Calendar year</label>
                            <input type="number" min="2000" v-model.number="exportForm.calendar_year" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div class="flex items-end gap-2">
                            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" v-model="exportForm.publish_to_feed">
                                Publish to feed
                            </label>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-sm text-gray-600 mb-1">Department</label>
                            <select v-model="exportForm.department_id" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="">Select</option>
                                <option v-for="dept in departmentsOptions" :key="`export-dept-${dept.id}`" :value="dept.id">
                                    {{ dept.name }}
                                </option>
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-sm text-gray-600 mb-1">Plan name</label>
                            <input type="text" v-model="exportForm.plan_name" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-sm text-gray-600 mb-1">Church slug (optional override)</label>
                            <input type="text" v-model="exportForm.church_slug" class="w-full border rounded px-3 py-2 text-sm" placeholder="iglesia-x">
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="exportModalOpen = false">Cancel</button>
                        <button class="px-4 py-2 bg-emerald-600 text-white rounded disabled:opacity-60" :disabled="exportLoading" @click="submitExport">
                            {{ exportLoading ? 'Exporting...' : 'Export' }}
                        </button>
                    </div>
                    <button
                        v-if="!exportResponseOpen && (exportResponse || exportError)"
                        class="text-xs text-blue-600 underline"
                        @click="exportResponseOpen = true"
                    >
                        Show export summary
                    </button>
                    <div v-if="exportResponseOpen && (exportResponse || exportError)" class="border rounded bg-gray-50 p-3 text-xs text-gray-700 max-h-80 overflow-y-auto">
                        <div class="flex items-center justify-between mb-2">
                            <div class="font-semibold">Export summary</div>
                            <button class="text-xs text-gray-500" @click="exportResponseOpen = false">Hide</button>
                        </div>
                        <div v-if="exportResponse" class="space-y-3">
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-[11px]">
                                <div class="bg-white border rounded p-2">
                                    <div class="text-gray-500">Status</div>
                                    <div class="font-semibold">{{ exportResponse.status || 'ok' }}</div>
                                </div>
                                <div class="bg-white border rounded p-2">
                                    <div class="text-gray-500">Imported</div>
                                    <div class="font-semibold">{{ exportResponse.imported ?? exportResponse.sent_events ?? 0 }}</div>
                                </div>
                                <div class="bg-white border rounded p-2">
                                    <div class="text-gray-500">Skipped</div>
                                    <div class="font-semibold">{{ exportResponse.skipped ?? 0 }}</div>
                                </div>
                                <div class="bg-white border rounded p-2">
                                    <div class="text-gray-500">Conflicts</div>
                                    <div class="font-semibold">{{ exportResponse.conflicts?.length || 0 }}</div>
                                </div>
                            </div>
                            <div v-if="exportResponse.conflicts?.length" class="border rounded bg-white">
                                <div class="px-2 py-1 text-[11px] font-semibold text-gray-600 border-b">Conflicts</div>
                                <div class="max-h-48 overflow-y-auto">
                                    <table class="min-w-full text-[11px]">
                                        <thead class="text-left text-gray-500">
                                            <tr>
                                                <th class="py-1 px-2">Event</th>
                                                <th class="py-1 px-2">Issue</th>
                                                <th class="py-1 px-2">Type</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(conflict, idx) in exportResponse.conflicts" :key="`conflict-${idx}`" class="border-t">
                                                <td class="py-1 px-2">{{ conflict.incoming_title }}</td>
                                                <td class="py-1 px-2">{{ conflict.message }}</td>
                                                <td class="py-1 px-2">{{ conflict.conflict_type }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div v-if="exportResponse.successes?.length" class="border rounded bg-white">
                                <div class="px-2 py-1 text-[11px] font-semibold text-gray-600 border-b">Imported events</div>
                                <div class="max-h-32 overflow-y-auto">
                                    <table class="min-w-full text-[11px]">
                                        <thead class="text-left text-gray-500">
                                            <tr>
                                                <th class="py-1 px-2">Title</th>
                                                <th class="py-1 px-2">Start</th>
                                                <th class="py-1 px-2">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="row in exportResponse.successes" :key="`success-${row.external_id}`" class="border-t">
                                                <td class="py-1 px-2">{{ row.title }}</td>
                                                <td class="py-1 px-2">{{ row.start_at }}</td>
                                                <td class="py-1 px-2">{{ row.review_status || 'pending' }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div v-else-if="exportError" class="text-[11px] text-red-700">
                            <div class="font-semibold mb-1">Export error</div>
                            <div>{{ exportError.message || 'Export failed' }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Objective check modal (recurrent) -->
            <div v-if="objectiveModalOpen" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg w-full max-w-3xl p-5 space-y-4">
                    <div class="flex items-start justify-between gap-2">
                        <h4 class="text-lg font-semibold">Assign objectives before export</h4>
                        <button class="text-gray-500" @click="objectiveModalOpen = false">✕</button>
                    </div>
                    <div class="text-sm text-gray-700">
                        These recurrent events are missing an objective or have a mismatched one. Assign one to each event or apply a general objective to all.
                    </div>
                    <div class="flex flex-col sm:flex-row sm:items-end gap-3">
                        <div class="flex-1">
                            <label class="block text-sm text-gray-600 mb-1">General objective</label>
                            <select v-model="bulkObjectiveId" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="">Select</option>
                                <option v-for="obj in objectivesOptions" :key="`bulk-obj-${obj.id}`" :value="obj.id">
                                    {{ obj.name }}
                                </option>
                            </select>
                        </div>
                        <button
                            class="px-3 py-2 border rounded text-sm"
                            type="button"
                            @click="applyObjectiveToAll(objectiveTab === 'special' ? missingSpecialEvents : missingRecurrentEvents)"
                        >
                            Aplicar a todos
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
                                Recurrent events ({{ missingRecurrentEvents.length }})
                            </button>
                            <button
                                class="px-3 py-2"
                                :class="objectiveTab === 'special' ? 'font-semibold text-gray-900' : 'text-gray-600'"
                                type="button"
                                @click="objectiveTab = 'special'"
                            >
                                Special events ({{ missingSpecialEvents.length }})
                            </button>
                        </div>
                        <div class="max-h-[320px] overflow-y-auto">
                            <table class="min-w-full text-sm">
                                <thead class="text-left text-gray-500">
                                    <tr>
                                        <th class="py-2 px-3">Event</th>
                                        <th class="py-2 px-3">Date</th>
                                        <th class="py-2 px-3">Department</th>
                                        <th class="py-2 px-3">Objective</th>
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
                                                <option value="">Select</option>
                                                <option
                                                    v-for="obj in objectivesForDepartment(getEventDepartmentId(ev))"
                                                    :key="`obj-${ev.id}-${obj.id}`"
                                                    :value="obj.id"
                                                >
                                                    {{ obj.name }}
                                                </option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr v-if="objectiveTab === 'recurrent' && missingRecurrentEvents.length === 0">
                                        <td colspan="4" class="py-3 text-center text-gray-500">No missing objectives.</td>
                                    </tr>
                                    <tr v-else-if="objectiveTab === 'special' && missingSpecialEvents.length === 0">
                                        <td colspan="4" class="py-3 text-center text-gray-500">No missing objectives.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="objectiveModalOpen = false">Cancel</button>
                        <button class="px-4 py-2 bg-emerald-600 text-white rounded disabled:opacity-60" :disabled="objectiveSaving" @click="saveObjectivesAndExport">
                            {{ objectiveSaving ? 'Saving...' : 'Save and export' }}
                        </button>
                    </div>
                </div>
            </div>
            <!-- Plan detail modal -->
            <div v-if="planDetailOpen" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-5 space-y-4">
                    <div class="flex items-center justify-between">
                        <h4 class="text-lg font-semibold">Plan details</h4>
                        <button class="text-gray-500" @click="planDetailOpen = false">✕</button>
                    </div>
                    <div v-if="planDetail" class="space-y-2 text-sm text-gray-700">
                        <div class="text-xs text-gray-600">
                            Meeting: {{ normalizeDate(planDetail._event?.date) }} · {{ planDetail._event?.title }} ({{ planDetail._event?.meeting_type }})
                        </div>
                        <div><span class="font-semibold">Title:</span> {{ planDetail.title }}</div>
                        <div><span class="font-semibold">Type:</span> {{ planDetail.type }}</div>
                        <div v-if="planDetail.description"><span class="font-semibold">Description:</span> {{ planDetail.description }}</div>
                        <div><span class="font-semibold">Requested date:</span> {{ normalizeDate(planDetail.requested_date) || '—' }}</div>
                        <div><span class="font-semibold">Location override:</span> {{ planDetail.location_override || '—' }}</div>
                        <div><span class="font-semibold">Class:</span> {{ planDetail.class?.class_name || '—' }}</div>
                        <div><span class="font-semibold">Staff:</span> {{ planDetail.staff?.name || planDetail.staff?.user?.name || '—' }}</div>
                        <div>
                            <span class="font-semibold">Status:</span>
                            <span class="text-[11px] px-2 py-0.5 rounded-full inline-block" :class="planStatusClass(planDetail.status)">{{ planDetail.status }}</span>
                            <span class="ml-2 text-xs text-gray-500" v-if="planDetail.requires_approval">(Requires approval)</span>
                        </div>
                        <div v-if="planDetail.request_note" class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded p-2">
                            <span class="font-semibold">Director note:</span> {{ planDetail.request_note }}
                        </div>
                    </div>
                    <div class="flex justify-end gap-2" v-if="isDirector && planDetail?.requires_approval && planDetail.status === 'submitted'">
                        <button class="px-3 py-2 border rounded text-red-700 border-red-500" @click="updatePlanStatus(planDetail, 'rejected')">Reject</button>
                        <button class="px-3 py-2 border rounded text-green-700 border-green-500" @click="updatePlanStatus(planDetail, 'approved')">Approve</button>
                    </div>
                    <div class="flex justify-end" v-else>
                        <button class="px-4 py-2 border rounded" @click="planDetailOpen = false">Close</button>
                    </div>
                </div>
            </div>

            <!-- ICS Help Modal -->
            <div v-if="showIcsHelp" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-5 space-y-4">
                    <h4 class="text-lg font-semibold">Add ICS to your calendar</h4>
                    <div class="space-y-2 text-sm text-gray-700">
                        <p class="font-medium text-gray-800">iOS (iPhone/iPad)</p>
                        <ol class="list-decimal list-inside space-y-1">
                            <li>Tap the “Download ICS” link.</li>
                            <li>Choose “Open in Calendar” (or share to Calendar).</li>
                            <li>Tap “Add All” or select events to import.</li>
                        </ol>
                        <p class="font-medium text-gray-800 pt-2">Google Calendar (web)</p>
                        <ol class="list-decimal list-inside space-y-1">
                            <li>Download the .ics file.</li>
                            <li>Go to calendar.google.com → “Other calendars” → “Import”.</li>
                            <li>Upload the .ics file and pick the calendar to add to.</li>
                        </ol>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="showIcsHelp = false">Close</button>
                    </div>
                </div>
            </div>

            <!-- Request update modal -->
            <div v-if="requestModalOpen" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-5 space-y-4">
                    <div class="flex items-start justify-between gap-2">
                        <h4 class="text-lg font-semibold">Request update</h4>
                        <button class="text-gray-500" @click="requestModalOpen = false">✕</button>
                    </div>
                    <div class="text-sm text-gray-700">
                        Add a note to send back to the staff member. The plan status will change to "changes requested".
                    </div>
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">Reason / requested changes</label>
                        <textarea v-model="requestNote" rows="4" class="w-full border rounded px-3 py-2 text-sm"></textarea>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="requestModalOpen = false">Cancel</button>
                        <button class="px-4 py-2 bg-amber-600 text-white rounded" @click="submitRequestNote">Send request</button>
                    </div>
                </div>
            </div>

            <!-- Create workplan modal -->
            <div v-if="workplanModalOpen" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg max-w-2xl w-full p-6 space-y-4">
                    <h4 class="text-lg font-semibold">Create workplan</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm text-gray-700">Start date</label>
                            <input type="date" v-model="form.start_date" class="w-full border rounded px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700">End date</label>
                            <input type="date" v-model="form.end_date" class="w-full border rounded px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700">Timezone</label>
                            <input v-model="form.timezone" class="w-full border rounded px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700">Sabbath location</label>
                            <input v-model="form.default_sabbath_location" class="w-full border rounded px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700">Sabbath start/end</label>
                            <div class="flex gap-2">
                                <input type="time" v-model="form.default_sabbath_start_time" class="w-full border rounded px-3 py-2 text-sm" />
                                <input type="time" v-model="form.default_sabbath_end_time" class="w-full border rounded px-3 py-2 text-sm" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700">Sunday location</label>
                            <input v-model="form.default_sunday_location" class="w-full border rounded px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700">Sunday start/end</label>
                            <div class="flex gap-2">
                                <input type="time" v-model="form.default_sunday_start_time" class="w-full border rounded px-3 py-2 text-sm" />
                                <input type="time" v-model="form.default_sunday_end_time" class="w-full border rounded px-3 py-2 text-sm" />
                            </div>
                        </div>
                        <div class="sm:col-span-2 space-y-3">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Sabbath recurrence (nth in month)</label>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="n in nthOptions"
                                        :key="'sab-'+n"
                                        type="button"
                                        class="px-3 py-1 rounded border text-sm"
                                        :class="recurrence.sabbath.includes(n) ? 'bg-amber-600 text-white border-amber-700' : 'bg-white text-gray-700'"
                                        @click="toggleNth('sabbath', n)"
                                    >
                                        {{ n }}{{ ['th','st','nd','rd'][n%10 > 3 ? 0 : n%10] || 'th' }} Sabbath
                                    </button>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Sunday recurrence (nth in month)</label>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="n in nthOptions"
                                        :key="'sun-'+n"
                                        type="button"
                                        class="px-3 py-1 rounded border text-sm"
                                        :class="recurrence.sunday.includes(n) ? 'bg-teal-600 text-white border-teal-700' : 'bg-white text-gray-700'"
                                        @click="toggleNth('sunday', n)"
                                    >
                                        {{ n }}{{ ['th','st','nd','rd'][n%10 > 3 ? 0 : n%10] || 'th' }} Sunday
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="workplanModalOpen = false">Cancel</button>
                        <button class="px-4 py-2 bg-blue-600 text-white rounded" @click="createWorkplanNow">Save</button>
                    </div>
                </div>
            </div>

        </div>
    </PathfinderLayout>
</template>
