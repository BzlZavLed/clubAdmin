<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'
import PathfinderLayout from '@/Layouts/PathfinderLayout.vue'
import PlanSectionsAccordion from '@/Components/EventPlanner/PlanSectionsAccordion.vue'
import RecommendationsCards from '@/Components/EventPlanner/RecommendationsCards.vue'
import BudgetTable from '@/Components/EventPlanner/BudgetTable.vue'
import ParticipantsTable from '@/Components/EventPlanner/ParticipantsTable.vue'
import DocumentsUploader from '@/Components/EventPlanner/DocumentsUploader.vue'
import PlannerChat from '@/Components/EventPlanner/PlannerChat.vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    event: Object,
    eventPlan: Object,
    tasks: Array,
    budgetItems: Array,
    participants: Array,
    documents: Array,
    placeOptions: Array,
    members: Array,
    classes: Array,
    staff: Array,
    parents: Array,
    paymentSummary: Object,
    paymentConfig: Object,
    paymentRecords: Array,
    serpApiUsage: Object,
})

const activeTab = ref('tasks')
const { tr } = useLocale()

const eventState = ref(props.event)
const planState = ref(props.eventPlan)
const tasksState = ref(props.tasks || [])
const budgetState = ref(props.budgetItems || [])
const participantsState = ref(props.participants || [])
const documentsState = ref(props.documents || [])
const placeOptionsState = ref(props.placeOptions || [])
const membersState = ref(props.members || [])
const classesState = ref(props.classes || [])
const staffState = ref(props.staff || [])
const parentsState = ref(props.parents || [])
const paymentSummaryState = ref(props.paymentSummary || { total_received: 0, by_member_id: {}, by_staff_id: {} })
const paymentConfigState = ref(props.paymentConfig || { concept_id: null, concept_label: null, amount: null, is_payable: false })
const paymentRecordsState = ref(props.paymentRecords || [])
const serpApiUsageState = ref(props.serpApiUsage || { month: null, limit: 250, used: 0, remaining: 250 })
const transportMode = ref(planState.value?.plan_json?.transportation_mode || null)
const autoCreateBudgetItem = ref(planState.value?.plan_json?.preferences?.auto_create_budget_item || false)
const isPrivateTransport = computed(() => transportMode.value === 'private')
const isRentalTransport = computed(() => transportMode.value === 'rental')

const formatMoney = (value) => {
    const num = Number(value || 0)
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(num)
}

const toLocalInput = (value) => {
    if (!value) return ''
    const date = new Date(value)
    const pad = (n) => String(n).padStart(2, '0')
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

const toReservationDateTime = (value) => {
    if (!value) return ''
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return ''
    const pad = (n) => String(n).padStart(2, '0')
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`
}

const eventForm = ref({
    start_at: toLocalInput(eventState.value?.start_at),
    end_at: toLocalInput(eventState.value?.end_at),
    timezone: eventState.value?.timezone || 'America/New_York',
    is_payable: !!eventState.value?.is_payable,
    payment_amount: eventState.value?.payment_amount ?? '',
})
const eventFormSaving = ref(false)
const eventFormError = ref('')
const showEventModal = ref(false)

const saveEventBasics = async () => {
    eventFormSaving.value = true
    eventFormError.value = ''
    const payload = {
        title: eventState.value.title,
        event_type: eventState.value.event_type,
        start_at: eventForm.value.start_at,
        end_at: eventForm.value.end_at || null,
        timezone: eventForm.value.timezone,
        status: eventState.value.status,
        budget_estimated_total: eventState.value.budget_estimated_total,
        budget_actual_total: eventState.value.budget_actual_total,
        requires_approval: eventState.value.requires_approval,
        risk_level: eventState.value.risk_level,
        is_payable: eventForm.value.is_payable,
        payment_amount: eventForm.value.is_payable ? eventForm.value.payment_amount : null,
    }

    router.put(route('events.update', eventState.value.id), payload, {
        preserveScroll: true,
        onSuccess: () => {
            showEventModal.value = false
            eventFormSaving.value = false
        },
        onError: (errors) => {
            const firstError = Object.values(errors || {})[0]
            eventFormError.value = Array.isArray(firstError) ? firstError[0] : (firstError || tr('No se pudo actualizar el evento.', 'Unable to update event.'))
            eventFormSaving.value = false
        },
        onFinish: () => {
            eventFormSaving.value = false
        },
    })
}

const recommendations = computed(() => {
    const sections = planState.value?.plan_json?.sections || []
    const section = sections.find((item) => item.name === 'Recommendations')
    return section?.items || []
})

const selectedPlaceOption = computed(() => {
    const options = placeOptionsState.value || []
    return options.find((option) => option.status === 'confirmed')
        || options.find((option) => option.status === 'tentative')
        || null
})

const showRecommendations = ref(!selectedPlaceOption.value)

watch(selectedPlaceOption, (value) => {
    if (!value) {
        showRecommendations.value = true
        return
    }
    if (value.status === 'confirmed') {
        showRecommendations.value = false
    }
})

watch(
    () => planState.value?.plan_json?.preferences?.auto_create_budget_item,
    (value) => {
        autoCreateBudgetItem.value = !!value
    }
)

const selectedPlaceDetails = computed(() => {
    if (!selectedPlaceOption.value) return null
    const option = selectedPlaceOption.value
    const item = recommendations.value.find((entry) => entry.place_id === option.place_id)
    return {
        ...option,
        details: item || null,
    }
})

const checklistTasks = computed(() => (tasksState.value || []).filter((task) => {
    const meta = task.checklist_json || {}
    return meta?.source === 'event_checklist'
}))

const permissionSlipStats = computed(() => {
    const kids = (participantsState.value || []).filter((participant) => participant.role === 'kid')
    const required = kids.length
    if (!required) {
        return { required: 0, received: 0 }
    }

    const memberIds = new Set()
    const participantIds = new Set()
    kids.forEach((kid) => {
        if (kid.member_id) {
            memberIds.add(kid.member_id)
        } else if (kid.id) {
            participantIds.add(kid.id)
        }
    })

    const uploadedMemberIds = new Set()
    const uploadedParticipantIds = new Set()
    const docs = documentsState.value || []
    docs.forEach((doc) => {
        const docType = (doc?.doc_type || doc?.type || '').toLowerCase()
        if (!docType.includes('permission') && !docType.includes('slip')) return
        if (doc?.member_id) {
            uploadedMemberIds.add(doc.member_id)
        }
        const meta = doc?.meta_json || {}
        const metaMemberIds = Array.isArray(meta.member_ids) ? meta.member_ids : []
        metaMemberIds.forEach((id) => uploadedMemberIds.add(id))
        const metaParticipantIds = Array.isArray(meta.participant_ids) ? meta.participant_ids : []
        metaParticipantIds.forEach((id) => uploadedParticipantIds.add(id))
    })

    let received = 0
    memberIds.forEach((id) => {
        if (uploadedMemberIds.has(id)) {
            received += 1
        }
    })
    participantIds.forEach((id) => {
        if (uploadedParticipantIds.has(id)) {
            received += 1
        }
    })

    return { required, received }
})

const permissionSlipNames = computed(() => {
    const kids = (participantsState.value || []).filter((participant) => participant.role === 'kid')
    if (!kids.length) return []

    const kidByMemberId = new Map()
    const kidByParticipantId = new Map()
    kids.forEach((kid) => {
        if (kid.member_id) {
            kidByMemberId.set(kid.member_id, kid.participant_name)
        } else if (kid.id) {
            kidByParticipantId.set(kid.id, kid.participant_name)
        }
    })

    const collectedNames = new Set()
    const docs = documentsState.value || []
    docs.forEach((doc) => {
        const docType = (doc?.doc_type || doc?.type || '').toLowerCase()
        if (!docType.includes('permission') && !docType.includes('slip')) return

        if (doc?.member_id && kidByMemberId.has(doc.member_id)) {
            collectedNames.add(kidByMemberId.get(doc.member_id))
        }

        const meta = doc?.meta_json || {}
        const metaMemberIds = Array.isArray(meta.member_ids) ? meta.member_ids : []
        metaMemberIds.forEach((id) => {
            if (kidByMemberId.has(id)) {
                collectedNames.add(kidByMemberId.get(id))
            }
        })

        const metaParticipantIds = Array.isArray(meta.participant_ids) ? meta.participant_ids : []
        metaParticipantIds.forEach((id) => {
            if (kidByParticipantId.has(id)) {
                collectedNames.add(kidByParticipantId.get(id))
            }
        })
    })

    return Array.from(collectedNames)
})

const outlineSections = computed(() => {
    const sections = (planState.value?.plan_json?.sections || [])
        .filter((section) => (section.name || '') !== 'Recommendations')
    const permissionTask = checklistTasks.value.find((task) => isPermissionSlipTask(task))
    if (!permissionTask || permissionTask.status !== 'done') {
        return sections
    }

    const names = permissionSlipNames.value
    const permissionSection = {
        name: 'Permissions',
        summary: 'Parent permission collected for the following kids.',
        items: names.map((name) => ({ label: name })),
    }

    return [permissionSection, ...sections]
})

const taskKeyFromTitle = (title) => {
    const normalized = (title || '').toLowerCase()
    const mappings = [
        ['confirm date/time with venue', 'camp_reservation'],
        ['confirm date with venue', 'camp_reservation'],
        ['confirm venue', 'camp_reservation'],
        ['venue confirmation', 'camp_reservation'],
        ['collect permission slips', 'permission_slips'],
        ['permission slips', 'permission_slips'],
        ['finalize attendee list', 'finalize_attendee_list'],
        ['attendee list', 'finalize_attendee_list'],
        ['arrange transportation', 'transportation_plan'],
        ['transportation', 'transportation_plan'],
        ['emergency contact list', 'emergency_contacts'],
        ['emergency contacts', 'emergency_contacts'],
        ['assign chaperones', 'chaperone_assignments'],
        ['chaperones', 'chaperone_assignments'],
    ]
    for (const [needle, key] of mappings) {
        if (normalized.includes(needle)) {
            return key
        }
    }
    return null
}

const supportedFormTaskKeys = new Set([
    'permission_slips',
    'transportation_plan',
    'emergency_contacts',
    'chaperone_assignments',
    'camp_reservation',
])

const hasFormForTask = (task) => {
    const title = (task?.title || '').toLowerCase()
    const customSchema = task?.checklist_json?.custom_form_schema
    const hasCustom = Array.isArray(customSchema?.fields) && customSchema.fields.length > 0
    if (title.includes('campsite reservation confirmed')) {
        return hasCustom
    }
    const key = (task?.checklist_json?.task_key || taskKeyFromTitle(task?.title || '') || '').toLowerCase()
    return supportedFormTaskKeys.has(key) || hasCustom
}

const isFinalizeAttendeeTask = (task) => {
    const key = (task?.checklist_json?.task_key || taskKeyFromTitle(task?.title || '') || '').toLowerCase()
    const title = (task?.title || '').toLowerCase()
    return key === 'finalize_attendee_list' || title.includes('finalize attendee list')
}

const isPermissionSlipTask = (task) => {
    const key = (task?.checklist_json?.task_key || '').toLowerCase()
    if (key === 'permission_slips' || key === 'permission_slip') return true
    const title = (task?.title || '').toLowerCase()
    return title.includes('permission slip') || title.includes('permission slips')
}

const syncMissingItems = async (tasks = checklistTasks.value) => {
    const remaining = tasks
        .filter((task) => task.status !== 'done')
        .map((task) => task.title)
    const payload = {
        plan_json: planState.value?.plan_json || { sections: [] },
        missing_items_json: remaining,
    }

    const { data } = await axios.patch(route('event-plans.update', { event: eventState.value.id }), payload)
    planState.value = data.eventPlan
}

const toggleChecklistTask = async (task) => {
    const nextStatus = task.status === 'done' ? 'todo' : 'done'
    const { data } = await axios.put(route('event-tasks.update', { eventTask: task.id }), {
        title: task.title,
        description: task.description,
        assigned_to_user_id: task.assigned_to_user_id,
        due_at: task.due_at,
        status: nextStatus,
        checklist_json: task.checklist_json,
    })
    tasksState.value = tasksState.value.map((item) => item.id === task.id ? data.task : item)
    await syncMissingItems()
}

const newChecklistItem = ref('')
const activeFormTask = ref(null)
const activeFormTaskKey = ref(null)
const formSchema = ref(null)
const formData = ref({})
const formLoading = ref(false)
const formError = ref('')
const showCustomFormBuilder = ref(false)
const customFormTask = ref(null)
const customFormFields = ref([])
const customFormError = ref('')
const documentPreset = ref(null)
const showTransportModal = ref(false)
const transportDrivers = ref([])
const transportLoading = ref(false)
const transportError = ref('')
const transportNotice = ref('')
const transportNoticeType = ref('success')
const transportTask = ref(null)
const vehicleModalOpen = ref(false)
const vehicleForm = ref({ id: null, driver_id: null, vin: '', plate: '', make: '', model: '', year: '', insurance_doc_id: '' })
const driverLicenseNumbers = ref({})
const driverEditMode = ref({})
const newDriverName = ref('')
const addingDriver = ref(false)
const attendeeToast = ref({ show: false, type: 'success', message: '' })
const venueTotalEditedManually = ref(false)
let attendeeToastTimer = null

const showAttendeeToast = (message, type = 'success') => {
    if (attendeeToastTimer) {
        clearTimeout(attendeeToastTimer)
    }
    attendeeToast.value = { show: true, type, message }
    attendeeToastTimer = setTimeout(() => {
        attendeeToast.value = { show: false, type: 'success', message: '' }
    }, 3200)
}

const roundedMoney = (value) => {
    const num = Number(value || 0)
    if (!Number.isFinite(num)) return 0
    return Number(num.toFixed(2))
}

const computedVenueTotal = () => {
    const qty = Number(formData.value?.venue_qty ?? 0)
    const unit = Number(formData.value?.venue_unit_cost ?? 0)
    if (!Number.isFinite(qty) || !Number.isFinite(unit) || qty <= 0 || unit < 0) return 0
    return roundedMoney(qty * unit)
}

const applyAutoVenueTotal = () => {
    const total = computedVenueTotal()
    if (!total) return
    formData.value = {
        ...formData.value,
        venue_expected_total: total,
    }
}

const onVenueExpectedTotalInput = (value) => {
    venueTotalEditedManually.value = true
    formData.value = {
        ...formData.value,
        venue_expected_total: value,
    }
}

watch(
    () => [activeFormTaskKey.value, formData.value?.venue_qty, formData.value?.venue_unit_cost],
    () => {
        if (activeFormTaskKey.value !== 'camp_reservation') return
        if (venueTotalEditedManually.value) return
        const total = computedVenueTotal()
        if (!total) return
        const current = roundedMoney(formData.value?.venue_expected_total ?? 0)
        if (current === total) return
        formData.value = {
            ...formData.value,
            venue_expected_total: total,
        }
    }
)

const toSnake = (value) => String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')

const openCustomFormBuilderModal = (task) => {
    customFormTask.value = task
    const existing = task?.checklist_json?.custom_form_schema
    if (existing && Array.isArray(existing.fields) && existing.fields.length) {
        customFormFields.value = existing.fields.map((field) => ({
            key: field.key || '',
            label: field.label || '',
            type: field.type || 'text',
            required: !!field.required,
        }))
    } else {
        customFormFields.value = [{ key: '', label: '', type: 'text', required: false }]
    }
    customFormError.value = ''
    showCustomFormBuilder.value = true
}

const addCustomFormField = () => {
    customFormFields.value = [
        ...customFormFields.value,
        { key: '', label: '', type: 'text', required: false },
    ]
}

const removeCustomFormField = (index) => {
    customFormFields.value = customFormFields.value.filter((_, i) => i !== index)
}

const saveCustomFormDefinition = async () => {
    if (!customFormTask.value) return
    customFormError.value = ''

    const normalizedFields = customFormFields.value
        .map((field) => {
            const label = String(field.label || '').trim()
            const key = toSnake(field.key || label)
            return {
                key,
                label,
                type: field.type || 'text',
                required: !!field.required,
            }
        })
        .filter((field) => field.key && field.label)

    if (!normalizedFields.length) {
        customFormError.value = 'Add at least one valid field.'
        return
    }

    const keys = new Set()
    for (const field of normalizedFields) {
        if (keys.has(field.key)) {
            customFormError.value = `Duplicate field key: ${field.key}`
            return
        }
        keys.add(field.key)
    }

    const checklist = {
        ...(customFormTask.value.checklist_json || {}),
        source: (customFormTask.value.checklist_json || {}).source || 'event_checklist',
        custom_form_schema: { fields: normalizedFields },
    }
    if (!checklist.task_key) {
        checklist.task_key = taskKeyFromTitle(customFormTask.value.title)
    }

    const { data } = await axios.put(route('event-tasks.update', { eventTask: customFormTask.value.id }), {
        title: customFormTask.value.title,
        description: customFormTask.value.description,
        assigned_to_user_id: customFormTask.value.assigned_to_user_id,
        due_at: customFormTask.value.due_at,
        status: customFormTask.value.status,
        checklist_json: checklist,
    })
    tasksState.value = tasksState.value.map((item) => item.id === data.task.id ? data.task : item)
    showCustomFormBuilder.value = false
    customFormTask.value = null
    customFormFields.value = []
}

const addChecklistItem = async () => {
    const label = newChecklistItem.value.trim()
    if (!label) return
    const taskKey = taskKeyFromTitle(label)
    const { data } = await axios.post(route('event-tasks.store', { event: eventState.value.id }), {
        title: label,
        status: 'todo',
        checklist_json: { source: 'event_checklist', task_key: taskKey },
    })
    tasksState.value = [...tasksState.value, data.task]
    newChecklistItem.value = ''
    await syncMissingItems()
}

const removeChecklistTask = async (task) => {
    await axios.delete(route('event-tasks.destroy', { eventTask: task.id }))
    tasksState.value = tasksState.value.filter((item) => item.id !== task.id)
    await syncMissingItems()
}

const openTaskForm = async (task) => {
    const taskKey = task?.checklist_json?.task_key || taskKeyFromTitle(task?.title || '')
    const docKeywords = ['release', 'doc', 'slip', 'permission', 'medical', 'insurance', 'rental']
    const title = (task?.title || '').toLowerCase()
    const isFinalizeAttendeesTask = taskKey === 'finalize_attendee_list' || title.includes('finalize attendee list')
    if (isFinalizeAttendeesTask) {
        activeTab.value = 'participants'
        return
    }
    const isDocTask = taskKey
        ? docKeywords.some((word) => taskKey.includes(word))
        : docKeywords.some((word) => title.includes(word))
    if (isDocTask) {
        documentPreset.value = {
            docType: taskKey || 'permission_slip',
            taskId: task.id,
        }
        activeTab.value = 'documents'
        return
    }
    if (taskKey === 'transportation_plan') {
        transportTask.value = task
        await openTransportModal()
        return
    }
    formLoading.value = true
    formError.value = ''
    try {
        const { data } = await axios.get(route('event-tasks.form.show', { eventTask: task.id }))
        formSchema.value = data.schema?.schema_json || null
        const existingData = data.response?.data_json || data.prefill || {}
        formData.value = applyCampReservationDefaults(taskKey, existingData)
        activeFormTaskKey.value = data.schema?.key || taskKey || null
        activeFormTask.value = task
    } catch (error) {
        formError.value = error?.response?.data?.message || 'Unable to load form.'
        activeFormTask.value = task
        activeFormTaskKey.value = taskKey || null
        formSchema.value = null
        formData.value = {}
    } finally {
        formLoading.value = false
    }
}

const applyCampReservationDefaults = (taskKey, currentData) => {
    if (taskKey !== 'camp_reservation') {
        return currentData
    }
    if (currentData && Object.keys(currentData).length) {
        return currentData
    }
    const selected = selectedPlaceDetails.value || selectedPlaceOption.value
    if (!selected) {
        return currentData
    }
    const name = selected.details?.name || selected.name || ''
    const address = selected.details?.address || selected.address || ''
    const phone = selected.details?.phone || selected.phone || ''
    const contactParts = [address, phone].filter(Boolean)
    return {
        site_name: name,
        contact: contactParts.join(' • '),
        reservation_id: '',
        check_in: toReservationDateTime(eventState.value?.start_at),
        check_out: toReservationDateTime(eventState.value?.end_at),
    }
}

const confirmCampReservation = async () => {
    const selected = selectedPlaceOption.value
    if (selected?.id && selected.status !== 'confirmed') {
        const { data } = await axios.put(route('event-place-options.update', { eventPlaceOption: selected.id }), {
            status: 'confirmed',
        })
        placeOptionsState.value = placeOptionsState.value.map(option => option.id === data.place_option.id ? data.place_option : option)
    }

    showRecommendations.value = false

    const detailSource = selectedPlaceDetails.value || selected
    const details = detailSource?.details || {}

    const placeName = details.name || detailSource?.name || ''
    const address = details.address || detailSource?.address || ''
    const eta = details.duration_text || detailSource?.duration_text || detailSource?.eta || ''
    const phone = details.phone || detailSource?.phone || ''
    const reservationId = formData.value?.reservation_id || ''
    const checkIn = formData.value?.check_in || ''
    const checkOut = formData.value?.check_out || ''
    const contact = formData.value?.contact || ''
    const venueType = String(formData.value?.venue_type || '').trim()
    const venueUnitLabel = String(formData.value?.venue_unit_label || '').trim() || 'units'
    const venueQtyRaw = Number(formData.value?.venue_qty ?? formData.value?.spaces_qty ?? 0)
    const venueUnitCostRaw = Number(formData.value?.venue_unit_cost ?? 0)
    const venueExpectedTotalRaw = Number(formData.value?.venue_expected_total ?? 0)
    const venueQty = Number.isFinite(venueQtyRaw) && venueQtyRaw > 0 ? venueQtyRaw : 0
    const venueUnitCost = Number.isFinite(venueUnitCostRaw) && venueUnitCostRaw >= 0 ? venueUnitCostRaw : 0
    const venueExpectedTotal = Number.isFinite(venueExpectedTotalRaw) && venueExpectedTotalRaw > 0
        ? venueExpectedTotalRaw
        : (venueQty > 0 && venueUnitCost >= 0 ? venueQty * venueUnitCost : 0)
    const hasVenueCostSummary = venueQty > 0 || venueUnitCost > 0 || venueExpectedTotal > 0 || !!venueType

    const items = [
        placeName && { label: 'Place', detail: placeName },
        address && { label: 'Address', detail: address },
        eta && { label: 'ETA', detail: eta },
        reservationId && { label: 'Confirmation ID', detail: reservationId },
        checkIn && { label: 'Check-in', detail: checkIn },
        checkOut && { label: 'Check-out', detail: checkOut },
        contact && { label: 'Contact', detail: contact },
        phone && { label: 'Phone', detail: phone },
        venueType && { label: 'Venue Type', detail: venueType },
        hasVenueCostSummary && {
            label: 'Venue Cost Summary',
            detail: `Qty: ${venueQty || 0} ${venueUnitLabel} • Unit: ${formatMoney(venueUnitCost)} • Expected Total: ${formatMoney(venueExpectedTotal)}`,
        },
    ].filter(Boolean)

    const sections = planState.value?.plan_json?.sections || []
    const nextSections = sections.filter((section) => section.name !== 'Campsite Reservation')
    nextSections.unshift({
        name: 'Campsite Reservation',
        summary: 'Reservation details confirmed.',
        items,
    })

    const payload = {
        plan_json: { sections: nextSections },
        missing_items_json: planState.value?.missing_items_json || [],
    }

    const { data } = await axios.patch(route('event-plans.update', { event: eventState.value.id }), payload)
    planState.value = data.eventPlan
}

const confirmEmergencyContacts = async () => {
    const contactList = (formData.value?.contact_list || '').trim()
    const medicalNotes = (formData.value?.medical_notes || '').trim()
    const allergies = (formData.value?.allergies || '').trim()

    const byName = new Map()
    const ensure = (name) => {
        if (!byName.has(name)) {
            byName.set(name, { contact: '', allergies: [], medical: [] })
        }
        return byName.get(name)
    }

    if (contactList) {
        contactList.split('\n').map((line) => line.trim()).filter(Boolean).forEach((line) => {
            const [name, detail] = line.split(' — ')
            if (!name) return
            const entry = ensure(name.trim())
            entry.contact = (detail || '').trim()
        })
    }
    if (medicalNotes) {
        medicalNotes.split('\n').map((line) => line.trim()).filter(Boolean).forEach((line) => {
            const [name, detail] = line.split(':')
            if (!name) return
            const entry = ensure(name.trim())
            if (detail) entry.medical.push(detail.trim())
        })
    }
    if (allergies) {
        allergies.split('\n').map((line) => line.trim()).filter(Boolean).forEach((line) => {
            const [name, detail] = line.split(':')
            if (!name) return
            const entry = ensure(name.trim())
            if (detail) entry.allergies.push(detail.trim())
        })
    }

    const items = Array.from(byName.entries()).map(([name, entry]) => {
        const parts = []
        if (entry.contact) parts.push(`Emergency: ${entry.contact}`)
        if (entry.allergies.length) parts.push(`Allergies: ${entry.allergies.join('; ')}`)
        if (entry.medical.length) parts.push(`Medical: ${entry.medical.join('; ')}`)
        return { label: name, detail: parts.join(' | ') }
    })

    const sections = planState.value?.plan_json?.sections || []
    const nextSections = sections.filter((section) => section.name !== 'Emergency Contacts')
    nextSections.unshift({
        name: 'Emergency Contacts',
        summary: 'Emergency contacts, medical notes, and allergies.',
        items,
    })

    const payload = {
        plan_json: { sections: nextSections },
        missing_items_json: planState.value?.missing_items_json || [],
    }

    const { data } = await axios.patch(route('event-plans.update', { event: eventState.value.id }), payload)
    planState.value = data.eventPlan
}

const openTransportModal = async () => {
    showTransportModal.value = true
    transportLoading.value = true
    transportError.value = ''
    transportNotice.value = ''
    try {
        if (transportMode.value) {
            await ensureTransportBudgets(transportMode.value)
        }
        const { data } = await axios.get(route('event-drivers.index', { event: eventState.value.id }))
        transportDrivers.value = data.drivers || []
        driverLicenseNumbers.value = (data.drivers || []).reduce((acc, driver) => {
            acc[driver.participant_id] = driver.license_number || ''
            return acc
        }, {})
        const participants = (driverParticipants.value || []).map((participant) => participant.id)
        const mode = {}
        participants.forEach((participantId) => {
            const hasDriver = (data.drivers || []).some((driver) => driver.participant_id === participantId)
            mode[participantId] = !hasDriver
        })
        driverEditMode.value = mode
        await refreshTransportationCompletion()
    } catch (error) {
        transportError.value = error?.response?.data?.message || 'Unable to load drivers.'
    } finally {
        transportLoading.value = false
    }
}

const isDriverEditing = (participantId) => {
    if (Object.prototype.hasOwnProperty.call(driverEditMode.value, participantId)) {
        return !!driverEditMode.value[participantId]
    }
    return !driverRecordFor(participantId)
}

const setDriverEditing = (participantId, editing) => {
    driverEditMode.value = {
        ...driverEditMode.value,
        [participantId]: !!editing,
    }
}

const addDriverParticipant = async () => {
    const name = newDriverName.value.trim()
    if (!name) {
        transportNoticeType.value = 'error'
        transportNotice.value = 'Please enter a driver name.'
        return
    }

    const exists = (participantsState.value || []).some((participant) => {
        return participant.role === 'driver' && (participant.participant_name || '').toLowerCase() === name.toLowerCase()
    })
    if (exists) {
        transportNoticeType.value = 'error'
        transportNotice.value = `Driver "${name}" already exists.`
        return
    }

    addingDriver.value = true
    transportNotice.value = ''
    try {
        const { data } = await axios.post(route('event-participants.store', { event: eventState.value.id }), {
            participant_name: name,
            role: 'driver',
            status: 'invited',
        })

        const participant = data?.participant
        if (!participant) {
            throw new Error('No participant returned.')
        }

        participantsState.value = [...(participantsState.value || []), participant]
        driverLicenseNumbers.value = {
            ...driverLicenseNumbers.value,
            [participant.id]: '',
        }
        setDriverEditing(participant.id, true)
        newDriverName.value = ''
        transportNoticeType.value = 'success'
        transportNotice.value = `Driver "${participant.participant_name}" added.`
        await refreshTransportationCompletion()
    } catch (error) {
        transportNoticeType.value = 'error'
        transportNotice.value = error?.response?.data?.message || `Unable to add driver "${name}".`
    } finally {
        addingDriver.value = false
    }
}

const driverParticipants = computed(() => {
    return (participantsState.value || []).filter((participant) => participant.role === 'driver')
})

const driverRecordFor = (participantId) => {
    return transportDrivers.value.find((driver) => driver.participant_id === participantId)
}

const licenseDocs = computed(() => {
    return (documentsState.value || []).filter((doc) => {
        const docType = (doc?.doc_type || doc?.type || '').toLowerCase()
        return docType.includes('license')
    })
})

const insuranceDocs = computed(() => {
    return (documentsState.value || []).filter((doc) => {
        const docType = (doc?.doc_type || doc?.type || '').toLowerCase()
        return docType.includes('insurance') || docType.includes('rental')
    })
})

const docForDriver = (participantId) => {
    return (documentsState.value || []).find((doc) => doc.driver_participant_id === participantId)
}

const docForVehicle = (vehicleId) => {
    return (documentsState.value || []).find((doc) => {
        if (doc.vehicle_id !== vehicleId) return false
        const docType = (doc?.doc_type || doc?.type || '').toLowerCase()
        return docType.includes('insurance') || docType.includes('rental')
    })
}

const upsertDriver = async (participant, licenseNumber) => {
    transportNotice.value = ''
    try {
        const { data } = await axios.post(route('event-drivers.store', { event: eventState.value.id }), {
            participant_id: participant.id,
            license_number: licenseNumber || null,
        })
        const next = transportDrivers.value.filter((driver) => driver.id !== data.driver.id)
        transportDrivers.value = [...next, data.driver]
        await refreshTransportationCompletion()
        transportNoticeType.value = 'success'
        transportNotice.value = `Driver "${participant.participant_name}" saved successfully.`
        setDriverEditing(participant.id, false)
        return data.driver
    } catch (error) {
        transportNoticeType.value = 'error'
        transportNotice.value = error?.response?.data?.message || `Unable to save driver "${participant.participant_name}".`
        return null
    }
}

const updateDriverLicenseDoc = async (docId, participantId) => {
    if (!docId) return
    await axios.put(route('event-documents.update', { eventDocument: docId }), {
        driver_participant_id: participantId,
    })
    await refreshDocuments()
    await refreshTransportationCompletion()
}

const openVehicleModal = (driverId, vehicle = null) => {
    vehicleModalOpen.value = true
    vehicleForm.value = {
        id: vehicle?.id || null,
        driver_id: driverId,
        vin: vehicle?.vin || '',
        plate: vehicle?.plate || '',
        make: vehicle?.make || '',
        model: vehicle?.model || '',
        year: vehicle?.year || '',
        insurance_doc_id: docForVehicle(vehicle?.id || '')?.id || '',
    }
}

const openVehicleModalForParticipant = async (participant) => {
    if (!isPrivateTransport.value) return
    let driver = driverRecordFor(participant.id)
    if (!driver) {
        driver = await upsertDriver(participant, driverLicenseNumbers.value[participant.id])
    }
    if (driver?.id) {
        openVehicleModal(driver.id)
    }
}

const saveVehicle = async () => {
    if (!isPrivateTransport.value) return
    const payload = {
        vin: vehicleForm.value.vin,
        plate: vehicleForm.value.plate,
        make: vehicleForm.value.make,
        model: vehicleForm.value.model,
        year: vehicleForm.value.year,
    }
    let vehicle = null
    if (vehicleForm.value.id) {
        const { data } = await axios.put(route('event-vehicles.update', { eventVehicle: vehicleForm.value.id }), payload)
        vehicle = data.vehicle
    } else {
        const { data } = await axios.post(route('event-vehicles.store', { eventDriver: vehicleForm.value.driver_id }), payload)
        vehicle = data.vehicle
    }

    if (vehicleForm.value.insurance_doc_id) {
        await axios.put(route('event-documents.update', { eventDocument: vehicleForm.value.insurance_doc_id }), {
            vehicle_id: vehicle.id,
        })
        await refreshDocuments()
    }

    const { data } = await axios.get(route('event-drivers.index', { event: eventState.value.id }))
    transportDrivers.value = data.drivers || []
    vehicleModalOpen.value = false
    await refreshTransportationCompletion()
}

const deleteVehicle = async (vehicleId) => {
    await axios.delete(route('event-vehicles.destroy', { eventVehicle: vehicleId }))
    const { data } = await axios.get(route('event-drivers.index', { event: eventState.value.id }))
    transportDrivers.value = data.drivers || []
    await refreshTransportationCompletion()
}

const refreshTransportationCompletion = async () => {
    const transportTaskRef = transportTask.value
        || (tasksState.value || []).find((task) => task?.checklist_json?.task_key === 'transportation_plan')

    const driverList = driverParticipants.value

    const allComplete = driverList.length > 0 && driverList.every((participant) => {
        const driver = driverRecordFor(participant.id)
        if (!driver) return false
        const licenseNumber = String(driver.license_number ?? '').trim()
        if (!licenseNumber) return false
        const licenseDoc = docForDriver(participant.id)
        if (!licenseDoc) return false
        if (!isPrivateTransport.value) {
            return true
        }
        const vehicles = driver.vehicles || []
        if (!vehicles.length) return false
        return vehicles.every((vehicle) => !!docForVehicle(vehicle.id))
    })

    const nextStatus = allComplete ? 'done' : 'todo'
    if (transportTaskRef && transportTaskRef.status !== nextStatus) {
        const { data } = await axios.put(route('event-tasks.update', { eventTask: transportTaskRef.id }), {
            title: transportTaskRef.title,
            description: transportTaskRef.description,
            assigned_to_user_id: transportTaskRef.assigned_to_user_id,
            due_at: transportTaskRef.due_at,
            status: nextStatus,
            checklist_json: transportTaskRef.checklist_json,
        })
        transportTask.value = data.task
        tasksState.value = tasksState.value.map((item) => item.id === data.task.id ? data.task : item)
        await syncMissingItems()
    }

    if (allComplete) {
        await updateTransportationOutline()
    }
}

const refreshDocuments = async () => {
    const { data } = await axios.get(route('event-documents.index', { event: eventState.value.id }))
    documentsState.value = data.documents || []
}

const finishAttendeeList = async () => {
    try {
        const confirmed = (participantsState.value || []).filter((participant) => participant.status === 'confirmed')
        const attendeeItems = confirmed.map((participant) => ({
            label: participant.participant_name,
            detail: participant.role ? `Role: ${participant.role}` : null,
        }))

        const sections = planState.value?.plan_json?.sections || []
        const nextSections = sections.filter((section) => section.name !== 'Attendee List')
        nextSections.unshift({
            name: 'Attendee List',
            summary: 'Confirmed participants.',
            items: attendeeItems,
        })

        const planPayload = {
            plan_json: {
                ...(planState.value?.plan_json || {}),
                sections: nextSections,
            },
            missing_items_json: planState.value?.missing_items_json || [],
        }
        const { data: planData } = await axios.patch(route('event-plans.update', { event: eventState.value.id }), planPayload)
        planState.value = planData.eventPlan

        const finalizeTask = checklistTasks.value.find((task) => {
            const key = (task?.checklist_json?.task_key || '').toLowerCase()
            const title = (task?.title || '').toLowerCase()
            return key === 'finalize_attendee_list' || title.includes('finalize attendee list')
        })
        if (finalizeTask && finalizeTask.status !== 'done') {
            const { data: taskData } = await axios.put(route('event-tasks.update', { eventTask: finalizeTask.id }), {
                title: finalizeTask.title,
                description: finalizeTask.description,
                assigned_to_user_id: finalizeTask.assigned_to_user_id,
                due_at: finalizeTask.due_at,
                status: 'done',
                checklist_json: finalizeTask.checklist_json,
            })
            tasksState.value = tasksState.value.map((item) => item.id === taskData.task.id ? taskData.task : item)
            await syncMissingItems()
        }

        showAttendeeToast(`Attendee list finalized with ${confirmed.length} confirmed participant${confirmed.length === 1 ? '' : 's'}.`)
    } catch (error) {
        const message = error?.response?.data?.message || 'Unable to finalize attendee list.'
        showAttendeeToast(message, 'error')
    }
}

const updateTransportationOutline = async () => {
    const drivers = driverParticipants.value
    if (!drivers.length) return

    const items = drivers.map((participant) => {
        const driver = driverRecordFor(participant.id)
        const licenseDoc = docForDriver(participant.id)
        const vehicles = isPrivateTransport.value
            ? (driver?.vehicles || []).map((vehicle) => {
                const insurance = docForVehicle(vehicle.id)
                const summary = [
                    vehicle.make,
                    vehicle.model,
                    vehicle.year,
                    vehicle.plate ? `${tr('Placa', 'Plate')}: ${vehicle.plate}` : null,
                    vehicle.vin ? `${tr('VIN', 'VIN')}: ${vehicle.vin}` : null,
                    insurance ? `${tr('Cobertura', 'Coverage')}: ${insurance.title}` : `${tr('Cobertura', 'Coverage')}: ${tr('Faltante', 'Missing')}`,
                ].filter(Boolean).join(' • ')
                return summary
            })
            : []

        const detailParts = [
            driver?.license_number ? `License #: ${driver.license_number}` : 'License #: —',
            licenseDoc ? `License Doc: ${licenseDoc.title}` : 'License Doc: Missing',
            isPrivateTransport.value
                ? (vehicles.length ? `Vehicles: ${vehicles.join(' | ')}` : 'Vehicles: —')
                : 'Vehicles: Rental fleet (assigned at pickup).',
        ]

        return {
            label: participant.participant_name,
            detail: detailParts.join(' • '),
        }
    })

    const sections = planState.value?.plan_json?.sections || []
    const nextSections = sections.filter((section) => section.name !== 'Transportation')
    nextSections.unshift({
        name: 'Transportation',
        summary: isPrivateTransport.value
            ? 'Driver assignments and vehicle details.'
            : 'Driver assignments for rented transportation.',
        items,
    })

    const payload = {
        plan_json: { sections: nextSections },
        missing_items_json: planState.value?.missing_items_json || [],
    }

    const { data } = await axios.patch(route('event-plans.update', { event: eventState.value.id }), payload)
    planState.value = data.eventPlan
}

const closeTaskForm = () => {
    activeFormTask.value = null
    activeFormTaskKey.value = null
    venueTotalEditedManually.value = false
    formSchema.value = null
    formData.value = {}
    formError.value = ''
}

const upsertVenueBudgetFromForm = async () => {
    const quantityRaw = Number(formData.value?.venue_qty ?? formData.value?.spaces_qty ?? 0)
    const unitCostRaw = Number(formData.value?.venue_unit_cost ?? 0)
    const explicitTotal = Number(formData.value?.venue_expected_total ?? 0)

    let qty = Number.isFinite(quantityRaw) && quantityRaw > 0 ? quantityRaw : 0
    let total = Number.isFinite(explicitTotal) && explicitTotal > 0 ? explicitTotal : 0
    if (!total && qty > 0 && Number.isFinite(unitCostRaw) && unitCostRaw >= 0) {
        total = qty * unitCostRaw
    }
    if (!total) return

    if (!qty) qty = 1
    const unitCost = Number((total / qty).toFixed(2))
    const venueType = String(formData.value?.venue_type || '').trim()
    const siteName = String(formData.value?.site_name || '').trim()
    const unitLabel = String(formData.value?.venue_unit_label || 'spots').trim()
    const reservationId = String(formData.value?.reservation_id || '').trim()
    const checkIn = String(formData.value?.check_in || '').trim()
    const checkOut = String(formData.value?.check_out || '').trim()

    const label = siteName || venueType || 'Venue'
    const description = `${label} hosting (${qty} ${unitLabel})`
    const marker = 'source:venue_confirmation'
    const notes = [
        marker,
        reservationId ? `Reservation ID: ${reservationId}` : null,
        checkIn ? `Check-in: ${checkIn}` : null,
        checkOut ? `Check-out: ${checkOut}` : null,
    ].filter(Boolean).join(' | ')

    const payload = {
        category: 'Venue',
        description,
        qty,
        unit_cost: unitCost,
        notes,
    }

    const existing = (budgetState.value || []).find((item) => {
        return String(item?.category || '').toLowerCase() === 'venue'
            && String(item?.notes || '').includes(marker)
    })

    if (existing?.id) {
        const { data } = await axios.put(route('event-budget-items.update', { eventBudgetItem: existing.id }), payload)
        budgetState.value = (budgetState.value || []).map((item) => item.id === existing.id ? data.budget_item : item)
        return
    }

    const { data } = await axios.post(route('event-budget-items.store', { event: eventState.value.id }), payload)
    budgetState.value = [...(budgetState.value || []), data.budget_item]
}

const saveTaskForm = async () => {
    if (!activeFormTask.value) return
    formLoading.value = true
    try {
        await axios.put(route('event-tasks.form.update', { eventTask: activeFormTask.value.id }), {
            data_json: formData.value || {},
        })
        const taskKey = activeFormTaskKey.value || activeFormTask.value?.checklist_json?.task_key
        if (taskKey === 'camp_reservation') {
            await confirmCampReservation()
            await upsertVenueBudgetFromForm()
        }
        if (taskKey === 'emergency_contacts') {
            await confirmEmergencyContacts()
        }
        if (['camp_reservation', 'emergency_contacts'].includes(taskKey)) {
            const { data } = await axios.put(route('event-tasks.update', { eventTask: activeFormTask.value.id }), {
                title: activeFormTask.value.title,
                description: activeFormTask.value.description,
                assigned_to_user_id: activeFormTask.value.assigned_to_user_id,
                due_at: activeFormTask.value.due_at,
                status: 'done',
                checklist_json: activeFormTask.value.checklist_json,
            })
            tasksState.value = tasksState.value.map((item) => item.id === data.task.id ? data.task : item)
            await syncMissingItems()
        }
        closeTaskForm()
    } finally {
        formLoading.value = false
    }
}

const missingCount = computed(() => {
    if (checklistTasks.value.length) {
        return checklistTasks.value.filter((task) => task.status !== 'done').length
    }
    return planState.value?.missing_items_json?.length || 0
})

const expectedIncomeParticipantsCount = computed(() => {
    return (participantsState.value || []).filter((participant) => {
        const role = (participant?.role || '').toLowerCase()
        return role === 'kid'
    }).length
})

const expectedPaymentsTotal = computed(() => {
    if (!paymentConfigState.value?.is_payable) return 0
    const amount = Number(paymentConfigState.value?.amount || 0)
    if (!Number.isFinite(amount) || amount <= 0) return 0
    return Number((expectedIncomeParticipantsCount.value * amount).toFixed(2))
})

const seededChecklist = ref(false)

onMounted(async () => {
    if (seededChecklist.value) return
    const missing = planState.value?.missing_items_json || []
    if (!missing.length || checklistTasks.value.length) return

    seededChecklist.value = true
    for (const item of missing) {
        const label = typeof item === 'string' ? item : (item.label ?? '')
        if (!label) continue
        const taskKey = taskKeyFromTitle(label)
        const { data } = await axios.post(route('event-tasks.store', { event: eventState.value.id }), {
            title: label,
            status: 'todo',
            checklist_json: { source: 'event_checklist', task_key: taskKey },
        })
        tasksState.value = [...tasksState.value, data.task]
    }
    await syncMissingItems()
})

onUnmounted(() => {
    if (attendeeToastTimer) {
        clearTimeout(attendeeToastTimer)
        attendeeToastTimer = null
    }
})
const completeness = computed(() => {
    const sections = (planState.value?.plan_json?.sections || [])
        .filter((section) => (section.name || '') !== 'Recommendations')
    const placeOptions = placeOptionsState.value || []
    const hasConfirmedPlace = placeOptions.some((option) => option.status === 'confirmed')
    const hasTentativePlace = placeOptions.some((option) => option.status === 'tentative')
    const baseSignals = sections.length
        + (tasksState.value?.length || 0)
        + (budgetState.value?.length || 0)
        + (participantsState.value?.length || 0)
        + missingCount.value
    const placeWeight = hasConfirmedPlace ? 2 : (hasTentativePlace ? 1 : 0)
    const totalSignals = baseSignals + placeWeight

    if (baseSignals === 0) {
        const bonus = hasConfirmedPlace ? 25 : (hasTentativePlace ? 15 : 0)
        return bonus
    }
    const score = (totalSignals - missingCount.value) / totalSignals
    const bonus = hasConfirmedPlace ? 25 : (hasTentativePlace ? 15 : 0)
    return Math.max(0, Math.min(100, Math.round(score * 100 + bonus)))
})

const handlePlannerUpdate = (payload) => {
    eventState.value = payload.event
    planState.value = payload.eventPlan
    transportMode.value = payload.eventPlan?.plan_json?.transportation_mode || null
    autoCreateBudgetItem.value = payload.eventPlan?.plan_json?.preferences?.auto_create_budget_item || false
    tasksState.value = payload.tasks || []
    budgetState.value = payload.budget_items || []
    participantsState.value = payload.participants || []
    documentsState.value = payload.documents || []
    paymentSummaryState.value = payload.paymentSummary || paymentSummaryState.value
    paymentConfigState.value = payload.paymentConfig || paymentConfigState.value
    paymentRecordsState.value = payload.paymentRecords || paymentRecordsState.value
    serpApiUsageState.value = payload.serpApiUsage || serpApiUsageState.value
    eventForm.value = {
        start_at: toLocalInput(payload.event?.start_at),
        end_at: toLocalInput(payload.event?.end_at),
        timezone: payload.event?.timezone || 'America/New_York',
        is_payable: !!payload.event?.is_payable,
        payment_amount: payload.event?.payment_amount ?? '',
    }
}

const updateDocuments = (docs) => {
    documentsState.value = docs
    refreshTasks()
    refreshTransportationCompletion()
}

const updateParticipants = (participants) => {
    participantsState.value = participants
    refreshTasks()
    refreshTransportationCompletion()
}

const updateBudget = (items) => {
    budgetState.value = items || []
}

const handlePlaceOptionsUpdate = (updated) => {
    placeOptionsState.value = updated
    showRecommendations.value = !selectedPlaceOption.value
}

const refreshTasks = async () => {
    try {
        const { data } = await axios.get(route('event-tasks.index', { event: eventState.value.id }))
        tasksState.value = data.tasks || []
    } catch (error) {
        // ignore refresh failures to avoid blocking UI updates
    }
}

const saveTransportMode = async (mode) => {
    transportMode.value = mode
    const nextPlan = planState.value?.plan_json ? { ...planState.value.plan_json } : { sections: [] }
    nextPlan.transportation_mode = mode
    try {
        const { data } = await axios.patch(route('event-plans.update', { event: eventState.value.id }), {
            plan_json: nextPlan,
        })
        if (data?.eventPlan) {
            planState.value = data.eventPlan
        }
    } catch (error) {
        // ignore failures to avoid blocking UI
    }
}

const ensureTransportBudgets = async (_mode) => {
    // Transportation placeholders create confusing $0 rows.
    // Budget rows should come from explicit user input or AI estimate tools.
    return
}

const selectTransportMode = async (mode) => {
    await saveTransportMode(mode)
    await ensureTransportBudgets(mode)
    await refreshTransportationCompletion()
}

const clearTransportMode = async () => {
    await saveTransportMode(null)
    await refreshTransportationCompletion()
}

const saveBudgetPreference = async (value) => {
    autoCreateBudgetItem.value = value
    const nextPlan = planState.value?.plan_json ? { ...planState.value.plan_json } : { sections: [] }
    const preferences = nextPlan.preferences ? { ...nextPlan.preferences } : {}
    preferences.auto_create_budget_item = !!value
    nextPlan.preferences = preferences
    try {
        const { data } = await axios.patch(route('event-plans.update', { event: eventState.value.id }), {
            plan_json: nextPlan,
        })
        if (data?.eventPlan) {
            planState.value = data.eventPlan
        }
    } catch (error) {
        // ignore failures to avoid blocking UI
    }
}
</script>

<template>
    <PathfinderLayout>
        <template #title>{{ eventState.title }}</template>

        <div
            v-if="attendeeToast.show"
            class="fixed top-5 right-5 z-[60] rounded-lg border px-4 py-3 text-sm shadow-lg"
            :class="attendeeToast.type === 'error'
                ? 'border-red-200 bg-red-50 text-red-700'
                : 'border-green-200 bg-green-50 text-green-700'"
        >
            {{ attendeeToast.message }}
        </div>

        <div class="space-y-6">
            <div class="bg-white rounded-lg border p-4 relative">
                <button
                    type="button"
                    class="absolute top-3 right-3 text-gray-500 hover:text-gray-700"
                     :title="tr('Editar detalles del evento', 'Edit event details')"
                    @click="showEventModal = true"
                >
                    <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M13.586 3.586a2 2 0 112.828 2.828l-8.5 8.5a1 1 0 01-.39.243l-3 1a1 1 0 01-1.265-1.265l1-3a1 1 0 01.243-.39l8.5-8.5zM12.172 5L5 12.172V15h2.828L15 7.828 12.172 5z"/>
                    </svg>
                </button>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <div class="text-xs text-gray-500">{{ tr('Tipo de evento', 'Event Type') }}</div>
                        <div class="font-semibold text-gray-800">{{ eventState.event_type }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">{{ tr('Fecha', 'Date') }}</div>
                        <div class="font-semibold text-gray-800">{{ new Date(eventState.start_at).toLocaleString() }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">{{ tr('Estado', 'Status') }}</div>
                        <div class="font-semibold text-gray-800 capitalize">{{ eventState.status }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">{{ tr('Pagos recibidos', 'Payments Received') }}</div>
                        <div class="font-semibold text-gray-800">
                            {{ formatMoney(paymentSummaryState.total_received || 0) }}
                        </div>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="flex items-center justify-between text-sm">
                        <span>{{ tr('Avance', 'Completeness') }}</span>
                        <span>{{ completeness }}%</span>
                    </div>
                    <div class="h-2 bg-gray-200 rounded-full mt-1">
                        <div class="h-2 bg-green-500 rounded-full" :style="{ width: completeness + '%' }"></div>
                    </div>
                    <div class="text-xs text-gray-500 mt-1 relative inline-flex items-center gap-1 group">
                        <span>{{ tr('Pendientes', 'Missing items') }}: {{ missingCount }}</span>
                        <div v-if="missingCount" class="hidden group-hover:block absolute z-10 left-0 top-full mt-2 w-64 rounded-md border bg-white p-3 shadow-lg text-xs text-gray-700">
                            <div class="font-semibold text-gray-800 mb-2">{{ tr('Pendientes', 'Missing items') }}</div>
                            <div class="space-y-1">
                                <div v-for="task in checklistTasks.filter((entry) => entry.status !== 'done')" :key="task.id">
                                    • {{ task.title }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-if="showEventModal"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                @keydown.esc="showEventModal = false"
            >
                <div class="w-full max-w-lg rounded-lg bg-white border shadow-xl">
                    <div class="flex items-center justify-between px-5 py-4 border-b">
                        <h2 class="text-lg font-semibold text-gray-800">{{ tr('Editar detalles del evento', 'Edit Event Details') }}</h2>
                        <button type="button" class="text-gray-500 hover:text-gray-700" @click="showEventModal = false">×</button>
                    </div>
                    <div class="p-5 space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="text-xs text-gray-600">{{ tr('Inicio', 'Start') }}</label>
                                <input v-model="eventForm.start_at" type="datetime-local" class="w-full border rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="text-xs text-gray-600">{{ tr('Fin', 'End') }}</label>
                                <input v-model="eventForm.end_at" type="datetime-local" class="w-full border rounded px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="text-xs text-gray-600">{{ tr('Zona horaria', 'Timezone') }}</label>
                                <input v-model="eventForm.timezone" class="w-full border rounded px-3 py-2 text-sm" />
                            </div>
                            <div class="flex items-center gap-4">
                                <label class="text-xs text-gray-600 flex items-center gap-2">
                                    <input type="checkbox" v-model="eventForm.is_payable" />
                                    {{ tr('Evento con pago', 'Payable event') }}
                                </label>
                                <input
                                    v-model="eventForm.payment_amount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    :disabled="!eventForm.is_payable"
                                    class="w-28 border rounded px-3 py-2 text-sm disabled:bg-gray-100"
                                    placeholder="0.00"
                                />
                            </div>
                        </div>
                        <div v-if="eventFormError" class="text-sm text-red-600">
                            {{ eventFormError }}
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-2 px-5 py-4 border-t">
                        <button type="button" class="px-3 py-1 rounded text-sm bg-gray-200 text-gray-700" @click="showEventModal = false">
                            Cancel
                        </button>
                        <button
                            type="button"
                            class="px-3 py-1 rounded text-sm bg-blue-600 text-white disabled:opacity-60"
                            :disabled="eventFormSaving"
                            @click="saveEventBasics"
                        >
                            {{ eventFormSaving ? tr('Guardando...', 'Saving...') : tr('Guardar cambios', 'Save Changes') }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-6">
                    <div class="bg-white rounded-lg border p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h2 class="text-lg font-semibold text-gray-800">{{ tr('Lugares recomendados', 'Recommended Places') }}</h2>
                            <button
                                v-if="selectedPlaceOption && !showRecommendations && selectedPlaceOption.status !== 'confirmed'"
                                type="button"
                                class="text-sm text-blue-600 hover:text-blue-700"
                                @click="showRecommendations = true"
                            >
                                Find another place
                            </button>
                        </div>

                        <div v-if="selectedPlaceOption && !showRecommendations" class="rounded-md border bg-gray-50 p-4">
                            <div class="text-xs uppercase tracking-wide text-gray-500 mb-2">{{ tr('Lugar preseleccionado', 'Preselected Place') }} ({{ selectedPlaceOption.status }})</div>
                            <div class="text-lg font-semibold text-gray-800">
                                {{ selectedPlaceDetails?.details?.name || selectedPlaceOption.name }}
                            </div>
                            <div class="text-sm text-gray-600">
                                {{ selectedPlaceDetails?.details?.address || selectedPlaceOption.address || tr('Dirección no disponible', 'Address unavailable') }}
                            </div>
                            <div class="mt-2 text-sm text-gray-600 flex flex-wrap gap-3">
                                <span v-if="selectedPlaceDetails?.details?.rating || selectedPlaceOption.rating">
                                    {{ tr('Calificación', 'Rating') }}: {{ selectedPlaceDetails?.details?.rating ?? selectedPlaceOption.rating }} ★
                                </span>
                                <span v-if="selectedPlaceDetails?.details?.user_ratings_total || selectedPlaceOption.user_ratings_total">
                                    {{ tr('Reseñas', 'Reviews') }}: {{ selectedPlaceDetails?.details?.user_ratings_total ?? selectedPlaceOption.user_ratings_total }}
                                </span>
                                <span v-if="selectedPlaceDetails?.details?.phone || selectedPlaceOption.phone">
                                    {{ tr('Teléfono', 'Phone') }}: {{ selectedPlaceDetails?.details?.phone ?? selectedPlaceOption.phone }}
                                </span>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-3 text-sm">
                                <a
                                    v-if="selectedPlaceDetails?.details?.maps_url"
                                    :href="selectedPlaceDetails.details.maps_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-blue-600 hover:text-blue-700"
                                >
                                    View on Google Maps
                                </a>
                                <button
                                    v-if="selectedPlaceOption.status !== 'confirmed'"
                                    type="button"
                                    class="text-gray-600 hover:text-gray-800"
                                    @click="showRecommendations = true"
                                >
                                    Change selection
                                </button>
                                <div v-else class="text-xs text-gray-500">
                                    {{ tr('¿Necesitas un nuevo lugar? Pide al planificador otro y cancelaremos la selección actual.', 'Need a new place? Ask the planner to find another and we will cancel the current selection.') }}
                                </div>
                            </div>
                        </div>

                        <div v-else>
                            <RecommendationsCards
                                :items="recommendations"
                                :place-options="placeOptionsState"
                                :event-id="eventState.id"
                                @updated="handlePlaceOptionsUpdate"
                            />
                        </div>
                    </div>
                    <div class="bg-white rounded-lg border p-4">
                        <div class="flex gap-3 mb-4">
                            <button @click="activeTab = 'tasks'" :class="activeTab === 'tasks' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600'" class="px-3 py-1 rounded text-sm">{{ tr('Tareas', 'Tasks') }}</button>
                            <button @click="activeTab = 'budget'" :class="activeTab === 'budget' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600'" class="px-3 py-1 rounded text-sm">{{ tr('Presupuesto', 'Budget') }}</button>
                            <button @click="activeTab = 'participants'" :class="activeTab === 'participants' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600'" class="px-3 py-1 rounded text-sm">{{ tr('Participantes', 'Participants') }}</button>
                            <button @click="activeTab = 'documents'" :class="activeTab === 'documents' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600'" class="px-3 py-1 rounded text-sm">{{ tr('Documentos', 'Documents') }}</button>
                        </div>

                        <div v-if="activeTab === 'tasks'">
                            <div class="mb-4 rounded-lg border bg-gray-50 p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <h3 class="text-sm font-semibold text-gray-800">{{ tr('Checklist del evento', 'Event Checklist') }}</h3>
                                    <span class="text-xs text-gray-500">{{ missingCount }} {{ tr('pendientes', 'remaining') }}</span>
                                </div>
                                <div class="space-y-2">
                                    <div v-if="!checklistTasks.length" class="text-xs text-gray-500">
                                        {{ tr('Aún no hay elementos del checklist.', 'No checklist items yet.') }}
                                    </div>
                                    <label v-for="task in checklistTasks" :key="task.id" class="flex items-center justify-between gap-2 text-sm text-gray-700">
                                        <span class="flex items-center gap-2">
                                            <input type="checkbox" class="rounded border-gray-300 text-blue-600" :checked="task.status === 'done'" @change="toggleChecklistTask(task)" />
                                            <span :class="task.status === 'done' ? 'line-through text-gray-400' : ''">{{ task.title }}</span>
                                            <span
                                                v-if="isPermissionSlipTask(task)"
                                                class="text-[11px] px-2 py-0.5 rounded-full border border-blue-200 bg-blue-50 text-blue-700"
                                                :title="tr('Permisos recibidos', 'Permission slips received')"
                                            >
                                                {{ permissionSlipStats.received }} / {{ permissionSlipStats.required }} {{ tr('permisos', 'slips') }}
                                            </span>
                                            <span
                                                v-if="hasFormForTask(task)"
                                                class="text-green-600 text-xs font-semibold"
                                                :title="tr('Formulario disponible', 'Form available')"
                                            >
                                                ✓
                                            </span>
                                            <span
                                                v-else
                                                class="text-red-600 text-xs font-semibold"
                                                :title="tr('Sin formulario disponible', 'No form available')"
                                            >
                                                ✕
                                            </span>
                                        </span>
                                        <span class="flex items-center gap-2">
                                            <button
                                                v-if="isFinalizeAttendeeTask(task)"
                                                type="button"
                                                class="text-xs text-blue-600 hover:text-blue-700"
                                                @click="activeTab = 'participants'"
                                            >
                                                Go to Participants
                                            </button>
                                            <button
                                                v-else-if="hasFormForTask(task)"
                                                type="button"
                                                class="text-xs text-blue-600 hover:text-blue-700"
                                                @click="openTaskForm(task)"
                                            >
                                                Open Form
                                            </button>
                                            <button
                                                v-else
                                                type="button"
                                                class="text-xs text-amber-600 hover:text-amber-700"
                                                @click="openCustomFormBuilderModal(task)"
                                            >
                                                Create Form
                                            </button>
                                            <button type="button" class="text-xs text-gray-400 hover:text-red-500" @click="removeChecklistTask(task)">
                                                Remove
                                            </button>
                                        </span>
                                    </label>
                                </div>
                                <div class="mt-3 flex items-center gap-2">
                                    <input v-model="newChecklistItem" type="text" class="flex-1 rounded border border-gray-300 px-2 py-1 text-sm" :placeholder="tr('Agregar elemento al checklist', 'Add checklist item')" />
                                    <button type="button" class="px-3 py-1 rounded text-sm bg-blue-600 text-white" @click="addChecklistItem">{{ tr('Agregar', 'Add') }}</button>
                                </div>
                            </div>
                        </div>
                        <div v-else-if="activeTab === 'budget'">
                            <div class="mb-3 flex items-center justify-between rounded-lg border bg-gray-50 px-3 py-2">
                                <div>
                                    <div class="text-sm font-semibold text-gray-800">{{ tr('Auto-crear presupuesto de renta', 'Auto-create rental budget') }}</div>
                                    <div class="text-xs text-gray-500">{{ tr('Cuando el planificador estima rentas, actualiza o crea una línea de presupuesto de renta.', 'When the planner estimates rentals, update or create a rental budget line.') }}</div>
                                </div>
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input
                                        type="checkbox"
                                        class="rounded border-gray-300"
                                        :checked="autoCreateBudgetItem"
                                        @change="saveBudgetPreference($event.target.checked)"
                                    />
                                    Enable
                                </label>
                            </div>
                            <BudgetTable
                                :items="budgetState"
                                :event-id="eventState.id"
                                :payment-summary="paymentSummaryState"
                                :payment-records="paymentRecordsState"
                                :concept-label="paymentConfigState?.concept_label || eventState?.title || ''"
                                :expected-payments-total="expectedPaymentsTotal"
                                @updated="updateBudget"
                            />
                        </div>
                        <div v-else-if="activeTab === 'participants'">
                            <ParticipantsTable
                                :participants="participantsState"
                                :event-id="eventState.id"
                                :members="membersState"
                                :classes="classesState"
                                :staff="staffState"
                                :parents="parentsState"
                                :payment-summary="paymentSummaryState"
                                :payment-config="paymentConfigState"
                                :club-id="eventState.club_id"
                                @updated="updateParticipants"
                                @finish-list="finishAttendeeList"
                            />
                        </div>
                        <div v-else>
                            <DocumentsUploader
                                :event-id="eventState.id"
                                :documents="documentsState"
                                :participants="participantsState"
                                :staff="staffState"
                                :parents="parentsState"
                                :preset="documentPreset"
                                @updated="updateDocuments"
                            />
                        </div>
                    </div>

                    <div class="bg-white rounded-lg border p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h2 class="text-lg font-semibold text-gray-800">{{ tr('Esquema del plan', 'Plan Outline') }}</h2>
                            <a
                                :href="route('events.pdf', { event: eventState.id })"
                                class="text-sm text-blue-600 hover:text-blue-700"
                            >
                                Export PDF
                            </a>
                        </div>
                        <p class="text-sm text-gray-500 mb-3">
                            {{ tr('Esta sección resume resultados de tareas y exporta un reporte imprimible.', 'This section will summarize task outcomes and export a printable report.') }}
                        </p>
                        <PlanSectionsAccordion :sections="outlineSections" />
                    </div>
                </div>

                <div>
                    <PlannerChat
                        :event-id="eventState.id"
                        :messages="planState?.conversation_json || []"
                        :auto-create-budget-item="planState?.plan_json?.preferences?.auto_create_budget_item || false"
                        :serp-api-usage="serpApiUsageState"
                        @update="handlePlannerUpdate"
                    />
                </div>
            </div>
        </div>

        <div v-if="showTransportModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-5xl rounded-lg bg-white p-6 shadow-xl">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-800">{{ tr('Organizar transporte', 'Arrange Transportation') }}</h3>
                    <button type="button" class="text-gray-400 hover:text-gray-600" @click="showTransportModal = false">✕</button>
                </div>
                <div v-if="transportLoading" class="text-sm text-gray-500">{{ tr('Cargando conductores...', 'Loading drivers...') }}</div>
                <div v-else-if="transportError" class="text-sm text-red-500">{{ transportError }}</div>
                <div v-else class="space-y-4">
                    <div
                        v-if="transportNotice"
                        :class="transportNoticeType === 'error'
                            ? 'rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700'
                            : 'rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-700'"
                    >
                        {{ transportNotice }}
                    </div>
                    <div class="rounded-lg border bg-gray-50 p-4 space-y-3">
                        <div class="text-sm font-semibold text-gray-800">{{ tr('Método de transporte', 'Transportation method') }}</div>
                        <div v-if="!transportMode" class="flex flex-wrap gap-3">
                            <button
                                type="button"
                                class="px-3 py-2 rounded border bg-white text-sm text-gray-700 hover:border-blue-500"
                                @click="selectTransportMode('private')"
                            >
                                {{ tr('Usar autos privados (reembolso de gasolina)', 'Use private cars (gas reimbursement)') }}
                            </button>
                            <button
                                type="button"
                                class="px-3 py-2 rounded border bg-white text-sm text-gray-700 hover:border-blue-500"
                                @click="selectTransportMode('rental')"
                            >
                                {{ tr('Rentar vehículos (agrega renta + gasolina al presupuesto)', 'Rent vehicles (adds rental + gas budget)') }}
                            </button>
                        </div>
                        <div v-else class="flex items-center justify-between gap-3 text-sm text-gray-700">
                            <div>
                                {{ tr('Seleccionado', 'Selected') }}: <span class="font-semibold">{{ transportMode === 'rental' ? tr('Vehículos rentados', 'Rental vehicles') : tr('Autos privados', 'Private cars') }}</span>
                            </div>
                            <button
                                type="button"
                                class="text-xs text-blue-600 hover:text-blue-700"
                                @click="clearTransportMode"
                            >
                                Change
                            </button>
                        </div>
                        <div class="text-xs text-gray-500">
                            <template v-if="isPrivateTransport">
                                {{ tr('Cada conductor debe tener documento de licencia y al menos un vehículo asegurado para completar esta tarea.', 'Each driver must have a license document and at least one insured vehicle to complete this task.') }}
                            </template>
                            <template v-else-if="isRentalTransport">
                                {{ tr('Cada conductor debe tener documento de licencia. No se requiere asignar vehículo en modo renta.', 'Each driver must have a license document. Vehicle assignment is not required for rental mode.') }}
                            </template>
                            <template v-else>
                                {{ tr('Selecciona un método de transporte para continuar.', 'Select a transportation method to continue.') }}
                            </template>
                        </div>
                    </div>
                    <div v-if="!transportMode" class="text-sm text-gray-500">
                        {{ tr('Selecciona un método de transporte para continuar.', 'Select a transportation method to continue.') }}
                    </div>
                    <div v-else class="space-y-3">
                        <div class="rounded border bg-gray-50 px-3 py-2 flex flex-wrap items-center gap-2">
                            <input
                                v-model="newDriverName"
                                class="border rounded px-2 py-1 text-sm min-w-[220px]"
                                :placeholder="tr('Nombre del conductor', 'Driver name')"
                                @keyup.enter="addDriverParticipant"
                            />
                            <button
                                type="button"
                                class="px-3 py-1 rounded text-xs bg-blue-600 text-white disabled:opacity-50"
                                :disabled="addingDriver"
                                @click="addDriverParticipant"
                            >
                                {{ addingDriver ? tr('Agregando...', 'Adding...') : tr('Agregar conductor', 'Add driver') }}
                            </button>
                        </div>
                        <div v-if="!driverParticipants.length" class="text-sm text-gray-500">
                            {{ tr('Aún no hay conductores. Agrega uno con el formulario de arriba.', 'No drivers added yet. Add one with the form above.') }}
                        </div>
                        <div v-else class="overflow-auto border rounded-lg">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="text-left px-4 py-2">{{ tr('Conductor', 'Driver') }}</th>
                                    <th class="text-left px-4 py-2">{{ tr('Licencia #', 'License #') }}</th>
                                    <th class="text-left px-4 py-2">{{ tr('Doc. licencia', 'License Doc') }}</th>
                                    <th v-if="isPrivateTransport" class="text-left px-4 py-2">{{ tr('Vehículos', 'Vehicles') }}</th>
                                    <th class="text-right px-4 py-2">{{ tr('Acciones', 'Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="driver in driverParticipants" :key="driver.id" class="border-t align-top">
                                    <td class="px-4 py-2 font-medium text-gray-800">{{ driver.participant_name }}</td>
                                    <td class="px-4 py-2">
                                        <template v-if="isDriverEditing(driver.id)">
                                            <input
                                                v-model="driverLicenseNumbers[driver.id]"
                                                class="w-40 border rounded px-2 py-1 text-sm"
                                                :placeholder="tr('Número de licencia', 'License number')"
                                            />
                                        </template>
                                        <template v-else>
                                            <span class="text-gray-700">{{ driverRecordFor(driver.id)?.license_number || '—' }}</span>
                                        </template>
                                    </td>
                                    <td class="px-4 py-2">
                                        <div class="flex flex-col gap-1">
                                            <template v-if="isDriverEditing(driver.id)">
                                                <select
                                                    class="border rounded px-2 py-1 text-sm"
                                                    :value="docForDriver(driver.id)?.id || ''"
                                                    @change="updateDriverLicenseDoc($event.target.value, driver.id)"
                                                >
                                                    <option value="">{{ tr('Selecciona documento de licencia', 'Select license doc') }}</option>
                                                    <option v-for="doc in licenseDocs" :key="doc.id" :value="doc.id">
                                                        {{ doc.title }}
                                                    </option>
                                                </select>
                                            </template>
                                            <template v-else>
                                                <div class="text-gray-700">{{ docForDriver(driver.id)?.title || '—' }}</div>
                                            </template>
                                            <a
                                                v-if="docForDriver(driver.id)?.path"
                                                :href="`/storage/${docForDriver(driver.id).path}`"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="text-xs text-blue-600"
                                            >
                                                View license
                                            </a>
                                        </div>
                                    </td>
                                    <td v-if="isPrivateTransport" class="px-4 py-2">
                                        <div v-if="driverRecordFor(driver.id)?.vehicles?.length" class="space-y-2">
                                            <div
                                                v-for="vehicle in driverRecordFor(driver.id).vehicles"
                                                :key="vehicle.id"
                                                class="border rounded p-2 text-xs text-gray-600"
                                            >
                                                <div class="font-semibold text-gray-800">
                                                    {{ vehicle.make }} {{ vehicle.model }} {{ vehicle.year }}
                                                </div>
                                                <div>{{ tr('Placa', 'Plate') }}: {{ vehicle.plate || '—' }}</div>
                                                <div>{{ tr('VIN', 'VIN') }}: {{ vehicle.vin || '—' }}</div>
                                                <div>
                                                    {{ tr('Cobertura', 'Coverage') }}:
                                                    <a
                                                        v-if="docForVehicle(vehicle.id)?.path"
                                                        :href="`/storage/${docForVehicle(vehicle.id).path}`"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="text-blue-600"
                                                    >
                                                        View
                                                    </a>
                                                    <span v-else class="text-red-600">{{ tr('Faltante', 'Missing') }}</span>
                                                </div>
                                                <div class="mt-1 flex gap-2">
                                                    <button
                                                        type="button"
                                                        class="text-blue-600"
                                                        @click="openVehicleModal(driverRecordFor(driver.id).id, vehicle)"
                                                    >
                                                        Edit
                                                    </button>
                                                    <button type="button" class="text-red-600" @click="deleteVehicle(vehicle.id)">{{ tr('Eliminar', 'Remove') }}</button>
                                                </div>
                                            </div>
                                        </div>
                                        <div v-else class="text-xs text-gray-500">{{ tr('Aún no hay vehículos.', 'No vehicles yet.') }}</div>
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        <button
                                            v-if="isDriverEditing(driver.id)"
                                            type="button"
                                            class="text-xs text-blue-600"
                                            @click="upsertDriver(driver, driverLicenseNumbers[driver.id])"
                                        >
                                            Save driver
                                        </button>
                                        <button
                                            v-else
                                            type="button"
                                            class="text-xs text-blue-600"
                                            @click="setDriverEditing(driver.id, true)"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            v-if="isPrivateTransport"
                                            type="button"
                                            class="ml-2 text-xs text-green-600"
                                            @click="openVehicleModalForParticipant(driver)"
                                        >
                                            Add vehicle
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="!driverParticipants.length">
                                    <td :colspan="isPrivateTransport ? 5 : 4" class="px-4 py-6 text-center text-gray-500">No drivers yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="vehicleModalOpen && isPrivateTransport" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-800">{{ tr('Detalles del vehículo', 'Vehicle Details') }}</h3>
                    <button type="button" class="text-gray-400 hover:text-gray-600" @click="vehicleModalOpen = false">✕</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <input v-model="vehicleForm.make" class="border rounded px-3 py-2 text-sm" :placeholder="tr('Marca', 'Make')" />
                    <input v-model="vehicleForm.model" class="border rounded px-3 py-2 text-sm" :placeholder="tr('Modelo', 'Model')" />
                    <input v-model="vehicleForm.year" class="border rounded px-3 py-2 text-sm" :placeholder="tr('Año', 'Year')" />
                    <input v-model="vehicleForm.plate" class="border rounded px-3 py-2 text-sm" :placeholder="tr('Placa', 'Plate')" />
                    <input v-model="vehicleForm.vin" class="border rounded px-3 py-2 text-sm md:col-span-2" :placeholder="tr('VIN', 'VIN')" />
                    <div class="md:col-span-2">
                        <label class="text-xs text-gray-600">{{ tr('Seguro o contrato de renta', 'Insurance or Rental Agreement') }}</label>
                        <select v-model="vehicleForm.insurance_doc_id" class="w-full border rounded px-2 py-1 text-sm">
                            <option value="">{{ tr('Selecciona documento de cobertura', 'Select coverage doc') }}</option>
                            <option v-for="doc in insuranceDocs" :key="doc.id" :value="doc.id">
                                {{ doc.title }}
                            </option>
                        </select>
                    </div>
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" class="px-3 py-1 rounded text-sm bg-gray-200 text-gray-700" @click="vehicleModalOpen = false">
                        Cancel
                    </button>
                    <button type="button" class="px-3 py-1 rounded text-sm bg-blue-600 text-white" @click="saveVehicle">
                        Save Vehicle
                    </button>
                </div>
            </div>
        </div>

        <div v-if="showCustomFormBuilder" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-3xl rounded-lg bg-white p-6 shadow-xl">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-800">{{ tr('Constructor de formularios', 'Custom Form Builder') }}</h3>
                    <button type="button" class="text-gray-400 hover:text-gray-600" @click="showCustomFormBuilder = false">✕</button>
                </div>
                <div class="text-sm text-gray-600 mb-3">
                    {{ tr('Tarea', 'Task') }}: <span class="font-semibold text-gray-800">{{ customFormTask?.title }}</span>
                </div>
                <div class="space-y-2 max-h-[55vh] overflow-auto pr-1">
                    <div v-for="(field, idx) in customFormFields" :key="idx" class="grid grid-cols-12 gap-2 items-center">
                        <input
                            v-model="field.label"
                            class="col-span-4 rounded border border-gray-300 px-2 py-1 text-sm"
                            :placeholder="tr('Etiqueta', 'Label')"
                        />
                        <input
                            v-model="field.key"
                            class="col-span-3 rounded border border-gray-300 px-2 py-1 text-sm"
                            placeholder="key_name"
                        />
                        <select v-model="field.type" class="col-span-3 rounded border border-gray-300 px-2 py-1 text-sm">
                            <option value="text">text</option>
                            <option value="textarea">textarea</option>
                            <option value="number">number</option>
                            <option value="date">date</option>
                            <option value="select">select</option>
                            <option value="checkbox">checkbox</option>
                        </select>
                        <label class="col-span-1 flex items-center justify-center">
                            <input type="checkbox" v-model="field.required" class="rounded border-gray-300 text-blue-600" />
                        </label>
                        <button type="button" class="col-span-1 text-xs text-red-600" @click="removeCustomFormField(idx)">{{ tr('Eliminar', 'Remove') }}</button>
                    </div>
                </div>
                <div class="mt-3">
                    <button type="button" class="px-3 py-1 rounded text-xs bg-gray-200 text-gray-700" @click="addCustomFormField">
                        Add field
                    </button>
                </div>
                <div v-if="customFormError" class="mt-2 text-xs text-red-600">{{ customFormError }}</div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="px-3 py-1 rounded text-sm bg-gray-100 text-gray-600" @click="showCustomFormBuilder = false">{{ tr('Cancelar', 'Cancel') }}</button>
                    <button type="button" class="px-3 py-1 rounded text-sm bg-blue-600 text-white" @click="saveCustomFormDefinition">{{ tr('Guardar formulario', 'Save Form') }}</button>
                </div>
            </div>
        </div>

        <div v-if="activeFormTask" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-2xl rounded-lg bg-white p-6 shadow-xl">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-800">
                        {{ activeFormTask.title }} {{ tr('Formulario', 'Form') }}
                    </h3>
                    <button type="button" class="text-gray-400 hover:text-gray-600" @click="closeTaskForm">✕</button>
                </div>
                <div v-if="formLoading" class="text-sm text-gray-500">{{ tr('Cargando...', 'Loading...') }}</div>
                <div v-else-if="formError" class="text-sm text-red-500">{{ formError }}</div>
                <div v-else-if="formSchema?.fields?.length" class="space-y-4">
                    <div v-for="field in formSchema.fields" :key="field.key" class="space-y-1">
                        <label class="text-sm font-medium text-gray-700">
                            {{ field.label }}
                            <span v-if="field.required" class="text-red-500">*</span>
                        </label>
                        <input
                            v-if="field.type === 'text' || field.type === 'date' || field.type === 'number'"
                            :type="field.type"
                            v-model="formData[field.key]"
                            class="w-full rounded border border-gray-300 px-3 py-2 text-sm"
                        />
                        <textarea
                            v-else-if="field.type === 'textarea'"
                            v-model="formData[field.key]"
                            rows="3"
                            class="w-full rounded border border-gray-300 px-3 py-2 text-sm"
                        ></textarea>
                        <select
                            v-else-if="field.type === 'select'"
                            v-model="formData[field.key]"
                            class="w-full rounded border border-gray-300 px-3 py-2 text-sm"
                        >
                            <option value="" disabled>{{ tr('Selecciona...', 'Select...') }}</option>
                            <option v-for="option in field.options || []" :key="option" :value="option">{{ option }}</option>
                        </select>
                        <label v-else-if="field.type === 'checkbox'" class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" v-model="formData[field.key]" class="rounded border-gray-300 text-blue-600" />
                            {{ field.help || tr('Sí', 'Yes') }}
                        </label>
                        <div v-else class="text-xs text-gray-500">{{ tr('Tipo de campo no compatible', 'Unsupported field type') }}: {{ field.type }}</div>
                    </div>
                    <div v-if="activeFormTaskKey === 'camp_reservation'" class="rounded-lg border bg-gray-50 p-3 space-y-2">
                        <div class="text-sm font-semibold text-gray-800">{{ tr('Costo del lugar (partida de presupuesto)', 'Venue Cost (Budget Entry)') }}</div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                            <input
                                v-model="formData.venue_type"
                                class="w-full rounded border border-gray-300 px-3 py-2 text-sm"
                                :placeholder="tr('Tipo de lugar (campamento, salón, cancha...)', 'Venue type (camping site, salon rental, sport field...)')"
                            />
                            <input
                                v-model="formData.venue_unit_label"
                                class="w-full rounded border border-gray-300 px-3 py-2 text-sm"
                                :placeholder="tr('Etiqueta de unidad (espacios, cabañas, horas...)', 'Unit label (spots, cabins, hours...)')"
                            />
                            <input
                                v-model.number="formData.venue_qty"
                                type="number"
                                min="0"
                                step="1"
                                class="w-full rounded border border-gray-300 px-3 py-2 text-sm"
                                :placeholder="tr('Cantidad', 'Quantity')"
                            />
                            <input
                                v-model.number="formData.venue_unit_cost"
                                type="number"
                                min="0"
                                step="0.01"
                                class="w-full rounded border border-gray-300 px-3 py-2 text-sm"
                                :placeholder="tr('Costo unitario', 'Unit cost')"
                            />
                            <input
                                :value="formData.venue_expected_total"
                                type="number"
                                min="0"
                                step="0.01"
                                class="w-full rounded border border-gray-300 px-3 py-2 text-sm md:col-span-2"
                                :placeholder="tr('Total esperado (opcional si ya hay cantidad x costo)', 'Expected total (optional if qty x unit cost is provided)')"
                                @input="onVenueExpectedTotalInput($event.target.value)"
                            />
                            <div class="md:col-span-2 flex items-center justify-between text-xs text-gray-500">
                                <span>
                                    {{ tr('Total automático de cantidad × costo unitario', 'Auto total from qty × unit cost:') }}
                                    <span class="font-semibold text-gray-700">${{ computedVenueTotal().toFixed(2) }}</span>
                                </span>
                                <button
                                    type="button"
                                    class="text-blue-600 hover:text-blue-700"
                                    @click="venueTotalEditedManually = false; applyAutoVenueTotal()"
                                >
                                    Use auto
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="text-sm text-gray-500">{{ tr('No hay campos de formulario para esta tarea.', 'No form fields available for this task.') }}</div>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" class="px-3 py-1 rounded text-sm bg-gray-100 text-gray-600" @click="closeTaskForm">{{ tr('Cerrar', 'Close') }}</button>
                    <button type="button" class="px-3 py-1 rounded text-sm bg-blue-600 text-white" :disabled="formLoading" @click="saveTaskForm">
                        Save
                    </button>
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>
