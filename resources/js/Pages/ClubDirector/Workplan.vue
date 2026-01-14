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
    exportWorkplanToMyChurchAdmin,
    deleteWorkplan
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
console.log('Workplan props', props)

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
        showToast('No se pudo previsualizar los cambios', 'error')
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
        showToast('Plan de trabajo actualizado')
    } catch (error) {
        console.error(error)
        showToast('No se pudieron aplicar los cambios', 'error')
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
    showToast('Crea un plan de trabajo antes de exportar', 'warning')
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
        showToast('Selecciona un objetivo para cada evento', 'warning')
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
        showToast('No se pudieron guardar los objetivos', 'error')
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
        const message = error?.response?.data?.message || 'Fallo la exportacion'
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
        showToast('Plan de trabajo creado')
        // Refresh to sync props and state (ensures no stale defaults)
        window.location.reload()
    } catch (error) {
        console.error(error)
        showToast('No se pudo crear el plan de trabajo', 'error')
    }
}

async function handleDeleteWorkplan() {
    if (!hasClubSelected.value || !props.workplan?.id) return
    const confirmDelete = confirm('¿Eliminar el plan de trabajo actual? Esto borrara todos los eventos programados del club.')
    if (!confirmDelete) return
    deletingWorkplan.value = true
    try {
        await deleteWorkplan(selectedClubId.value)
        showToast('Plan de trabajo eliminado')
        const redirect = safeRoute('club.workplan', { club_id: selectedClubId.value }, '/club-director/workplan')
        window.location.assign(redirect)
    } catch (error) {
        console.error(error)
        showToast('No se pudo eliminar el plan de trabajo', 'error')
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
        const payload = {
            ...eventForm.value,
            department_id: eventForm.value.department_id || null,
            objective_id: eventForm.value.objective_id || null,
        }
        if (editingEvent.value) {
            const { event } = await updateWorkplanEvent(editingEvent.value.id, payload)
            events.value = events.value.map(e => e.id === event.id ? normalizeEvents([event])[0] : e)
            showToast('Evento actualizado')
        } else {
            const { event } = await createWorkplanEvent(payload)
            events.value = [...events.value, normalizeEvents([event])[0]]
            showToast('Evento agregado')
        }
        eventModalOpen.value = false
        editingEvent.value = null
    } catch (error) {
        console.error(error)
        showToast('No se pudo guardar el evento', 'error')
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
    if (!confirm('¿Eliminar este evento?')) return
    try {
        await deleteWorkplanEvent(ev.id)
        events.value = events.value.filter(e => e.id !== ev.id)
        showToast('Evento eliminado')
    } catch (error) {
        console.error(error)
        showToast('No se pudo eliminar el evento', 'error')
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
        showToast('Selecciona una reunion primero', 'warning')
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
        showToast(editingPlanId.value ? 'Plan de clase actualizado' : 'Plan de clase enviado')
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
        showToast('No se pudo guardar el plan de clase', 'error')
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
        showToast('No se pudo actualizar el estado', 'error')
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
    showToast('Edita el plan a la derecha y guarda.')
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
        <template #title>Plan de trabajo</template>
        <div class="px-6 py-4 space-y-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="space-y-2">
                    <h1 class="text-2xl font-semibold text-gray-900">Plan de trabajo del club</h1>
                    <p class="text-sm text-gray-600">Calendario de reuniones sabaticas y dominicales del club con eventos especiales.</p>
                    <div class="flex items-center gap-2">
                        <label class="text-sm text-gray-700">Club</label>
                        <template v-if="isDirector">
                            <select v-model="selectedClubId" class="border rounded px-3 py-1 text-sm">
                                <option value="">Selecciona un club</option>
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
                            <span class="sr-only">Descargar PDF</span>
                        </a>
                        <a :href="hasClubSelected ? icsHref : '#'" target="_blank" class="p-2 rounded-md bg-white border text-sm text-gray-800 hover:bg-gray-50 inline-flex items-center gap-1" :class="!hasClubSelected && 'opacity-50 pointer-events-none'">
                            <CalendarDaysIcon class="w-4 h-4" />
                            <span class="sr-only">Descargar ICS</span>
                        </a>
                        <button
                            v-if="isDirector && props.workplan?.id"
                            class="px-3 py-2 text-sm rounded-md bg-emerald-600 text-white"
                            :class="!hasClubSelected && 'opacity-50 pointer-events-none'"
                            type="button"
                            @click="openExportModal"
                        >
                            Exportar a mychurchadmin.net
                        </button>
                        <button
                            v-if="isDirector && props.workplan?.id"
                            class="px-3 py-2 text-sm rounded-md bg-red-600 text-white"
                            :class="(!hasClubSelected || deletingWorkplan) && 'opacity-50 pointer-events-none'"
                            type="button"
                            @click="handleDeleteWorkplan"
                        >
                            Eliminar calendario
                        </button>
                    </div>
                    <button class="text-sm text-blue-600 hover:underline" @click="showIcsHelp = true" type="button">¿Como agregar?</button>
                </div>
            </div>

            <div v-if="hasClubSelected" class="space-y-6">
                <div v-if="!props.workplan?.id" class="bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 rounded flex items-center justify-between">
                    <div>
                        <p class="font-semibold">No hay plan de trabajo para este club.</p>
                        <p class="text-sm">Define el rango de fechas y valores predeterminados para comenzar.</p>
                    </div>
                    <button class="px-3 py-2 bg-amber-600 text-white rounded text-sm" @click="openWorkplanModal">Crear plan de trabajo</button>
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
                            <h3 class="font-semibold text-gray-800">Planes de clase en dias de reunion</h3>
                            <p class="text-sm text-gray-600">Elige una reunion sabatica/dominical y agrega tu plan de clase o salida.</p>
                        </div>
                        <div class="text-xs text-gray-500">Selecciona una reunion para comenzar.</div>
                    </div>

                    <div class="grid md:grid-cols-2 gap-4">
                        <div class="rounded-lg p-3 border bg-white space-y-2">
                            <h4 class="font-semibold text-gray-800 text-sm">Fechas de reunion</h4>
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
                                            <div class="text-[11px] text-gray-600">{{ formatTimeRange(ev) || 'Todo el dia' }}</div>
                                            <div class="text-[11px] text-gray-600">Ubicacion: {{ ev.location || '—' }}</div>
                                        </div>
                                        <span v-if="ev.is_generated" class="text-[10px] px-2 py-0.5 rounded-full border border-black text-black bg-white inline-flex items-center justify-center">A</span>
                                    </div>
                                </button>
                            </div>
                            <div v-else class="text-sm text-gray-600 space-y-2">
                                <div>No hay reuniones programadas en el rango.</div>
                                <button
                                    class="px-3 py-1 border rounded text-sm text-blue-600 inline-flex items-center gap-1"
                                    type="button"
                                    @click="openEventModal()"
                                >
                                    Crear una reunion
                                </button>
                            </div>
                        </div>

                        <div class="rounded-lg p-4 border bg-gray-50 border-gray-200">
                            <div class="flex items-center justify-between mb-2">
                                <h4 class="font-semibold text-gray-800 text-sm">Crear plan de clase</h4>
                                <span v-if="editingPlanId" class="text-xs text-amber-700 bg-amber-100 border border-amber-200 px-2 py-0.5 rounded">Editando</span>
                            </div>
                            <div v-if="selectedEvent" class="space-y-3">
                                <div class="text-xs text-gray-600 bg-white border rounded p-2">
                                    Reunion: {{ normalizeDate(selectedEvent.date) }} · {{ selectedEvent.title }} ({{ selectedEvent.meeting_type }})
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-700">Titulo</label>
                                    <input v-model="planForm.title" class="w-full border rounded px-3 py-2 text-sm" :disabled="!isStaff" />
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-700">Objetivo / Descripcion</label>
                                    <textarea v-model="planForm.description" rows="3" class="w-full border rounded px-3 py-2 text-sm" :disabled="!isStaff"></textarea>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-sm text-gray-700">Tipo</label>
                                        <select v-model="planForm.type" class="w-full border rounded px-3 py-2 text-sm" :disabled="!isStaff">
                                            <option value="plan">Plan (en sitio)</option>
                                            <option value="outing">Salida (requiere aprobacion)</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm text-gray-700">Fecha</label>
                                        <input type="date" v-model="planForm.requested_date" class="w-full border rounded px-3 py-2 text-sm" :disabled="!isStaff" />
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-700">Ubicacion alternativa (para salidas)</label>
                                    <input
                                        v-model="planForm.location_override"
                                        class="w-full border rounded px-3 py-2 text-sm"
                                        :disabled="!isStaff"
                                        placeholder="Opcional"
                                        @input="searchPlanLocation(planForm.location_override)"
                                    />
                                    <div v-if="planLocationSuggestions.length"
                                        class="mt-1 border rounded bg-white shadow-sm max-h-40 overflow-y-auto text-sm">
                                        <button v-for="(opt, idx) in planLocationSuggestions" :key="idx" type="button"
                                            class="w-full text-left px-3 py-2 hover:bg-gray-100"
                                            @click="applyPlanLocation(opt)">
                                            {{ opt.label }}
                                        </button>
                                        <div v-if="planLocationLoading" class="px-3 py-2 text-xs text-gray-500">Buscando…</div>
                                    </div>
                                </div>
                                <div class="flex justify-end">
                                    <button class="px-4 py-2 bg-blue-600 text-white rounded text-sm disabled:opacity-50" :disabled="!isStaff" @click="savePlan">
                                        Guardar plan
                                    </button>
                                </div>
                            </div>
                            <div v-else class="text-sm text-gray-600">Selecciona una reunion para crear un plan.</div>
                        </div>
                    </div>
                </div>

                <div class="border-t pt-4">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <h4 class="font-semibold text-gray-800">Planes por clases</h4>
                            <span class="text-xs text-gray-500">Actualizaciones de estado en tiempo real.</span>
                        </div>
                        <div class="flex flex-wrap gap-2 items-center">
                            <template v-if="isDirector">
                                <label class="text-sm text-gray-700">Clase</label>
                                <select v-model="classFilter" class="border rounded px-3 py-1 text-sm">
                                    <option value="">Todas</option>
                                    <option v-for="opt in classesOptions" :key="opt.id" :value="opt.id">{{ opt.name }}</option>
                                </select>
                                <label class="text-sm text-gray-700">Requiere aprobacion</label>
                                <input type="checkbox" v-model="needsApprovalOnly" class="mr-2">
                                <label class="text-sm text-gray-700">Estado</label>
                                <select v-model="statusFilter" class="border rounded px-3 py-1 text-sm">
                                    <option value="all">Todos</option>
                                    <option value="pending">Pendiente</option>
                                    <option value="approved">Aprobado</option>
                                    <option value="rejected">Rechazado</option>
                                </select>
                            </template>
                            <template v-else>
                                <span class="text-sm text-gray-700">Clase: {{ classDisplayName }}</span>
                            </template>
                            <a :href="plansPdfHref" target="_blank" class="px-3 py-1 text-sm bg-white border rounded inline-flex items-center gap-1" :class="!hasClubSelected && 'opacity-50 pointer-events-none'">
                                Exportar PDF
                            </a>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-gray-500">
                                <tr>
                                    <th class="py-2 pr-4">Fecha</th>
                                    <th class="py-2 pr-4">Clase</th>
                                    <th class="py-2 pr-4">Tipo</th>
                                    <th class="py-2 pr-4">Personal</th>
                                    <th class="py-2 pr-4">Estado</th>
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
                                            <button class="text-blue-600 text-sm" @click="openPlanDetail(plan)">Ver</button>
                                            <button
                                                v-if="isStaff && (plan.status === 'rejected' || plan.status === 'changes_requested')"
                                                class="text-amber-700 text-sm"
                                                @click="editPlan(plan)"
                                            >
                                                Actualizar
                                            </button>
                                            <template v-if="isDirector">
                                                <button class="text-amber-700 text-sm" @click="openRequestModal(plan)">Solicitar actualizacion</button>
                                                <button v-if="plan.status === 'submitted' || plan.status === 'changes_requested'" class="text-red-600 text-sm" @click="updatePlanStatus(plan, 'rejected')">Rechazar</button>
                                            </template>
                                        </div>
                                        <div v-if="plan.request_note" class="text-[11px] text-amber-800 bg-amber-50 border border-amber-200 rounded mt-2 p-2 text-left">
                                            Nota: {{ plan.request_note }}
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="filteredPlans.length === 0">
                                    <td colspan="6" class="py-3 text-center text-gray-500">No hay planes enviados.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div v-if="hasClubSelected" class="bg-white shadow-sm rounded-lg p-4 border space-y-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <h3 class="font-semibold text-gray-800">Plan de trabajo actual</h3>
                            <p class="text-sm text-gray-600">{{ form.start_date }} → {{ form.end_date }} ({{ form.timezone || 'Sin zona horaria' }})</p>
                            <p class="text-xs text-gray-500">Predeterminados: Sabado {{ form.default_sabbath_start_time || '—' }}-{{ form.default_sabbath_end_time || '—' }}, Domingo {{ form.default_sunday_start_time || '—' }}-{{ form.default_sunday_end_time || '—' }}</p>
                            <p v-if="isExpired" class="text-xs text-red-600 font-semibold mt-1">El rango actual ha finalizado. El calendario es solo lectura; configura el siguiente plan.</p>
                        </div>
                    </div>

                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold text-gray-800">Lista de eventos</h3>
                    <button v-if="!isReadOnly" class="px-3 py-2 text-sm rounded-md bg-amber-100 text-amber-800 border border-amber-200" @click="openEventModal()">Agregar evento especial</button>
                </div>
                <div v-if="isMobile" class="space-y-3">
                    <div v-for="ev in pagedEvents" :key="ev.id" class="border rounded-lg p-3 bg-white shadow-sm">
                        <div class="flex items-center justify-between">
                            <div class="text-sm font-semibold text-gray-900">{{ ev.title }}</div>
                            <span class="text-xs px-2 py-0.5 rounded border" :class="badgeColor(ev.meeting_type)">{{ ev.meeting_type }}</span>
                        </div>
                        <div class="text-sm text-gray-700 mt-1">{{ formatDateTime(ev) }}</div>
                        <div class="text-xs text-gray-600">{{ formatTimeRange(ev) }}</div>
                        <div class="text-xs text-gray-600">Ubicacion: {{ ev.location || '—' }}</div>
                        <div class="flex items-center justify-between mt-2">
                            <span v-if="ev.is_generated" class="text-[10px] px-2 py-0.5 rounded-full border border-black text-black bg-white">A</span>
                            <div class="flex gap-3" v-if="!isReadOnly">
                                <button class="text-blue-600 text-sm" @click="openEventModal(ev)">Editar</button>
                                <button class="text-red-600 text-sm" @click="removeEvent(ev)">Eliminar</button>
                            </div>
                        </div>
                    </div>
                    <div v-if="events.length === 0" class="text-sm text-gray-500 text-center py-4">No hay eventos.</div>
                    <div v-else class="flex items-center justify-between text-sm text-gray-700">
                        <button class="px-3 py-1 border rounded" :disabled="currentPage === 1" @click="prevPage">Anterior</button>
                        <span>Pagina {{ currentPage }} / {{ totalPages }}</span>
                        <button class="px-3 py-1 border rounded" :disabled="currentPage === totalPages" @click="nextPage">Siguiente</button>
                    </div>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-2 pr-4">Fecha</th>
                                <th class="py-2 pr-4">Tipo</th>
                                <th class="py-2 pr-4">Titulo</th>
                                <th class="py-2 pr-4">Hora</th>
                                <th class="py-2 pr-4">Ubicacion</th>
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
                                    <button v-if="!isReadOnly" class="text-blue-600 text-sm mr-2" @click="openEventModal(ev)">Editar</button>
                                    <button v-if="!isReadOnly" class="text-red-600 text-sm" @click="removeEvent(ev)">Eliminar</button>
                                </td>
                            </tr>
                            <tr v-if="events.length === 0">
                                <td colspan="6" class="py-4 text-center text-gray-500">No hay eventos.</td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="events.length" class="flex items-center justify-between text-sm text-gray-700 mt-3">
                        <button class="px-3 py-1 border rounded" :disabled="currentPage === 1" @click="prevPage">Anterior</button>
                        <span>Pagina {{ currentPage }} / {{ totalPages }}</span>
                        <button class="px-3 py-1 border rounded" :disabled="currentPage === totalPages" @click="nextPage">Siguiente</button>
                    </div>
                </div>
            </div>

            <div v-else class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded">
                Selecciona un club para ver o administrar su plan de trabajo.
            </div>

            <!-- Diff modal -->
            <div v-if="showDiffModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg max-w-xl w-full p-5">
                    <h4 class="text-lg font-semibold mb-3">Previsualizar cambios</h4>
                    <div class="space-y-3 max-h-[60vh] overflow-y-auto">
                        <div>
                            <h5 class="font-medium text-gray-800 mb-1">Para agregar</h5>
                            <ul class="space-y-1 text-sm text-gray-700">
                                <li v-for="(item, idx) in previewDiff.adds" :key="`add-${idx}`">
                                    {{ item.date }} — {{ item.meeting_type }} ({{ item.title }})
                                </li>
                                <li v-if="previewDiff.adds.length === 0" class="text-gray-400">Sin adiciones</li>
                            </ul>
                        </div>
                        <div>
                            <h5 class="font-medium text-gray-800 mb-1">Para eliminar</h5>
                            <p class="text-sm text-gray-700" v-if="previewDiff.removals.length">{{ previewDiff.removals.length }} reuniones generadas se eliminaran (las ediciones manuales se mantienen).</p>
                            <p class="text-sm text-gray-400" v-else>Sin eliminaciones</p>
                        </div>
                    </div>
                    <div class="mt-4 flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="showDiffModal = false">Cancelar</button>
                        <button class="px-4 py-2 bg-red-600 text-white rounded" @click="applyChanges">Aplicar</button>
                    </div>
                </div>
            </div>

            <!-- Event modal -->
            <div v-if="eventModalOpen" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-5">
                    <h4 class="text-lg font-semibold mb-3">{{ editingEvent ? 'Editar evento' : 'Agregar evento' }}</h4>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="col-span-2">
                            <label class="block text-sm text-gray-600 mb-1">Titulo</label>
                            <input type="text" v-model="eventForm.title" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Fecha</label>
                            <input type="date" v-model="eventForm.date" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Tipo</label>
                            <select v-model="eventForm.meeting_type" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="sabbath">Sabado</option>
                                <option value="sunday">Domingo</option>
                                <option value="special">Especial</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Hora de inicio</label>
                            <input type="time" v-model="eventForm.start_time" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Hora de fin</label>
                            <input type="time" v-model="eventForm.end_time" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-sm text-gray-600 mb-1">Ubicacion</label>
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
                            <p class="text-[11px] text-gray-500 mt-1">Busqueda con OpenStreetMap (1 req/seg).</p>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Departamento</label>
                            <select v-model="eventForm.department_id" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="">Seleccionar</option>
                                <option v-for="dept in departmentsOptions" :key="`dept-${dept.id}`" :value="dept.id">
                                    {{ dept.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Objetivo</label>
                            <select v-model="eventForm.objective_id" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="">Seleccionar</option>
                                <option v-for="obj in objectivesOptions" :key="`obj-${obj.id}`" :value="obj.id">
                                    {{ obj.name }}
                                </option>
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-sm text-gray-600 mb-1">Descripcion</label>
                            <textarea v-model="eventForm.description" rows="3" class="w-full border rounded px-3 py-2 text-sm"></textarea>
                        </div>
                    </div>
                    <div class="mt-4 flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="eventModalOpen = false">Cancelar</button>
                        <button class="px-4 py-2 bg-red-600 text-white rounded" @click="saveEvent">{{ editingEvent ? 'Guardar cambios' : 'Agregar evento' }}</button>
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
                        Esto enviara los eventos del plan de trabajo al sistema de calendario externo.
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Ano del calendario</label>
                            <input type="number" min="2000" v-model.number="exportForm.calendar_year" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div class="flex items-end gap-2">
                            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" v-model="exportForm.publish_to_feed">
                                Publicar en el feed
                            </label>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-sm text-gray-600 mb-1">Departamento</label>
                            <select v-model="exportForm.department_id" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="">Seleccionar</option>
                                <option v-for="dept in departmentsOptions" :key="`export-dept-${dept.id}`" :value="dept.id">
                                    {{ dept.name }}
                                </option>
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-sm text-gray-600 mb-1">Nombre del plan</label>
                            <input type="text" v-model="exportForm.plan_name" class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-sm text-gray-600 mb-1">Slug de iglesia (opcional)</label>
                            <input type="text" v-model="exportForm.church_slug" class="w-full border rounded px-3 py-2 text-sm" placeholder="iglesia-x">
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="exportModalOpen = false">Cancelar</button>
                        <button class="px-4 py-2 bg-emerald-600 text-white rounded disabled:opacity-60" @click="submitExport">
                            {{ exportLoading ? 'Exportando...' : 'Exportar' }}
                        </button>
                    </div>
                    <button
                        v-if="!exportResponseOpen && (exportResponse || exportError)"
                        class="text-xs text-blue-600 underline"
                        @click="exportResponseOpen = true"
                    >
                        Mostrar resumen de exportacion
                    </button>
                    <div v-if="exportResponseOpen && (exportResponse || exportError)" class="border rounded bg-gray-50 p-3 text-xs text-gray-700 max-h-80 overflow-y-auto">
                        <div class="flex items-center justify-between mb-2">
                            <div class="font-semibold">Resumen de exportacion</div>
                            <button class="text-xs text-gray-500" @click="exportResponseOpen = false">Ocultar</button>
                        </div>
                        <div v-if="exportResponse" class="space-y-3">
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-[11px]">
                                <div class="bg-white border rounded p-2">
                                    <div class="text-gray-500">Estado</div>
                                    <div class="font-semibold">{{ exportResponse.status || 'ok' }}</div>
                                </div>
                                <div class="bg-white border rounded p-2">
                                    <div class="text-gray-500">Importados</div>
                                    <div class="font-semibold">{{ exportResponse.imported ?? exportResponse.sent_events ?? 0 }}</div>
                                </div>
                                <div class="bg-white border rounded p-2">
                                    <div class="text-gray-500">Omitidos</div>
                                    <div class="font-semibold">{{ exportResponse.skipped ?? 0 }}</div>
                                </div>
                                <div class="bg-white border rounded p-2">
                                    <div class="text-gray-500">Conflictos</div>
                                    <div class="font-semibold">{{ exportResponse.conflicts?.length || 0 }}</div>
                                </div>
                            </div>
                            <div v-if="exportResponse.conflicts?.length" class="border rounded bg-white">
                                <div class="px-2 py-1 text-[11px] font-semibold text-gray-600 border-b">Conflictos</div>
                                <div class="max-h-48 overflow-y-auto">
                                    <table class="min-w-full text-[11px]">
                                        <thead class="text-left text-gray-500">
                                            <tr>
                                                <th class="py-1 px-2">Evento</th>
                                                <th class="py-1 px-2">Problema</th>
                                                <th class="py-1 px-2">Tipo</th>
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
                                <div class="px-2 py-1 text-[11px] font-semibold text-gray-600 border-b">Eventos importados</div>
                                <div class="max-h-32 overflow-y-auto">
                                    <table class="min-w-full text-[11px]">
                                        <thead class="text-left text-gray-500">
                                            <tr>
                                                <th class="py-1 px-2">Titulo</th>
                                                <th class="py-1 px-2">Inicio</th>
                                                <th class="py-1 px-2">Estado</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="row in exportResponse.successes" :key="`success-${row.external_id}`" class="border-t">
                                                <td class="py-1 px-2">{{ row.title }}</td>
                                                <td class="py-1 px-2">{{ row.start_at }}</td>
                                                <td class="py-1 px-2">{{ row.review_status || 'pendiente' }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div v-else-if="exportError" class="text-[11px] text-red-700">
                            <div class="font-semibold mb-1">Error de exportacion</div>
                            <div>{{ exportError.message || 'Fallo la exportacion' }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Objective check modal (recurrent) -->
            <div v-if="objectiveModalOpen" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg w-full max-w-3xl p-5 space-y-4">
                    <div class="flex items-start justify-between gap-2">
                        <h4 class="text-lg font-semibold">Asignar objetivos antes de exportar</h4>
                        <button class="text-gray-500" @click="objectiveModalOpen = false">✕</button>
                    </div>
                    <div class="text-sm text-gray-700">
                        Estos eventos recurrentes no tienen objetivo o tienen uno incorrecto. Asigna uno a cada evento o aplica un objetivo general a todos.
                    </div>
                    <div class="flex flex-col sm:flex-row sm:items-end gap-3">
                        <div class="flex-1">
                            <label class="block text-sm text-gray-600 mb-1">Objetivo general</label>
                            <select v-model="bulkObjectiveId" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="">Seleccionar</option>
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
                                Eventos recurrentes ({{ missingRecurrentEvents.length }})
                            </button>
                            <button
                                class="px-3 py-2"
                                :class="objectiveTab === 'special' ? 'font-semibold text-gray-900' : 'text-gray-600'"
                                type="button"
                                @click="objectiveTab = 'special'"
                            >
                                Eventos especiales ({{ missingSpecialEvents.length }})
                            </button>
                        </div>
                        <div class="max-h-[320px] overflow-y-auto">
                            <table class="min-w-full text-sm">
                                <thead class="text-left text-gray-500">
                                    <tr>
                                        <th class="py-2 px-3">Evento</th>
                                        <th class="py-2 px-3">Fecha</th>
                                        <th class="py-2 px-3">Departamento</th>
                                        <th class="py-2 px-3">Objetivo</th>
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
                                                <option value="">Seleccionar</option>
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
                                        <td colspan="4" class="py-3 text-center text-gray-500">No hay objetivos pendientes.</td>
                                    </tr>
                                    <tr v-else-if="objectiveTab === 'special' && missingSpecialEvents.length === 0">
                                        <td colspan="4" class="py-3 text-center text-gray-500">No hay objetivos pendientes.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="objectiveModalOpen = false">Cancelar</button>
                        <button class="px-4 py-2 bg-emerald-600 text-white rounded disabled:opacity-60" :disabled="objectiveSaving" @click="saveObjectivesAndExport">
                            {{ objectiveSaving ? 'Guardando...' : 'Guardar y exportar' }}
                        </button>
                    </div>
                </div>
            </div>
            <!-- Plan detail modal -->
            <div v-if="planDetailOpen" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-5 space-y-4">
                    <div class="flex items-center justify-between">
                        <h4 class="text-lg font-semibold">Detalles del plan</h4>
                        <button class="text-gray-500" @click="planDetailOpen = false">✕</button>
                    </div>
                    <div v-if="planDetail" class="space-y-2 text-sm text-gray-700">
                        <div class="text-xs text-gray-600">
                            Reunion: {{ normalizeDate(planDetail._event?.date) }} · {{ planDetail._event?.title }} ({{ planDetail._event?.meeting_type }})
                        </div>
                        <div><span class="font-semibold">Titulo:</span> {{ planDetail.title }}</div>
                        <div><span class="font-semibold">Tipo:</span> {{ planDetail.type }}</div>
                        <div v-if="planDetail.description"><span class="font-semibold">Descripcion:</span> {{ planDetail.description }}</div>
                        <div><span class="font-semibold">Fecha solicitada:</span> {{ normalizeDate(planDetail.requested_date) || '—' }}</div>
                        <div><span class="font-semibold">Ubicacion alternativa:</span> {{ planDetail.location_override || '—' }}</div>
                        <div><span class="font-semibold">Clase:</span> {{ planDetail.class?.class_name || '—' }}</div>
                        <div><span class="font-semibold">Personal:</span> {{ planDetail.staff?.name || planDetail.staff?.user?.name || '—' }}</div>
                        <div>
                            <span class="font-semibold">Estado:</span>
                            <span class="text-[11px] px-2 py-0.5 rounded-full inline-block" :class="planStatusClass(planDetail.status)">{{ planDetail.status }}</span>
                            <span class="ml-2 text-xs text-gray-500" v-if="planDetail.requires_approval">(Requiere aprobacion)</span>
                        </div>
                        <div v-if="planDetail.request_note" class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded p-2">
                            <span class="font-semibold">Nota del director:</span> {{ planDetail.request_note }}
                        </div>
                    </div>
                    <div class="flex justify-end gap-2" v-if="isDirector && planDetail?.requires_approval && planDetail.status === 'submitted'">
                        <button class="px-3 py-2 border rounded text-red-700 border-red-500" @click="updatePlanStatus(planDetail, 'rejected')">Rechazar</button>
                        <button class="px-3 py-2 border rounded text-green-700 border-green-500" @click="updatePlanStatus(planDetail, 'approved')">Aprobar</button>
                    </div>
                    <div class="flex justify-end" v-else>
                        <button class="px-4 py-2 border rounded" @click="planDetailOpen = false">Cerrar</button>
                    </div>
                </div>
            </div>

            <!-- ICS Help Modal -->
            <div v-if="showIcsHelp" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-5 space-y-4">
                    <h4 class="text-lg font-semibold">Agregar ICS a tu calendario</h4>
                    <div class="space-y-2 text-sm text-gray-700">
                        <p class="font-medium text-gray-800">iOS (iPhone/iPad)</p>
                        <ol class="list-decimal list-inside space-y-1">
                            <li>Toca el enlace “Descargar ICS”.</li>
                            <li>Elige “Abrir en Calendario” (o compartir a Calendario).</li>
                            <li>Toca “Agregar todos” o selecciona los eventos a importar.</li>
                        </ol>
                        <p class="font-medium text-gray-800 pt-2">Google Calendar (web)</p>
                        <ol class="list-decimal list-inside space-y-1">
                            <li>Descarga el archivo .ics.</li>
                            <li>Ve a calendar.google.com → “Otros calendarios” → “Importar”.</li>
                            <li>Sube el archivo .ics y elige el calendario.</li>
                        </ol>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="showIcsHelp = false">Cerrar</button>
                    </div>
                </div>
            </div>

            <!-- Request update modal -->
            <div v-if="requestModalOpen" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-5 space-y-4">
                    <div class="flex items-start justify-between gap-2">
                        <h4 class="text-lg font-semibold">Solicitar actualizacion</h4>
                        <button class="text-gray-500" @click="requestModalOpen = false">✕</button>
                    </div>
                    <div class="text-sm text-gray-700">
                        Agrega una nota para enviar al personal. El estado cambiara a "cambios solicitados".
                    </div>
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">Motivo / cambios solicitados</label>
                        <textarea v-model="requestNote" rows="4" class="w-full border rounded px-3 py-2 text-sm"></textarea>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="requestModalOpen = false">Cancelar</button>
                        <button class="px-4 py-2 bg-amber-600 text-white rounded" @click="submitRequestNote">Enviar solicitud</button>
                    </div>
                </div>
            </div>

            <!-- Create workplan modal -->
            <div v-if="workplanModalOpen" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg max-w-2xl w-full p-6 space-y-4">
                    <h4 class="text-lg font-semibold">Crear plan de trabajo</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm text-gray-700">Fecha de inicio</label>
                            <input type="date" v-model="form.start_date" class="w-full border rounded px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700">Fecha de fin</label>
                            <input type="date" v-model="form.end_date" class="w-full border rounded px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700">Zona horaria</label>
                            <input v-model="form.timezone" class="w-full border rounded px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700">Ubicacion sabatica</label>
                            <input v-model="form.default_sabbath_location" class="w-full border rounded px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700">Sabado inicio/fin</label>
                            <div class="flex gap-2">
                                <input type="time" v-model="form.default_sabbath_start_time" class="w-full border rounded px-3 py-2 text-sm" />
                                <input type="time" v-model="form.default_sabbath_end_time" class="w-full border rounded px-3 py-2 text-sm" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700">Ubicacion dominical</label>
                            <input v-model="form.default_sunday_location" class="w-full border rounded px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700">Domingo inicio/fin</label>
                            <div class="flex gap-2">
                                <input type="time" v-model="form.default_sunday_start_time" class="w-full border rounded px-3 py-2 text-sm" />
                                <input type="time" v-model="form.default_sunday_end_time" class="w-full border rounded px-3 py-2 text-sm" />
                            </div>
                        </div>
                        <div class="sm:col-span-2 space-y-3">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Recurrencia sabatica (n en el mes)</label>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="n in nthOptions"
                                        :key="'sab-'+n"
                                        type="button"
                                        class="px-3 py-1 rounded border text-sm"
                                        :class="recurrence.sabbath.includes(n) ? 'bg-amber-600 text-white border-amber-700' : 'bg-white text-gray-700'"
                                        @click="toggleNth('sabbath', n)"
                                    >
                                        {{ n }}{{ ['th','st','nd','rd'][n%10 > 3 ? 0 : n%10] || 'th' }} Sabado
                                    </button>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-1">Recurrencia dominical (n en el mes)</label>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="n in nthOptions"
                                        :key="'sun-'+n"
                                        type="button"
                                        class="px-3 py-1 rounded border text-sm"
                                        :class="recurrence.sunday.includes(n) ? 'bg-teal-600 text-white border-teal-700' : 'bg-white text-gray-700'"
                                        @click="toggleNth('sunday', n)"
                                    >
                                        {{ n }}{{ ['th','st','nd','rd'][n%10 > 3 ? 0 : n%10] || 'th' }} Domingo
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button class="px-4 py-2 border rounded" @click="workplanModalOpen = false">Cancelar</button>
                        <button class="px-4 py-2 bg-blue-600 text-white rounded" @click="createWorkplanNow">Guardar</button>
                    </div>
                </div>
            </div>

        </div>
    </PathfinderLayout>
</template>
