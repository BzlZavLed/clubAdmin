<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue'
import axios from 'axios'
import { router, usePage } from '@inertiajs/vue3'
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
    feeComponents: {
        type: Array,
        default: () => [],
    },
    supportsClubOperations: {
        type: Boolean,
        default: true,
    },
    scopeLabel: {
        type: String,
        default: '',
    },
    targetClubs: {
        type: Array,
        default: () => [],
    },
    clubSignupSummary: {
        type: Array,
        default: () => [],
    },
    members: Array,
    classes: Array,
    staff: Array,
    accounts: Array,
    parents: Array,
    paymentSummary: Object,
    paymentConfig: Object,
    participantClubSummary: {
        type: Array,
        default: () => [],
    },
    participantRoster: {
        type: Array,
        default: () => [],
    },
    canEditEvent: {
        type: Boolean,
        default: false,
    },
    canManageParticipants: {
        type: Boolean,
        default: true,
    },
    taskResponsibilityOptions: {
        type: Array,
        default: () => [],
    },
    taskFormSchemaKeys: {
        type: Array,
        default: () => [],
    },
    manageableSettlementClubIds: {
        type: Array,
        default: () => [],
    },
    paymentRecords: Array,
    serpApiUsage: Object,
})

const activeTab = ref('tasks')
const { tr } = useLocale()
const page = usePage()

const eventState = ref(props.event)
const planState = ref(props.eventPlan)
const tasksState = ref(props.tasks || [])
const budgetState = ref(props.budgetItems || [])
const participantsState = ref(props.participants || [])
const documentsState = ref(props.documents || [])
const placeOptionsState = ref(props.placeOptions || [])
const feeComponentsState = ref(props.feeComponents || [])
const membersState = ref(props.members || [])
const classesState = ref(props.classes || [])
const staffState = ref(props.staff || [])
const accountsState = ref(props.accounts || [])
const parentsState = ref(props.parents || [])
const paymentSummaryState = ref(props.paymentSummary || { total_received: 0, by_member_id: {}, by_staff_id: {} })
const paymentConfigState = ref(props.paymentConfig || { concept_id: null, concept_label: null, amount: null, is_payable: false })
const participantClubSummaryState = ref(props.participantClubSummary || [])
const participantRosterState = ref(props.participantRoster || [])
const paymentRecordsState = ref(props.paymentRecords || [])
const serpApiUsageState = ref(props.serpApiUsage || { month: null, limit: 250, used: 0, remaining: 250 })
const supportsClubOperations = computed(() => !!props.supportsClubOperations)
const canManageTasks = computed(() => !!props.canEditEvent)
const effectiveProfileType = computed(() => {
    const authUser = page.props.auth?.user || null
    if (authUser?.profile_type !== 'superadmin') {
        return authUser?.profile_type || null
    }

    return page.props.auth?.superadmin_context?.role || 'superadmin'
})
const isDownstreamClubViewer = computed(() => {
    return !props.canEditEvent && ['club_director', 'club_personal'].includes(effectiveProfileType.value || '')
})
const isIntermediateEventFinanceViewer = computed(() => {
    return !props.canEditEvent && !supportsClubOperations.value && !isDownstreamClubViewer.value
})
const taskResponsibilityOptions = computed(() => props.taskResponsibilityOptions || [])
const targetClubSummaryByDistrict = computed(() => {
    const groups = new Map()

    ;(props.clubSignupSummary || []).forEach((club) => {
        const districtKey = `${club.district_id || 0}`
        if (!groups.has(districtKey)) {
            groups.set(districtKey, {
                district_id: club.district_id || 0,
                district_name: club.district_name || 'Sin distrito',
                clubs: [],
            })
        }
        groups.get(districtKey).clubs.push(club)
    })

    return Array.from(groups.values())
})
const paymentRecordsByClub = computed(() => {
    const groups = new Map()
    ;(paymentRecordsState.value || []).forEach((payment) => {
        const clubId = Number(payment?.club_id || 0)
        if (!clubId) return
        if (!groups.has(clubId)) {
            groups.set(clubId, [])
        }
        groups.get(clubId).push(payment)
    })

    return groups
})
const feeComponentsTotal = computed(() => feeComponentsState.value.reduce((total, component) => total + Number(component?.amount || 0), 0))
const transportMode = ref(planState.value?.plan_json?.transportation_mode || null)
const autoCreateBudgetItem = ref(planState.value?.plan_json?.preferences?.auto_create_budget_item || false)
const isPrivateTransport = computed(() => transportMode.value === 'private')
const isRentalTransport = computed(() => transportMode.value === 'rental')

const formatMoney = (value) => {
    const num = Number(value || 0)
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(num)
}

const formatShortDate = (value) => {
    if (!value) return '—'
    const parsed = new Date(`${String(value).slice(0, 10)}T00:00:00`)
    if (Number.isNaN(parsed.getTime())) return String(value)

    return new Intl.DateTimeFormat(undefined, { month: 'short', day: '2-digit', year: 'numeric' }).format(parsed)
}

const paymentRecordsForClub = (club) => paymentRecordsByClub.value.get(Number(club?.club_id || 0)) || []
const paymentRecordsTotal = (records) => records.reduce((sum, payment) => sum + Number(payment?.amount_paid || 0), 0)
const paymentRecordBreakdown = (payment) => {
    if (Array.isArray(payment?.breakdown) && payment.breakdown.length) {
        return payment.breakdown
    }

    return [{
        component_label: payment?.concept_label || tr('Pago del evento', 'Event payment'),
        amount: Number(payment?.amount_paid || 0),
        is_required: null,
    }]
}

const taskInstanceKey = (task) => task?.instance_key || (task?.active_assignment_id ? `task:${task.id}:assignment:${task.active_assignment_id}` : `task:${task?.id}`)
const openChecklistTasks = ref({})
const eventTasksExpanded = ref(false)
const taskDomId = (task) => `event-task-${taskInstanceKey(task).replace(/[^a-zA-Z0-9_-]/g, '-')}`
const taskOwnerLabel = (task) => task?.responsibility_label || task?.assignment_scope_label || tr('Organizador', 'Organizer')
const isTaskOpen = (task) => !!openChecklistTasks.value[taskInstanceKey(task)]
const setTaskOpen = (task, open) => {
    openChecklistTasks.value = {
        ...openChecklistTasks.value,
        [taskInstanceKey(task)]: !!open,
    }
}
const openTaskFromIndex = async (task) => {
    eventTasksExpanded.value = true
    setTaskOpen(task, true)
    await nextTick()
    const target = document.getElementById(taskDomId(task))
    if (!target) return
    target.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

const taskRequestPayload = (task, overrides = {}) => ({
    title: task.title,
    description: task.description,
    assigned_to_user_id: task.assigned_to_user_id,
    due_at: task.due_at,
    status: task.status,
    responsibility_level: task.responsibility_level || 'organizer',
    checklist_json: task.checklist_json,
    ...(task.active_assignment_id ? { assignment_id: task.active_assignment_id } : {}),
    ...overrides,
})

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
    description: eventState.value?.description || '',
    start_at: toLocalInput(eventState.value?.start_at),
    end_at: toLocalInput(eventState.value?.end_at),
    timezone: eventState.value?.timezone || 'America/New_York',
    status: eventState.value?.status || 'draft',
    is_mandatory: !!eventState.value?.is_mandatory,
    fee_components: (props.feeComponents || []).map((component) => ({
        label: component.label || '',
        amount: component.amount ?? '',
        is_required: component.is_required ?? true,
    })),
})
const eventFormSaving = ref(false)
const eventFormError = ref('')
const showEventModal = ref(false)
const saveEventBasics = async () => {
    eventFormSaving.value = true
    eventFormError.value = ''
    const payload = {
        title: eventState.value.title,
        description: eventForm.value.description || null,
        event_type: eventState.value.event_type,
        start_at: eventForm.value.start_at,
        end_at: eventForm.value.end_at || null,
        timezone: eventForm.value.timezone,
        status: eventForm.value.status,
        budget_estimated_total: eventState.value.budget_estimated_total,
        budget_actual_total: eventState.value.budget_actual_total,
        requires_approval: eventState.value.requires_approval,
        risk_level: eventState.value.risk_level,
        is_mandatory: eventForm.value.is_mandatory,
        fee_components: (eventForm.value.fee_components || [])
            .filter((component) => String(component.label || '').trim() !== '' && Number(component.amount || 0) > 0)
            .map((component) => ({
                label: String(component.label || '').trim(),
                amount: Number(component.amount || 0),
                is_required: !!component.is_required,
            })),
    }

    if (payload.fee_components.length && !payload.fee_components.some((component) => component.is_required)) {
        eventFormError.value = tr('Marca al menos un concepto obligatorio, por ejemplo Inscripción.', 'Mark at least one required component, for example registration.')
        eventFormSaving.value = false
        return
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

watch(() => props.event, (nextEvent) => {
    if (!nextEvent) return
    eventState.value = nextEvent
    eventForm.value = {
        ...eventForm.value,
        description: nextEvent.description || '',
        start_at: toLocalInput(nextEvent.start_at),
        end_at: toLocalInput(nextEvent.end_at),
        timezone: nextEvent.timezone || 'America/New_York',
        status: nextEvent.status || 'draft',
        is_mandatory: !!nextEvent.is_mandatory,
        fee_components: (props.feeComponents || []).map((component) => ({
            label: component.label || '',
            amount: component.amount ?? '',
            is_required: component.is_required ?? true,
        })),
    }
})

watch(() => props.feeComponents, (nextComponents) => {
    feeComponentsState.value = nextComponents || []
    eventForm.value = {
        ...eventForm.value,
        fee_components: (nextComponents || []).map((component) => ({
            label: component.label || '',
            amount: component.amount ?? '',
            is_required: component.is_required ?? true,
        })),
    }
})

const eventStatusLabel = (status) => {
    switch (status) {
        case 'plan_finalized':
            return tr('Plan finalizado', 'Plan finalized')
        case 'ongoing':
            return tr('En curso', 'Ongoing')
        case 'past':
            return tr('Pasado', 'Past')
        case 'draft':
        default:
            return tr('Borrador', 'Draft')
    }
}

const eventStatusClass = (status) => {
    switch (status) {
        case 'plan_finalized':
            return 'bg-blue-50 text-blue-700'
        case 'ongoing':
            return 'bg-emerald-50 text-emerald-700'
        case 'past':
            return 'bg-gray-100 text-gray-700'
        case 'draft':
        default:
            return 'bg-amber-50 text-amber-700'
    }
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

watch(supportsClubOperations, (value) => {
    if (!value && ['budget'].includes(activeTab.value)) {
        activeTab.value = 'tasks'
    }
}, { immediate: true })

const updateClubSignupStatus = (club, signupStatus) => {
    router.post(route('events.club-signup', { event: eventState.value.id }), {
        club_id: club.club_id,
        signup_status: signupStatus,
    }, {
        preserveScroll: true,
    })
}

const addEventFeeComponent = () => {
    eventForm.value.fee_components.push({
        label: '',
        amount: '',
        is_required: eventForm.value.fee_components.length === 0,
    })
}

const removeEventFeeComponent = (index) => {
    eventForm.value.fee_components.splice(index, 1)
}

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

const normalizedTaskTitle = (task) => String(task?.title || '').trim().toLowerCase()
const taskDedupKey = (task) => {
    const scopeKey = task?.active_assignment_id
        ? `${task.assignment_scope_type || 'scope'}:${task.assignment_scope_id || task.active_assignment_id}`
        : 'organizer'

    return `${normalizedTaskTitle(task)}|${scopeKey}`
}

const checklistTasks = computed(() => {
    const candidates = (tasksState.value || []).filter((task) => {
        const meta = task?.checklist_json || {}
        return meta?.source === 'event_checklist' || meta?.source === 'event_type_template'
    })

    const byTitle = new Map()
    for (const task of candidates) {
        const key = taskDedupKey(task)
        if (!key) continue
        const current = byTitle.get(key)
        if (!current) {
            byTitle.set(key, task)
            continue
        }

        const currentMeta = current?.checklist_json || {}
        const nextMeta = task?.checklist_json || {}
        const currentPriority = [
            currentMeta?.source === 'event_type_template' ? 1 : 0,
            Array.isArray(currentMeta?.custom_form_schema?.fields) ? 1 : 0,
            current?.status === 'done' ? 1 : 0,
            Number(current?.id || 0),
        ].join('-')
        const nextPriority = [
            nextMeta?.source === 'event_type_template' ? 1 : 0,
            Array.isArray(nextMeta?.custom_form_schema?.fields) ? 1 : 0,
            task?.status === 'done' ? 1 : 0,
            Number(task?.id || 0),
        ].join('-')

        if (nextPriority > currentPriority) {
            byTitle.set(key, task)
        }
    }

    return Array.from(byTitle.values())
})

const summarizeAssignmentRows = (rows) => {
    const total = rows.length
    const done = rows.filter((row) => row.status === 'done').length
    return {
        total,
        done,
        pending: Math.max(total - done, 0),
    }
}

const taskTrackingTree = (task) => {
    const details = Array.isArray(task?.assignment_details) ? task.assignment_details : []
    const scopeType = eventState.value?.scope_type || 'club'
    const responsibilityLevel = task?.responsibility_level || 'organizer'

    if (!details.length || scopeType === 'club' || responsibilityLevel === 'organizer') {
        return []
    }

    if (scopeType === 'district') {
        return details.map((row) => ({
            key: `club-${row.id}`,
            label: row.club_name || row.scope_label,
            summary: summarizeAssignmentRows([row]),
            rows: [row],
            children: [],
        }))
    }

    if (scopeType === 'association') {
        if (responsibilityLevel === 'district') {
            return details.map((row) => ({
                key: `district-${row.id}`,
                label: row.district_name || row.scope_label,
                summary: summarizeAssignmentRows([row]),
                rows: [row],
                children: [],
            }))
        }

        const byDistrict = new Map()
        details.forEach((row) => {
            const key = `${row.district_id || row.scope_id}`
            if (!byDistrict.has(key)) {
                byDistrict.set(key, {
                    key: `district-${key}`,
                    label: row.district_name || 'Sin distrito',
                    rows: [],
                })
            }
            byDistrict.get(key).rows.push(row)
        })

        return Array.from(byDistrict.values()).map((group) => ({
            key: group.key,
            label: group.label,
            summary: summarizeAssignmentRows(group.rows),
            rows: [],
            children: group.rows.map((row) => ({
                key: `club-${row.id}`,
                label: row.club_name || row.scope_label,
                summary: summarizeAssignmentRows([row]),
                rows: [row],
                children: [],
            })),
        }))
    }

    if (scopeType === 'union') {
        if (responsibilityLevel === 'association') {
            return details.map((row) => ({
                key: `association-${row.id}`,
                label: row.association_name || row.scope_label,
                summary: summarizeAssignmentRows([row]),
                rows: [row],
                children: [],
            }))
        }

        const byAssociation = new Map()
        details.forEach((row) => {
            const associationKey = `${row.association_id || 0}`
            if (!byAssociation.has(associationKey)) {
                byAssociation.set(associationKey, {
                    key: `association-${associationKey}`,
                    label: row.association_name || 'Sin asociacion',
                    rows: [],
                })
            }
            byAssociation.get(associationKey).rows.push(row)
        })

        return Array.from(byAssociation.values()).map((associationGroup) => {
            if (responsibilityLevel === 'district') {
                return {
                    key: associationGroup.key,
                    label: associationGroup.label,
                    summary: summarizeAssignmentRows(associationGroup.rows),
                    rows: [],
                    children: associationGroup.rows.map((row) => ({
                        key: `district-${row.id}`,
                        label: row.district_name || row.scope_label,
                        summary: summarizeAssignmentRows([row]),
                        rows: [row],
                        children: [],
                    })),
                }
            }

            const byDistrict = new Map()
            associationGroup.rows.forEach((row) => {
                const districtKey = `${row.district_id || 0}`
                if (!byDistrict.has(districtKey)) {
                    byDistrict.set(districtKey, {
                        key: `district-${districtKey}`,
                        label: row.district_name || 'Sin distrito',
                        rows: [],
                    })
                }
                byDistrict.get(districtKey).rows.push(row)
            })

            return {
                key: associationGroup.key,
                label: associationGroup.label,
                summary: summarizeAssignmentRows(associationGroup.rows),
                rows: [],
                children: Array.from(byDistrict.values()).map((districtGroup) => ({
                    key: districtGroup.key,
                    label: districtGroup.label,
                    summary: summarizeAssignmentRows(districtGroup.rows),
                    rows: [],
                    children: districtGroup.rows.map((row) => ({
                        key: `club-${row.id}`,
                        label: row.club_name || row.scope_label,
                        summary: summarizeAssignmentRows([row]),
                        rows: [row],
                        children: [],
                    })),
                })),
            }
        })
    }

    return []
}

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

const humanizeTaskValue = (value) => {
    if (typeof value === 'boolean') return value ? tr('Sí', 'Yes') : tr('No', 'No')
    if (value === null || value === undefined || String(value).trim() === '') return '—'
    return String(value)
}

const humanizeTaskKey = (key) => String(key || '')
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase())

const fieldsForTaskSchema = (task) => {
    const schema = task?.checklist_json?.custom_form_schema
    return Array.isArray(schema?.fields) ? schema.fields : []
}

const taskGeneratedOutlineSections = computed(() => {
    return checklistTasks.value
        .filter((task) => task.status === 'done' && task?.form_response?.data_json)
        .map((task) => {
            const responseData = task.form_response?.data_json || {}
            const schema = task?.checklist_json?.custom_form_schema || null
            const fields = fieldsForTaskSchema(task)
            const fieldMap = new Map(fields.map((field) => [field.key, field.label || humanizeTaskKey(field.key)]))

            if (schema?.mode === 'registry' && Array.isArray(responseData.rows) && responseData.rows.length) {
                return {
                    name: task.title,
                    summary: task.description || tr('Datos recopilados en esta tarea.', 'Data collected in this task.'),
                    items: responseData.rows.map((row, index) => {
                        const imageUrls = Object.entries(row || {})
                            .map(([key, value]) => {
                                const field = fields.find((entry) => entry.key === key) || null
                                if (field?.type !== 'image') return null
                                if (value && typeof value === 'object') return value.url || null
                                return value || null
                            })
                            .filter(Boolean)

                        const detail = Object.entries(row || {})
                            .filter(([key]) => key !== '_row_id')
                            .map(([key, value]) => {
                                const field = fields.find((entry) => entry.key === key) || null
                                if (field?.type === 'image') return null
                                return `${fieldMap.get(key) || humanizeTaskKey(key)}: ${formatFieldValue(field, value)}`
                            })
                            .filter(Boolean)
                            .join(' • ')

                        return {
                            label: `${tr('Registro', 'Row')} ${index + 1}`,
                            detail,
                            image_urls: imageUrls,
                        }
                    }),
                }
            }

            const entries = Object.entries(responseData || {})
            if (!entries.length) {
                return null
            }

            return {
                name: task.title,
                summary: task.description || tr('Datos recopilados en esta tarea.', 'Data collected in this task.'),
                items: entries.map(([key, value]) => ({
                    label: fieldMap.get(key) || humanizeTaskKey(key),
                    detail: (fields.find((entry) => entry.key === key) || null)?.type === 'image'
                        ? ''
                        : formatFieldValue(fields.find((entry) => entry.key === key) || null, value),
                    image_url: (fields.find((entry) => entry.key === key) || null)?.type === 'image'
                        ? (value && typeof value === 'object' ? value.url || '' : value || '')
                        : null,
                })),
            }
        })
        .filter(Boolean)
})

const outlineSections = computed(() => {
    const sections = (planState.value?.plan_json?.sections || [])
        .filter((section) => (section.name || '') !== 'Recommendations')
    const permissionTask = checklistTasks.value.find((task) => isPermissionSlipTask(task))
    if (!permissionTask || permissionTask.status !== 'done') {
        return [...taskGeneratedOutlineSections.value, ...sections]
    }

    const names = permissionSlipNames.value
    const permissionSection = {
        name: 'Permissions',
        summary: 'Parent permission collected for the following kids.',
        items: names.map((name) => ({ label: name })),
    }

    return [permissionSection, ...taskGeneratedOutlineSections.value, ...sections]
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

const fixedDocumentTaskKeys = new Set(['permission_slips', 'permission_slip'])
const documentTaskKeywords = ['release', 'doc', 'slip', 'permission', 'medical', 'insurance', 'rental']
const globalTaskFormKeys = computed(() => new Set(props.taskFormSchemaKeys || []))

const customFormSchemaForTask = (task) => task?.checklist_json?.custom_form_schema || null
const hasCustomFormForTask = (task) => {
    const customSchema = customFormSchemaForTask(task)
    return Array.isArray(customSchema?.fields) && customSchema.fields.length > 0
}

const normalizedTaskKeyFor = (task) => (task?.checklist_json?.task_key || taskKeyFromTitle(task?.title || '') || '').toLowerCase()

const isTransportationTask = (task) => normalizedTaskKeyFor(task) === 'transportation_plan'

const isDocumentHandlerTask = (task) => {
    const key = normalizedTaskKeyFor(task)
    if (fixedDocumentTaskKeys.has(key)) return true
    if (hasCustomFormForTask(task) || globalTaskFormKeys.value.has(key)) return false

    const title = (task?.title || '').toLowerCase()
    const source = key || title
    return documentTaskKeywords.some((word) => source.includes(word))
}

const hasFormForTask = (task) => {
    const title = (task?.title || '').toLowerCase()
    const hasCustom = hasCustomFormForTask(task)
    if (title.includes('campsite reservation confirmed')) {
        return hasCustom
    }
    const key = normalizedTaskKeyFor(task)
    return globalTaskFormKeys.value.has(key) || hasCustom || isTransportationTask(task) || isDocumentHandlerTask(task)
}

const taskCompletionActionLabel = (task) => {
    if (isTransportationTask(task)) return tr('Abrir transporte', 'Open transportation')
    if (isDocumentHandlerTask(task)) return tr('Ir a documentos', 'Go to documents')
    return tr('Abrir formulario', 'Open form')
}

const taskCompletionBadgeLabel = (task) => {
    if (isTransportationTask(task)) return tr('Transporte', 'Transportation')
    if (isDocumentHandlerTask(task)) return tr('Documentos', 'Documents')
    return tr('Formulario', 'Form')
}

const isFinalizeAttendeeTask = (task) => {
    const key = normalizedTaskKeyFor(task)
    const title = (task?.title || '').toLowerCase()
    return key === 'finalize_attendee_list' || title.includes('finalize attendee list')
}

const isPermissionSlipTask = (task) => {
    const key = (task?.checklist_json?.task_key || '').toLowerCase()
    if (key === 'permission_slips' || key === 'permission_slip') return true
    const title = (task?.title || '').toLowerCase()
    return title.includes('permission slip') || title.includes('permission slips')
}

const hasAiSuggestedForm = (task) => {
    return hasCustomFormForTask(task)
}

const openTaskInfoModal = (task) => {
    taskInfoModal.value = {
        open: true,
        title: task?.title || '',
        description: task?.description || '',
    }
}

const closeTaskInfoModal = () => {
    taskInfoModal.value = { open: false, title: '', description: '' }
}

const syncMissingItems = async (tasks = checklistTasks.value) => {
    if (!canManageTasks.value) return

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

const ensureTaskResponseForCompletion = async (task) => {
    if (!task?.id || task?.form_response?.data_json) return null

    try {
        const { data } = await axios.get(route('event-tasks.form.show', { eventTask: task.id }), {
            params: task.active_assignment_id ? { assignment_id: task.active_assignment_id } : {},
        })
        const schema = data?.schema?.schema_json || null
        const existingResponse = data?.response || null
        const prefill = data?.prefill || null

        if (existingResponse?.data_json) {
            mergeTaskIntoState({
                ...task,
                form_response: existingResponse,
            })
            return existingResponse
        }

        const hasPrefill = prefill && typeof prefill === 'object' && Object.keys(prefill).length > 0
        if (!schema || !hasPrefill) return null

        const { data: responseData } = await axios.put(route('event-tasks.form.update', { eventTask: task.id }), {
            data_json: prefill,
            ...(task.active_assignment_id ? { assignment_id: task.active_assignment_id } : {}),
        })

        mergeTaskIntoState({
            ...task,
            form_response: responseData.response,
        })

        return responseData.response
    } catch {
        return null
    }
}

const toggleChecklistTask = async (task) => {
    if (!task?.can_complete_task) return

    const nextStatus = task.status === 'done' ? 'todo' : 'done'
    if (nextStatus === 'done') {
        await ensureTaskResponseForCompletion(task)
    }
    const { data } = await axios.put(route('event-tasks.update', { eventTask: task.id }), taskRequestPayload(task, {
        status: nextStatus,
    }))
    mergeTaskIntoState(data.task)
    await syncMissingItems()
}

const newChecklistItem = ref('')
const editingTaskId = ref(null)
const editingTaskTitle = ref('')
const activeFormTask = ref(null)
const activeFormTaskKey = ref(null)
const formSchema = ref(null)
const formData = ref({})
const registryRows = ref([])
const registryEditIndex = ref(null)
const formLoading = ref(false)
const formError = ref('')
const showCustomFormBuilder = ref(false)
const customFormTask = ref(null)
const customFormFields = ref([])
const customFormMode = ref('single')
const customFormError = ref('')
const customFormSuggesting = ref(false)
const documentPreset = ref(null)
const showTransportModal = ref(false)
const taskInfoModal = ref({ open: false, title: '', description: '' })
const multiSelectQueries = ref({})
const formImageUploadingField = ref(null)
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

const isRegistryForm = computed(() => formSchema.value?.mode === 'registry')

const showAttendeeToast = (message, type = 'success') => {
    if (attendeeToastTimer) {
        clearTimeout(attendeeToastTimer)
    }
    attendeeToast.value = { show: true, type, message }
    attendeeToastTimer = setTimeout(() => {
        attendeeToast.value = { show: false, type: 'success', message: '' }
    }, 3200)
}

const mergeTaskIntoState = (nextTask) => {
    const nextKey = taskInstanceKey(nextTask)

    tasksState.value = tasksState.value.map((item) => {
        if (taskInstanceKey(item) !== nextKey) return item
        return {
            ...item,
            ...nextTask,
            form_response: nextTask.form_response ?? item.form_response ?? null,
        }
    })
}

const startTaskTitleEdit = (task) => {
    editingTaskId.value = task.id
    editingTaskTitle.value = task.title || ''
}

const cancelTaskTitleEdit = () => {
    editingTaskId.value = null
    editingTaskTitle.value = ''
}

const saveTaskTitleEdit = async (task) => {
    if (!task?.can_manage_definition) {
        cancelTaskTitleEdit()
        return
    }

    const nextTitle = String(editingTaskTitle.value || '').trim()
    if (!nextTitle || nextTitle === task.title) {
        cancelTaskTitleEdit()
        return
    }

    const { data } = await axios.put(route('event-tasks.update', { eventTask: task.id }), taskRequestPayload(task, {
        title: nextTitle,
    }))

    mergeTaskIntoState(data.task)
    cancelTaskTitleEdit()
    await syncMissingItems()
}

const updateTaskResponsibility = async (task, responsibilityLevel) => {
    if (!task?.can_manage_definition) return

    const { data } = await axios.put(route('event-tasks.update', { eventTask: task.id }), taskRequestPayload(task, {
        responsibility_level: responsibilityLevel,
    }))

    mergeTaskIntoState(data.task)
    await refreshTasks()
    await syncMissingItems()
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

const blankDataFromSchema = (schema) => {
    const fields = Array.isArray(schema?.fields) ? schema.fields : []
    return fields.reduce((acc, field) => {
        if (field.type === 'checkbox') {
            acc[field.key] = false
        } else if (field.type === 'select' && field.multiple) {
            acc[field.key] = []
        } else {
            acc[field.key] = ''
        }
        return acc
    }, {})
}

const buildRegistryRowId = () => {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID()
    }

    return `row_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`
}

const normalizedSchemaFields = (schema) => Array.isArray(schema?.fields) ? schema.fields : []

const taskSchemaFields = (task) => normalizedSchemaFields(task?.checklist_json?.custom_form_schema)

const taskDataSourceTasks = computed(() => {
    return (tasksState.value || []).filter((task) => {
        if (!task?.id) return false
        const responseData = task?.form_response?.data_json
        if (Array.isArray(responseData?.rows) && responseData.rows.length) return true
        return responseData && typeof responseData === 'object' && Object.keys(responseData).length > 0
    })
})

const taskById = (taskId) => (tasksState.value || []).find((task) => Number(task.id) === Number(taskId)) || null

const taskRowsForSource = (taskId) => {
    const task = taskById(taskId)
    if (!task) return []

    const schema = task?.checklist_json?.custom_form_schema || null
    const responseData = task?.form_response?.data_json || {}

    if (schema?.mode === 'registry' && Array.isArray(responseData.rows)) {
        return responseData.rows.map((row, index) => ({
            ...row,
            _row_id: row?._row_id || `task-${task.id}-row-${index}`,
        }))
    }

    if (responseData && typeof responseData === 'object' && Object.keys(responseData).length) {
        return [{
            ...responseData,
            _row_id: responseData?._row_id || `task-${task.id}-single`,
        }]
    }

    return []
}

const taskSourceFieldChoices = (taskId) => {
    const task = taskById(taskId)
    if (!task) return []

    const schemaFields = taskSchemaFields(task)
    if (schemaFields.length) {
        return schemaFields
            .filter((field) => field?.key)
            .map((field) => ({
                value: field.key,
                label: field.label || field.key,
            }))
    }

    const firstRow = taskRowsForSource(taskId)[0] || {}
    return Object.keys(firstRow)
        .filter((key) => key !== '_row_id')
        .map((key) => ({ value: key, label: humanizeTaskKey(key) }))
}

const labelForTaskDataRow = (row, labelField) => {
    const labelValue = labelField ? row?.[labelField] : null
    if (labelValue !== null && labelValue !== undefined && String(labelValue).trim() !== '') {
        return String(labelValue)
    }

    const fallbackKey = Object.keys(row || {}).find((key) => key !== '_row_id' && row?.[key] !== null && row?.[key] !== undefined && String(row?.[key]).trim() !== '')
    if (fallbackKey) {
        return String(row[fallbackKey])
    }

    return tr('Registro', 'Record')
}

const dynamicOptionsForField = (field) => {
    const source = field?.source || ''
    if (source === 'members') {
        return (membersState.value || []).map((member) => ({
            value: member.member_id,
            label: member.applicant_name || member.name || `Member #${member.member_id}`,
        }))
    }
    if (source === 'staff') {
        return (staffState.value || []).map((staffMember) => ({
            value: staffMember.id,
            label: staffMember.name || `Staff #${staffMember.id}`,
        }))
    }
    if (source === 'classes') {
        return (classesState.value || []).map((clubClass) => ({
            value: clubClass.id,
            label: clubClass.class_name || `Class #${clubClass.id}`,
        }))
    }
    if (source === 'participants') {
        const roleFilter = String(field?.source_config?.participant_role || '').trim().toLowerCase()
        const statusFilter = String(field?.source_config?.participant_status || '').trim().toLowerCase()

        return (participantsState.value || [])
            .filter((participant) => {
                const participantRole = String(participant?.role || '').trim().toLowerCase()
                const participantStatus = String(participant?.status || '').trim().toLowerCase()

                if (roleFilter && participantRole !== roleFilter) return false
                if (statusFilter && participantStatus !== statusFilter) return false
                return true
            })
            .map((participant) => {
            const role = participant?.role ? String(participant.role) : ''
            const status = participant?.status ? String(participant.status) : ''
            const suffix = [role, status].filter(Boolean).join(' · ')

            return {
                value: participant.id,
                label: suffix
                    ? `${participant.participant_name || `Participant #${participant.id}`} (${suffix})`
                    : (participant.participant_name || `Participant #${participant.id}`),
            }
            })
    }
    if (source === 'task_data') {
        const taskId = field?.source_config?.task_id
        const labelField = field?.source_config?.label_field
        return taskRowsForSource(taskId).map((row) => ({
            value: row._row_id,
            label: labelForTaskDataRow(row, labelField),
        }))
    }

    return Array.isArray(field?.options)
        ? field.options.map((option) => ({ value: option, label: option }))
        : []
}

const blockedRegistryValuesForField = (field) => {
    if (!isRegistryForm.value || !field?.source) return new Set()

    return new Set(
        registryRows.value.flatMap((row, index) => {
            if (registryEditIndex.value !== null && index === registryEditIndex.value) {
                return []
            }

            const value = row?.[field.key]
            if (Array.isArray(value)) {
                return value.map((item) => String(item))
            }
            if (value === null || value === undefined || String(value).trim() === '') {
                return []
            }
            return [String(value)]
        })
    )
}

const availableOptionsForField = (field) => {
    const blocked = blockedRegistryValuesForField(field)
    const selected = new Set(selectedValuesForField(field).map((value) => String(value)))
    return dynamicOptionsForField(field).filter((option) => {
        const key = String(option.value)
        return !blocked.has(key) || selected.has(key)
    })
}

const selectedValuesForField = (field) => {
    const value = formData.value?.[field.key]
    return Array.isArray(value) ? value : []
}

const filteredMultiSelectOptions = (field) => {
    const query = String(multiSelectQueries.value?.[field.key] || '').trim().toLowerCase()
    const selected = new Set(selectedValuesForField(field).map((value) => String(value)))
    return availableOptionsForField(field).filter((option) => {
        if (selected.has(String(option.value))) return false
        if (!query) return true
        return String(option.label).toLowerCase().includes(query)
    })
}

const addMultiSelectValue = (field, value) => {
    const current = selectedValuesForField(field)
    if (current.some((item) => String(item) === String(value))) return
    formData.value = {
        ...formData.value,
        [field.key]: [...current, value],
    }
    multiSelectQueries.value = {
        ...multiSelectQueries.value,
        [field.key]: '',
    }
}

const removeMultiSelectValue = (field, value) => {
    formData.value = {
        ...formData.value,
        [field.key]: selectedValuesForField(field).filter((item) => String(item) !== String(value)),
    }
}

const formatFieldValue = (field, value) => {
    if (field?.type === 'image') {
        if (value && typeof value === 'object') {
            return value.url || value.path || '—'
        }
        return value || '—'
    }
    if (field?.type === 'checkbox') {
        return value ? tr('Sí', 'Yes') : tr('No', 'No')
    }
    if (Array.isArray(value)) {
        const optionMap = new Map(dynamicOptionsForField(field).map((option) => [String(option.value), option.label]))
        const labels = value.map((item) => optionMap.get(String(item)) || String(item)).filter(Boolean)
        return labels.length ? labels.join(', ') : '—'
    }
    if (value === null || value === undefined || String(value).trim() === '') {
        return '—'
    }
    if (field?.type === 'select') {
        const optionMap = new Map(dynamicOptionsForField(field).map((option) => [String(option.value), option.label]))
        return optionMap.get(String(value)) || String(value)
    }
    return String(value)
}

const openCustomFormBuilderModal = (task) => {
    if (!task?.can_edit_form_definition) return

    customFormTask.value = task
    const existing = task?.checklist_json?.custom_form_schema
    customFormMode.value = existing?.mode === 'registry' ? 'registry' : 'single'
    if (existing && Array.isArray(existing.fields) && existing.fields.length) {
        customFormFields.value = existing.fields.map((field) => ({
            key: field.key || '',
            label: field.label || '',
            type: field.type || 'text',
            required: !!field.required,
            help: field.help || '',
            optionsText: Array.isArray(field.options) ? field.options.join(', ') : '',
            source: field.source || '',
            multiple: !!field.multiple,
            sourceTaskId: field?.source_config?.task_id || '',
            sourceLabelField: field?.source_config?.label_field || '',
            sourceParticipantRole: field?.source_config?.participant_role || '',
            sourceParticipantStatus: field?.source_config?.participant_status || '',
        }))
    } else {
        customFormFields.value = [{ key: '', label: '', type: 'text', required: false, help: '', optionsText: '', source: '', multiple: false, sourceTaskId: '', sourceLabelField: '', sourceParticipantRole: '', sourceParticipantStatus: '' }]
    }
    customFormError.value = ''
    showCustomFormBuilder.value = true
}

const addCustomFormField = () => {
    customFormFields.value = [
        ...customFormFields.value,
        { key: '', label: '', type: 'text', required: false, help: '', optionsText: '', source: '', multiple: false, sourceTaskId: '', sourceLabelField: '', sourceParticipantRole: '', sourceParticipantStatus: '' },
    ]
}

const moveCustomFormField = (index, direction) => {
    const targetIndex = index + direction
    if (targetIndex < 0 || targetIndex >= customFormFields.value.length) return
    const next = [...customFormFields.value]
    const [field] = next.splice(index, 1)
    next.splice(targetIndex, 0, field)
    customFormFields.value = next
}

const removeCustomFormField = (index) => {
    customFormFields.value = customFormFields.value.filter((_, i) => i !== index)
}

const saveCustomFormDefinition = async () => {
    if (!customFormTask.value?.can_edit_form_definition) return
    customFormError.value = ''

    const normalizedFields = customFormFields.value
        .map((field) => {
            const label = String(field.label || '').trim()
            const key = toSnake(field.key || label)
            const help = String(field.help || '').trim()
            const options = field.type === 'select'
                ? String(field.optionsText || '')
                    .split(',')
                    .map((option) => option.trim())
                    .filter(Boolean)
                : []
            let sourceConfig = null
            if (field.type === 'select' && field.source === 'task_data' && field.sourceTaskId && field.sourceLabelField) {
                sourceConfig = {
                    task_id: Number(field.sourceTaskId),
                    label_field: field.sourceLabelField,
                }
            } else if (field.type === 'select' && field.source === 'participants') {
                sourceConfig = {
                    ...(field.sourceParticipantRole ? { participant_role: field.sourceParticipantRole } : {}),
                    ...(field.sourceParticipantStatus ? { participant_status: field.sourceParticipantStatus } : {}),
                }
                if (!Object.keys(sourceConfig).length) {
                    sourceConfig = null
                }
            }
            return {
                key,
                label,
                type: field.type || 'text',
                required: !!field.required,
                ...(field.type === 'select' && field.source ? { source: field.source } : {}),
                ...(field.type === 'select' && sourceConfig ? { source_config: sourceConfig } : {}),
                ...(field.type === 'select' && field.multiple ? { multiple: true } : {}),
                ...(help ? { help } : {}),
                ...(field.type === 'select' && !field.source && options.length ? { options } : {}),
            }
        })
        .filter((field) => field.key && field.label)

    if (!normalizedFields.length) {
        customFormError.value = 'Add at least one valid field.'
        return
    }

    const invalidTaskDataField = normalizedFields.find((field) => field.type === 'select' && field.source === 'task_data' && (!field.source_config?.task_id || !field.source_config?.label_field))
    if (invalidTaskDataField) {
        customFormError.value = tr('Selecciona la tarea origen y el campo visible para cada selector con datos de otra tarea.', 'Select a source task and display field for every cross-task data selector.')
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
        custom_form_schema: {
            mode: customFormMode.value === 'registry' ? 'registry' : 'single',
            fields: normalizedFields,
        },
    }
    if (!checklist.task_key) {
        checklist.task_key = taskKeyFromTitle(customFormTask.value.title)
    }

    const { data } = await axios.put(route('event-tasks.update', { eventTask: customFormTask.value.id }), taskRequestPayload(customFormTask.value, {
        checklist_json: checklist,
    }))
    mergeTaskIntoState(data.task)
    showCustomFormBuilder.value = false
    customFormTask.value = null
    customFormFields.value = []
    customFormMode.value = 'single'
}

const suggestCustomFormDefinition = async () => {
    if (!customFormTask.value?.can_edit_form_definition) return
    if (customFormFields.value.length) {
        const shouldReplace = window.confirm(tr('Esto reemplazará temporalmente los campos actuales del constructor. Podrás editarlos antes de guardar. ¿Continuar?', 'This will temporarily replace the current builder fields. You can still edit them before saving. Continue?'))
        if (!shouldReplace) return
    }

    customFormSuggesting.value = true
    customFormError.value = ''
    try {
        const { data } = await axios.post(route('event-tasks.form.suggest', { eventTask: customFormTask.value.id }))
        const schema = data?.schema || {}
        customFormMode.value = schema.mode === 'registry' ? 'registry' : 'single'
        customFormFields.value = Array.isArray(schema.fields)
            ? schema.fields.map((field) => ({
                key: field.key || '',
                label: field.label || '',
                type: field.type || 'text',
                required: !!field.required,
                help: field.help || '',
                optionsText: Array.isArray(field.options) ? field.options.join(', ') : '',
                source: field.source || '',
                multiple: !!field.multiple,
                sourceTaskId: field?.source_config?.task_id || '',
                sourceLabelField: field?.source_config?.label_field || '',
                sourceParticipantRole: field?.source_config?.participant_role || '',
                sourceParticipantStatus: field?.source_config?.participant_status || '',
            }))
            : []
        if (!customFormFields.value.length) {
            customFormError.value = tr('La IA no devolvió un formulario utilizable.', 'AI did not return a usable form.')
        }
    } catch (error) {
        customFormError.value = error?.response?.data?.message || tr('No se pudo generar una sugerencia de formulario.', 'Unable to generate a form suggestion.')
    } finally {
        customFormSuggesting.value = false
    }
}

const uploadTaskFormImage = async (field, file) => {
    if (!activeFormTask.value || !field?.key || !file) return

    formImageUploadingField.value = field.key
    formError.value = ''

    try {
        const payload = new FormData()
        payload.append('file', file)

        const { data } = await axios.post(
            route('event-tasks.form.media', { eventTask: activeFormTask.value.id }),
            payload,
            {
                params: activeFormTask.value.active_assignment_id ? { assignment_id: activeFormTask.value.active_assignment_id } : {},
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            }
        )

        formData.value = {
            ...formData.value,
            [field.key]: data.url || '',
        }
    } catch (error) {
        formError.value = error?.response?.data?.message || tr('No se pudo subir la imagen.', 'Unable to upload image.')
    } finally {
        formImageUploadingField.value = null
    }
}

const copyImageFieldUrl = async (field) => {
    const rawValue = formData.value?.[field.key]
    const url = typeof rawValue === 'object' ? rawValue?.url : rawValue
    if (!url) return

    try {
        if (navigator?.clipboard?.writeText) {
            await navigator.clipboard.writeText(String(url))
            showAttendeeToast(tr('URL copiada al portapapeles.', 'URL copied to clipboard.'))
            return
        }

        const temp = document.createElement('textarea')
        temp.value = String(url)
        document.body.appendChild(temp)
        temp.select()
        document.execCommand('copy')
        document.body.removeChild(temp)
        showAttendeeToast(tr('URL copiada al portapapeles.', 'URL copied to clipboard.'))
    } catch {
        formError.value = tr('No se pudo copiar la URL pública.', 'Unable to copy the public URL.')
    }
}

const addChecklistItem = async () => {
    const label = newChecklistItem.value.trim()
    if (!label) return
    const taskKey = taskKeyFromTitle(label)
    const { data } = await axios.post(route('event-tasks.store', { event: eventState.value.id }), {
        title: label,
        status: 'todo',
        responsibility_level: 'organizer',
        checklist_json: { source: 'event_checklist', task_key: taskKey },
    })
    tasksState.value = [...tasksState.value, data.task]
    newChecklistItem.value = ''
    await syncMissingItems()
}

const removeChecklistTask = async (task) => {
    if (!task?.can_manage_definition) return

    await axios.delete(route('event-tasks.destroy', { eventTask: task.id }))
    tasksState.value = tasksState.value.filter((item) => taskInstanceKey(item) !== taskInstanceKey(task))
    await syncMissingItems()
}

const openTaskForm = async (task) => {
    const taskKey = task?.checklist_json?.task_key || taskKeyFromTitle(task?.title || '')
    const normalizedTaskKey = String(taskKey || '').toLowerCase()
    const hasCustomForm = hasCustomFormForTask(task)
    const hasGlobalForm = globalTaskFormKeys.value.has(normalizedTaskKey)
    const title = (task?.title || '').toLowerCase()
    const isFinalizeAttendeesTask = normalizedTaskKey === 'finalize_attendee_list' || title.includes('finalize attendee list')
    if (!hasCustomForm && isFinalizeAttendeesTask) {
        activeTab.value = 'participants'
        return
    }
    if (!hasCustomForm && normalizedTaskKey === 'transportation_plan') {
        transportTask.value = task
        await openTransportModal()
        return
    }
    const isFixedDocumentTask = fixedDocumentTaskKeys.has(normalizedTaskKey)
    const isDocKeywordTask = taskKey
        ? documentTaskKeywords.some((word) => normalizedTaskKey.includes(word))
        : documentTaskKeywords.some((word) => title.includes(word))
    if (!hasCustomForm && (isFixedDocumentTask || (!hasGlobalForm && isDocKeywordTask))) {
        documentPreset.value = {
            docType: taskKey || 'permission_slip',
            taskId: task.id,
        }
        activeTab.value = 'documents'
        return
    }
    formLoading.value = true
    formError.value = ''
    try {
        const { data } = await axios.get(route('event-tasks.form.show', { eventTask: task.id }), {
            params: task.active_assignment_id ? { assignment_id: task.active_assignment_id } : {},
        })
        formSchema.value = data.schema?.schema_json || null
        const existingData = data.response?.data_json || data.prefill || {}
        if (data.schema?.schema_json?.mode === 'registry') {
            registryRows.value = Array.isArray(existingData?.rows) ? existingData.rows : []
            formData.value = blankDataFromSchema(data.schema?.schema_json)
            registryEditIndex.value = null
        } else {
            registryRows.value = []
            registryEditIndex.value = null
            formData.value = applyCampReservationDefaults(taskKey, existingData)
        }
        activeFormTaskKey.value = data.schema?.key || taskKey || null
        activeFormTask.value = task
    } catch (error) {
        formError.value = error?.response?.data?.message || 'Unable to load form.'
        activeFormTask.value = task
        activeFormTaskKey.value = taskKey || null
        formSchema.value = null
        formData.value = {}
        registryRows.value = []
        registryEditIndex.value = null
    } finally {
        formLoading.value = false
    }
}

const validateFormFields = (schema, data) => {
    const fields = Array.isArray(schema?.fields) ? schema.fields : []
    for (const field of fields) {
        if (!field.required) continue
        const value = data?.[field.key]
        if (field.type === 'select' && field.multiple) {
            if (!Array.isArray(value) || !value.length) return field.label
            continue
        }
        if (field.type === 'checkbox') {
            if (!value) return field.label
            continue
        }
        if (value === null || value === undefined || String(value).trim() === '') {
            return field.label
        }
    }
    return null
}

const persistRegistryRows = async (rows) => {
    if (!activeFormTask.value) return
    const { data } = await axios.put(route('event-tasks.form.update', { eventTask: activeFormTask.value.id }), {
        data_json: { rows },
        ...(activeFormTask.value.active_assignment_id ? { assignment_id: activeFormTask.value.active_assignment_id } : {}),
    })
    activeFormTask.value = {
        ...activeFormTask.value,
        form_response: data.response,
    }
    tasksState.value = tasksState.value.map((item) => taskInstanceKey(item) === taskInstanceKey(activeFormTask.value)
        ? { ...item, form_response: data.response }
        : item)
}

const saveRegistryRow = async () => {
    if (!formSchema.value) return
    const missingLabel = validateFormFields(formSchema.value, formData.value)
    if (missingLabel) {
        formError.value = `${missingLabel} is required.`
        return
    }

    formLoading.value = true
    formError.value = ''
    try {
        const row = { ...formData.value }
        row._row_id = row._row_id || buildRegistryRowId()
        const nextRows = [...registryRows.value]
        if (registryEditIndex.value === null) {
            nextRows.push(row)
        } else {
            nextRows.splice(registryEditIndex.value, 1, row)
        }
        await persistRegistryRows(nextRows)
        registryRows.value = nextRows
        formData.value = blankDataFromSchema(formSchema.value)
        registryEditIndex.value = null
    } catch (error) {
        formError.value = error?.response?.data?.message || 'Unable to save registry row.'
    } finally {
        formLoading.value = false
    }
}

const editRegistryRow = (index) => {
    const row = registryRows.value[index]
    if (!row) return
    formData.value = { ...blankDataFromSchema(formSchema.value), ...row }
    registryEditIndex.value = index
}

const removeRegistryRow = async (index) => {
    const nextRows = registryRows.value.filter((_, rowIndex) => rowIndex !== index)
    formLoading.value = true
    formError.value = ''
    try {
        await persistRegistryRows(nextRows)
        registryRows.value = nextRows
        if (registryEditIndex.value === index) {
            formData.value = blankDataFromSchema(formSchema.value)
            registryEditIndex.value = null
        }
    } catch (error) {
        formError.value = error?.response?.data?.message || 'Unable to remove registry row.'
    } finally {
        formLoading.value = false
    }
}

const cancelRegistryEdit = () => {
    formData.value = blankDataFromSchema(formSchema.value)
    registryEditIndex.value = null
    formError.value = ''
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
        const { data } = await axios.put(route('event-tasks.update', { eventTask: transportTaskRef.id }), taskRequestPayload(transportTaskRef, {
            status: nextStatus,
        }))
        transportTask.value = data.task
        mergeTaskIntoState(data.task)
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
            const { data: taskData } = await axios.put(route('event-tasks.update', { eventTask: finalizeTask.id }), taskRequestPayload(finalizeTask, {
                status: 'done',
            }))
            mergeTaskIntoState(taskData.task)
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
    registryRows.value = []
    registryEditIndex.value = null
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
    if (isRegistryForm.value) {
        await saveRegistryRow()
        return
    }
    formLoading.value = true
    try {
        const { data: responseData } = await axios.put(route('event-tasks.form.update', { eventTask: activeFormTask.value.id }), {
            data_json: formData.value || {},
            ...(activeFormTask.value.active_assignment_id ? { assignment_id: activeFormTask.value.active_assignment_id } : {}),
        })
        activeFormTask.value = {
            ...activeFormTask.value,
            form_response: responseData.response,
        }
        tasksState.value = tasksState.value.map((item) => taskInstanceKey(item) === taskInstanceKey(activeFormTask.value)
            ? { ...item, form_response: responseData.response }
            : item)
        const taskKey = activeFormTaskKey.value || activeFormTask.value?.checklist_json?.task_key
        if (taskKey === 'camp_reservation') {
            await confirmCampReservation()
            await upsertVenueBudgetFromForm()
        }
        if (taskKey === 'emergency_contacts') {
            await confirmEmergencyContacts()
        }
        if (['camp_reservation', 'emergency_contacts'].includes(taskKey)) {
            const { data } = await axios.put(route('event-tasks.update', { eventTask: activeFormTask.value.id }), taskRequestPayload(activeFormTask.value, {
                status: 'done',
            }))
            mergeTaskIntoState(data.task)
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

const cleanupLegacyChecklistDuplicates = async () => {
    if (!canManageTasks.value) return

    const tasks = tasksState.value || []
    const seededTitles = new Set(
        tasks
            .filter((task) => (task?.checklist_json?.source || null) === 'event_type_template')
            .map((task) => normalizedTaskTitle(task))
            .filter(Boolean)
    )

    const duplicates = tasks.filter((task) => {
        const source = task?.checklist_json?.source || null
        return source === 'event_checklist' && seededTitles.has(normalizedTaskTitle(task))
    })

    if (!duplicates.length) return

    for (const task of duplicates) {
        await axios.delete(route('event-tasks.destroy', { eventTask: task.id }))
    }

    const duplicateKeys = new Set(duplicates.map((task) => taskInstanceKey(task)))
    tasksState.value = tasks.filter((task) => !duplicateKeys.has(taskInstanceKey(task)))
}

onMounted(async () => {
    await cleanupLegacyChecklistDuplicates()
    if (seededChecklist.value) return
    if (!canManageTasks.value) return
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
            responsibility_level: 'organizer',
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
        description: payload.event?.description || '',
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

const updateBudget = (payload) => {
    if (Array.isArray(payload)) {
        budgetState.value = payload
        return
    }

    budgetState.value = payload?.items || []
    if (Array.isArray(payload?.accounts)) {
        accountsState.value = payload.accounts
    }
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
                    v-if="canEditEvent"
                    type="button"
                    class="absolute top-3 right-3 text-gray-500 hover:text-gray-700"
                     :title="tr('Editar detalles del evento', 'Edit event details')"
                    @click="showEventModal = true"
                >
                    <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M13.586 3.586a2 2 0 112.828 2.828l-8.5 8.5a1 1 0 01-.39.243l-3 1a1 1 0 01-1.265-1.265l1-3a1 1 0 01.243-.39l8.5-8.5zM12.172 5L5 12.172V15h2.828L15 7.828 12.172 5z"/>
                    </svg>
                </button>
                <div class="mb-4 flex flex-wrap gap-2 pr-8">
                    <a
                        :href="route('events.readiness', { event: eventState.id })"
                        class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100"
                    >
                        {{ tr('Ver preparación', 'View readiness') }}
                    </a>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <div class="text-xs text-gray-500">{{ tr('Scope', 'Scope') }}</div>
                        <div class="font-semibold text-gray-800">{{ scopeLabel || '—' }}</div>
                    </div>
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
                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :class="eventStatusClass(eventState.effective_status || eventState.status)">
                            {{ eventStatusLabel(eventState.effective_status || eventState.status) }}
                        </span>
                    </div>
                    <div v-if="supportsClubOperations">
                        <div class="text-xs text-gray-500">{{ tr('Pagos recibidos', 'Payments Received') }}</div>
                        <div class="font-semibold text-gray-800">
                            {{ formatMoney(paymentSummaryState.total_received || 0) }}
                        </div>
                    </div>
                </div>
                <div v-if="targetClubs.length && !isDownstreamClubViewer" class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-3">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ tr('Clubes involucrados', 'Involved clubs') }}</div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <span
                            v-for="club in targetClubs"
                            :key="club.id"
                            class="inline-flex items-center rounded-full bg-white px-3 py-1 text-xs font-medium text-gray-700 border"
                        >
                            {{ club.club_name }}<span v-if="club.church_name" class="ml-1 text-gray-400">· {{ club.church_name }}</span>
                        </span>
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
                <div class="w-full max-w-3xl rounded-lg bg-white border shadow-xl">
                    <div class="flex items-center justify-between px-5 py-4 border-b">
                        <h2 class="text-lg font-semibold text-gray-800">{{ tr('Editar detalles del evento', 'Edit Event Details') }}</h2>
                        <button type="button" class="text-gray-500 hover:text-gray-700" @click="showEventModal = false">×</button>
                    </div>
                    <div class="max-h-[85vh] space-y-4 overflow-y-auto p-5">
                        <div>
                            <label class="text-xs text-gray-600">{{ tr('Descripción del evento', 'Event Description') }}</label>
                            <textarea
                                v-model="eventForm.description"
                                rows="4"
                                class="w-full border rounded px-3 py-2 text-sm"
                            ></textarea>
                        </div>
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
                            <div>
                                <label class="text-xs text-gray-600">{{ tr('Estado del plan', 'Plan status') }}</label>
                                <select v-model="eventForm.status" class="w-full border rounded px-3 py-2 text-sm">
                                    <option value="draft">{{ tr('Borrador', 'Draft') }}</option>
                                    <option value="plan_finalized">{{ tr('Plan finalizado', 'Plan finalized') }}</option>
                                </select>
                                <div class="mt-1 text-[11px] text-gray-500">
                                    {{ tr('En curso y Pasado se calculan automáticamente por fecha.', 'Ongoing and Past are calculated automatically from the event dates.') }}
                                </div>
                            </div>
                            <div v-if="!supportsClubOperations" class="flex items-center gap-4">
                                <label class="text-xs text-gray-600 flex items-center gap-2">
                                    <input type="checkbox" v-model="eventForm.is_mandatory" />
                                    {{ tr('Evento obligatorio', 'Mandatory event') }}
                                </label>
                            </div>
                        </div>
                        <div class="rounded border border-gray-200 p-4 space-y-3">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <div class="text-xs font-semibold text-gray-700 uppercase tracking-wide">{{ tr('Desglose de inscripción', 'Signup fee breakdown') }}</div>
                                    <div class="text-xs text-gray-500">{{ tr('Edita los componentes que se cobran por participante.', 'Edit the components charged per participant.') }}</div>
                                </div>
                                <div class="shrink-0 text-sm font-semibold text-gray-900 sm:text-right">
                                    <div>${{ eventForm.fee_components.reduce((sum, item) => sum + Number(item.amount || 0), 0).toFixed(2) }}</div>
                                    <div class="text-xs font-medium text-gray-500">
                                        {{ tr('Obligatorio', 'Required') }}: ${{ eventForm.fee_components.filter((item) => item.is_required).reduce((sum, item) => sum + Number(item.amount || 0), 0).toFixed(2) }}
                                    </div>
                                </div>
                            </div>
                            <div class="space-y-2">
                                <div
                                    v-for="(component, index) in eventForm.fee_components"
                                    :key="`edit-fee-component-${index}`"
                                    class="space-y-3 rounded border border-gray-200 p-3"
                                >
                                    <label class="block">
                                        <span class="text-xs font-medium text-gray-600">{{ tr('Concepto', 'Component') }}</span>
                                        <input v-model="component.label" class="mt-1 w-full border rounded px-3 py-2 text-sm" :placeholder="tr('Ej. Inscripción', 'Ex. Registration')" />
                                    </label>
                                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,9rem)_minmax(0,10rem)_auto] sm:items-end">
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-600">{{ tr('Monto', 'Amount') }}</span>
                                            <input v-model="component.amount" type="number" step="0.01" min="0" class="mt-1 w-full border rounded px-3 py-2 text-sm" placeholder="0.00" />
                                        </label>
                                        <div>
                                            <span class="text-xs font-medium text-gray-600">{{ tr('Tipo', 'Type') }}</span>
                                            <label class="mt-1 flex items-center gap-2 rounded border border-gray-200 px-3 py-2 text-sm text-gray-700">
                                                <input v-model="component.is_required" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                                                {{ tr('Obligatorio', 'Required') }}
                                            </label>
                                        </div>
                                        <button
                                            type="button"
                                            class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-red-200 text-lg font-semibold leading-none text-red-700 hover:bg-red-50 sm:justify-self-end"
                                            :aria-label="tr('Quitar componente', 'Remove component')"
                                            :title="tr('Quitar componente', 'Remove component')"
                                            @click="removeEventFeeComponent(index)"
                                        >
                                            ×
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="rounded border px-3 py-2 text-sm text-gray-700" @click="addEventFeeComponent">
                                {{ tr('Agregar componente', 'Add component') }}
                            </button>
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
                    <div v-if="feeComponentsState.length || targetClubSummaryByDistrict.length" class="bg-white rounded-lg border p-4 space-y-4">
                        <div v-if="feeComponentsState.length">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <h2 class="text-lg font-semibold text-gray-800">{{ tr('Desglose de inscripción', 'Signup fee breakdown') }}</h2>
                                    <p class="text-sm text-gray-500">{{ tr('Cada componente se cobra por miembro participante.', 'Each component is charged per participating member.') }}</p>
                                </div>
                                <div class="text-sm font-semibold text-gray-900">
                                    {{ tr('Total por miembro', 'Total per member') }}: ${{ feeComponentsTotal.toFixed(2) }}
                                </div>
                            </div>
                            <div class="mt-3 overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-gray-50 text-gray-600">
                                        <tr>
                                            <th class="px-4 py-2 text-left">{{ tr('Componente', 'Component') }}</th>
                                            <th class="px-4 py-2 text-left">{{ tr('Tipo', 'Type') }}</th>
                                            <th class="px-4 py-2 text-right">{{ tr('Monto', 'Amount') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="component in feeComponentsState" :key="component.id" class="border-t">
                                            <td class="px-4 py-2 text-gray-800">{{ component.label }}</td>
                                            <td class="px-4 py-2">
                                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="component.is_required ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-700'">
                                                    {{ component.is_required ? tr('Obligatorio', 'Required') : tr('Opcional', 'Optional') }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-2 text-right font-medium text-gray-900">${{ Number(component.amount || 0).toFixed(2) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div v-if="targetClubSummaryByDistrict.length && !supportsClubOperations && !isDownstreamClubViewer">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <h2 class="text-lg font-semibold text-gray-800">{{ tr('Clubes involucrados', 'Targeted clubs') }}</h2>
                                    <p class="text-sm text-gray-500">{{ tr('Seguimiento por distrito de clubes inscritos, participantes esperados y pagos recibidos.', 'District-segmented tracking of signed-up clubs, expected participants, and received payments.') }}</p>
                                </div>
                            </div>

                            <div class="mt-3 space-y-4">
                                <div v-for="district in targetClubSummaryByDistrict" :key="district.district_id" class="rounded-lg border border-gray-200">
                                    <div class="border-b bg-gray-50 px-4 py-2 text-sm font-semibold text-gray-800">
                                        {{ district.district_name }}
                                    </div>
                                    <div class="space-y-3 p-3">
                                        <details
                                            v-for="club in district.clubs"
                                            :key="club.club_id"
                                            class="group rounded-lg border border-gray-200 bg-white"
                                        >
                                            <summary class="flex cursor-pointer list-none flex-col gap-3 px-4 py-3 marker:hidden sm:flex-row sm:items-center sm:justify-between">
                                                <div class="min-w-0">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <div class="font-medium text-gray-900 break-words">{{ club.club_name }}</div>
                                                        <span
                                                            class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                                            :class="club.signup_status === 'signed_up' ? 'bg-emerald-100 text-emerald-700' : (club.signup_status === 'declined' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700')"
                                                        >
                                                            {{ club.signup_status }}
                                                        </span>
                                                    </div>
                                                    <div class="mt-1 text-xs text-gray-500 break-words">{{ club.church_name || '—' }}</div>
                                                </div>
                                                <div class="flex items-center gap-3 text-sm text-gray-600 sm:shrink-0">
                                                    <div class="text-right">
                                                        <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ tr('Participantes', 'Participants') }}</div>
                                                        <div class="font-semibold text-gray-900">{{ club.participant_count }}</div>
                                                    </div>
                                                    <div class="text-right">
                                                        <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ tr('Esperado', 'Expected') }}</div>
                                                        <div class="font-semibold text-gray-900">${{ Number(club.expected_amount || 0).toFixed(2) }}</div>
                                                    </div>
                                                    <svg class="h-4 w-4 text-gray-400 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.51a.75.75 0 01-1.08 0l-4.25-4.51a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                                                    </svg>
                                                </div>
                                            </summary>

                                            <div class="border-t border-gray-200 px-4 py-4">
                                                <div class="flex flex-wrap gap-2">
                                                    <button type="button" class="rounded border px-2 py-1 text-xs text-emerald-700 border-emerald-200" @click="updateClubSignupStatus(club, 'signed_up')">
                                                        {{ tr('Inscrito', 'Signed up') }}
                                                    </button>
                                                    <button type="button" class="rounded border px-2 py-1 text-xs text-red-700 border-red-200" @click="updateClubSignupStatus(club, 'declined')">
                                                        {{ tr('No asistirá', 'Decline') }}
                                                    </button>
                                                    <template v-for="receipt in club.settlement_receipts || []" :key="receipt.id">
                                                        <a
                                                            :href="receipt.receipt_url"
                                                            class="rounded border px-2 py-1 text-xs text-slate-700 border-slate-200"
                                                        >
                                                            {{ receipt.receipt_number }}
                                                        </a>
                                                    </template>
                                                </div>

	                                                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
	                                                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 xl:col-span-2">
                                                        <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ tr('Esperado', 'Expected') }}</div>
                                                        <div class="mt-2 space-y-1 text-xs text-gray-500">
                                                            <div
                                                                v-for="component in club.expected_breakdown || []"
                                                                :key="`${club.club_id}-expected-${component.component_id}`"
                                                                class="flex items-start justify-between gap-3"
                                                            >
                                                                <span class="break-words">{{ component.label }}</span>
                                                                <span class="whitespace-nowrap font-medium text-gray-700">
                                                                    ${{ Number(component.amount || 0).toFixed(2) }}
                                                                </span>
                                                            </div>
                                                            <div
                                                                v-if="!(club.expected_breakdown || []).length"
                                                                class="text-gray-400"
                                                            >
                                                                {{ tr('Sin desglose disponible.', 'No breakdown available.') }}
                                                            </div>
                                                        </div>
                                                    </div>
	                                                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 xl:col-span-1">
	                                                        <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ tr('Cobrado por club', 'Collected by club') }}</div>
	                                                        <div class="mt-1 text-lg font-semibold text-gray-900">${{ Number(club.paid_amount || 0).toFixed(2) }}</div>
	                                                    </div>
	                                                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 xl:col-span-1">
	                                                        <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ tr('Pendiente de cobro', 'Still due from members') }}</div>
	                                                        <div class="mt-1 text-lg font-semibold text-gray-900">${{ Number(club.remaining_amount || 0).toFixed(2) }}</div>
	                                                    </div>
	                                                    <div
	                                                        class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 sm:col-span-2"
	                                                        :class="isIntermediateEventFinanceViewer ? 'xl:col-span-2' : 'xl:col-span-4'"
	                                                    >
	                                                        <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ tr('Depositado', 'Deposited') }}</div>
	                                                        <div class="mt-1 text-lg font-semibold text-gray-900">${{ Number(club.deposited_amount || 0).toFixed(2) }}</div>
	                                                    </div>
	                                                    <div
	                                                        v-if="isIntermediateEventFinanceViewer"
	                                                        class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 sm:col-span-2 xl:col-span-2"
	                                                    >
	                                                        <div class="text-[11px] font-semibold uppercase tracking-wide text-amber-700">{{ tr('Listo para depositar', 'Ready to deposit upward') }}</div>
	                                                        <div class="mt-1 text-lg font-semibold text-amber-950">${{ Number(club.pending_settlement_amount || 0).toFixed(2) }}</div>
	                                                        <div class="mt-1 text-xs text-amber-800">
	                                                            {{ tr('Monto ya cobrado por el club y pendiente de enviar al dueño superior del evento.', 'Amount already collected by the club and pending transfer to the upper event owner.') }}
	                                                        </div>
	                                                    </div>
		                                                </div>

                                                    <details class="mt-4 rounded-lg border border-blue-100 bg-blue-50/40">
                                                        <summary class="flex cursor-pointer list-none flex-col gap-2 px-3 py-2 text-sm marker:hidden sm:flex-row sm:items-center sm:justify-between">
                                                            <span class="font-semibold text-blue-950">{{ tr('Pagos registrados', 'Registered payments') }}</span>
                                                            <span class="text-xs font-medium text-blue-800">
                                                                {{ paymentRecordsForClub(club).length }} {{ tr('pagos', 'payments') }} · {{ formatMoney(paymentRecordsTotal(paymentRecordsForClub(club))) }}
                                                            </span>
                                                        </summary>
                                                        <div class="border-t border-blue-100 bg-white p-3">
                                                            <div v-if="paymentRecordsForClub(club).length" class="max-h-72 overflow-auto">
                                                                <table class="min-w-full text-xs">
                                                                    <thead class="bg-gray-50 text-gray-600">
                                                                        <tr>
                                                                            <th class="px-3 py-2 text-left font-semibold">{{ tr('Fecha', 'Date') }}</th>
                                                                            <th class="px-3 py-2 text-left font-semibold">{{ tr('Pagador', 'Payer') }}</th>
                                                                            <th class="px-3 py-2 text-left font-semibold">{{ tr('Desglose', 'Breakdown') }}</th>
                                                                            <th class="px-3 py-2 text-left font-semibold">{{ tr('Método', 'Method') }}</th>
                                                                            <th class="px-3 py-2 text-right font-semibold">{{ tr('Monto', 'Amount') }}</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <tr v-for="payment in paymentRecordsForClub(club)" :key="payment.id" class="border-t align-top">
                                                                            <td class="px-3 py-2 whitespace-nowrap">{{ formatShortDate(payment.payment_date) }}</td>
                                                                            <td class="px-3 py-2">
                                                                                <div class="font-medium text-gray-900">{{ payment.payer_name || '—' }}</div>
                                                                                <div class="text-[11px] capitalize text-gray-500">{{ payment.payer_type || '—' }}</div>
                                                                            </td>
                                                                            <td class="px-3 py-2">
                                                                                <div class="space-y-1">
                                                                                    <div
                                                                                        v-for="(row, index) in paymentRecordBreakdown(payment)"
                                                                                        :key="`${payment.id}-breakdown-${index}`"
                                                                                        class="flex items-start justify-between gap-3"
                                                                                    >
                                                                                        <span class="min-w-0 break-words text-gray-700">
                                                                                            {{ row.component_label || payment.concept_label || tr('Concepto', 'Concept') }}
                                                                                            <span
                                                                                                v-if="row.is_required !== null"
                                                                                                class="ml-1 rounded-full px-1.5 py-0.5 text-[10px] font-semibold"
                                                                                                :class="row.is_required ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-700'"
                                                                                            >
                                                                                                {{ row.is_required ? tr('Obligatorio', 'Required') : tr('Opcional', 'Optional') }}
                                                                                            </span>
                                                                                        </span>
                                                                                        <span class="shrink-0 font-medium text-gray-900">{{ formatMoney(row.amount) }}</span>
                                                                                    </div>
                                                                                </div>
                                                                            </td>
                                                                            <td class="px-3 py-2 capitalize">{{ payment.payment_type || '—' }}</td>
                                                                            <td class="px-3 py-2 text-right font-semibold text-gray-900">{{ formatMoney(payment.amount_paid) }}</td>
                                                                        </tr>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                            <div v-else class="py-4 text-center text-sm text-gray-500">
                                                                {{ tr('Este club todavía no tiene pagos registrados para el evento.', 'This club does not have registered event payments yet.') }}
                                                            </div>
                                                        </div>
                                                    </details>
	                                            </div>
	                                        </details>
	                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg border p-4">
                        <div class="flex gap-3 mb-4">
                            <button @click="activeTab = 'tasks'" :class="activeTab === 'tasks' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600'" class="px-3 py-1 rounded text-sm">{{ tr('Tareas', 'Tasks') }}</button>
                            <button v-if="supportsClubOperations" @click="activeTab = 'budget'" :class="activeTab === 'budget' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600'" class="px-3 py-1 rounded text-sm">{{ tr('Presupuesto', 'Budget') }}</button>
                            <button @click="activeTab = 'participants'" :class="activeTab === 'participants' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600'" class="px-3 py-1 rounded text-sm">{{ tr('Participantes', 'Participants') }}</button>
                            <button @click="activeTab = 'documents'" :class="activeTab === 'documents' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600'" class="px-3 py-1 rounded text-sm">{{ tr('Documentos', 'Documents') }}</button>
                        </div>

                        <div v-if="activeTab === 'tasks'">
                            <div
                                v-if="checklistTasks.length"
                                class="mb-4 grid gap-2 md:grid-cols-2 xl:grid-cols-3"
                            >
                                <button
                                    v-for="task in checklistTasks"
                                    :key="`index-${task.instance_key || task.id}`"
                                    type="button"
                                    class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-left shadow-sm transition hover:border-blue-300 hover:bg-blue-50"
                                    @click="openTaskFromIndex(task)"
                                >
                                    <div class="text-sm font-medium text-gray-900 break-words">{{ task.title }}</div>
                                    <div class="mt-1 flex flex-wrap items-center gap-2 text-[11px] text-gray-500">
                                        <span class="inline-flex items-center rounded-full border border-violet-200 bg-violet-50 px-2 py-0.5 font-medium text-violet-700">
                                            {{ taskOwnerLabel(task) }}
                                        </span>
                                        <span
                                            v-if="task.assignment_summary?.total"
                                            class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 font-medium text-amber-700"
                                        >
                                            {{ task.assignment_summary.done }} / {{ task.assignment_summary.total }} {{ tr('completadas', 'completed') }}
                                        </span>
                                    </div>
                                </button>
                            </div>
                            <details
                                class="group mb-4 rounded-xl border bg-gray-50"
                                :open="eventTasksExpanded"
                                @toggle="eventTasksExpanded = $event.target.open"
                            >
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 xl:px-6">
                                    <div>
                                        <h3 class="text-base font-semibold text-gray-800">{{ tr('Tareas del evento', 'Event tasks') }}</h3>
                                        <div class="mt-1 text-xs text-gray-500">{{ missingCount }} {{ tr('pendientes', 'remaining') }}</div>
                                    </div>
                                    <svg class="h-4 w-4 text-gray-400 transition group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.51a.75.75 0 01-1.08 0l-4.25-4.51a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                                    </svg>
                                </summary>
                                <div class="border-t border-gray-200 px-5 py-5 xl:px-6">
                                <div class="space-y-3">
                                    <div v-if="!checklistTasks.length" class="text-xs text-gray-500">
                                        {{ tr('Aún no hay elementos del checklist.', 'No checklist items yet.') }}
                                    </div>
                                    <div
                                        v-for="task in checklistTasks"
                                        :key="task.instance_key || task.id"
                                        :id="taskDomId(task)"
                                        class="rounded-xl border border-gray-200 bg-white shadow-sm"
                                    >
                                        <details
                                            class="group"
                                            :open="isTaskOpen(task)"
                                            @toggle="setTaskOpen(task, $event.target.open)"
                                        >
                                            <summary class="flex cursor-pointer list-none items-start justify-between gap-3 px-5 py-4">
                                                <div class="min-w-0">
                                                    <div
                                                        :class="[task.status === 'done' ? 'line-through text-gray-400' : 'text-gray-900', 'text-sm font-semibold break-words']"
                                                    >
                                                        {{ task.title }}
                                                    </div>
                                                    <div class="mt-2 flex flex-wrap items-center gap-2 text-[11px]">
                                                        <span class="inline-flex items-center rounded-full border border-violet-200 bg-violet-50 px-2 py-0.5 font-medium text-violet-700">
                                                            {{ taskOwnerLabel(task) }}
                                                        </span>
                                                        <span
                                                            v-if="task.assignment_scope_label"
                                                            class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 font-medium text-slate-700"
                                                        >
                                                            {{ task.assignment_scope_label }}
                                                        </span>
                                                        <span
                                                            v-if="task.assignment_summary?.total"
                                                            class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 font-medium text-amber-700"
                                                        >
                                                            {{ task.assignment_summary.done }} / {{ task.assignment_summary.total }} {{ tr('completadas', 'completed') }}
                                                        </span>
                                                    </div>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <span
                                                        class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold"
                                                        :class="task.status === 'done' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'"
                                                    >
                                                        {{ task.status === 'done' ? tr('Completada', 'Done') : tr('Pendiente', 'Pending') }}
                                                    </span>
                                                    <svg class="mt-0.5 h-4 w-4 text-gray-400 transition group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.51a.75.75 0 01-1.08 0l-4.25-4.51a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                                                    </svg>
                                                </div>
                                            </summary>

                                            <div class="border-t border-gray-200 px-5 py-5">
                                                <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(300px,340px)] 2xl:grid-cols-[minmax(0,1fr)_360px]">
                                                    <div class="min-w-0">
                                                        <div class="flex items-start gap-3">
                                                            <input
                                                                v-if="task.can_complete_task"
                                                                type="checkbox"
                                                                class="mt-1 rounded border-gray-300 text-blue-600"
                                                                :checked="task.status === 'done'"
                                                                @change="toggleChecklistTask(task)"
                                                            />
                                                            <div class="min-w-0 flex-1">
                                                                <template v-if="editingTaskId === task.id">
                                                                    <div class="flex flex-wrap items-center gap-2">
                                                                        <input
                                                                            v-model="editingTaskTitle"
                                                                            type="text"
                                                                            class="min-w-[260px] flex-1 rounded border border-gray-300 px-2 py-1 text-sm text-gray-800"
                                                                            @click.stop
                                                                            @keydown.enter.prevent="saveTaskTitleEdit(task)"
                                                                            @keydown.esc.prevent="cancelTaskTitleEdit"
                                                                        />
                                                                        <button
                                                                            type="button"
                                                                            class="text-xs text-blue-600 hover:text-blue-700"
                                                                            @click.prevent.stop="saveTaskTitleEdit(task)"
                                                                        >
                                                                            {{ tr('Guardar', 'Save') }}
                                                                        </button>
                                                                        <button
                                                                            type="button"
                                                                            class="text-xs text-gray-500 hover:text-gray-700"
                                                                            @click.prevent.stop="cancelTaskTitleEdit"
                                                                        >
                                                                            {{ tr('Cancelar', 'Cancel') }}
                                                                        </button>
                                                                    </div>
                                                                </template>

                                                                    <div class="space-y-3">
                                                                        <div
                                                                            v-if="isFinalizeAttendeeTask(task) || (hasFormForTask(task) && task.can_complete_task) || (!hasFormForTask(task) && task.can_edit_form_definition)"
                                                                            class="flex flex-wrap items-center gap-2 rounded-lg border border-blue-100 bg-blue-50 px-3 py-2"
                                                                        >
                                                                            <button
                                                                                v-if="isFinalizeAttendeeTask(task)"
                                                                                type="button"
                                                                                class="rounded border border-blue-200 bg-white px-3 py-2 text-xs font-medium text-blue-700 hover:bg-blue-100"
                                                                                @click="activeTab = 'participants'"
                                                                            >
                                                                                {{ tr('Ir a participantes', 'Go to participants') }}
                                                                            </button>
                                                                            <button
                                                                                v-else-if="hasFormForTask(task) && task.can_complete_task"
                                                                                type="button"
                                                                                class="rounded border border-blue-200 bg-white px-3 py-2 text-xs font-medium text-blue-700 hover:bg-blue-100"
                                                                                @click="openTaskForm(task)"
                                                                            >
                                                                                {{ taskCompletionActionLabel(task) }}
                                                                            </button>
                                                                            <button
                                                                                v-else-if="!hasFormForTask(task) && task.can_edit_form_definition"
                                                                                type="button"
                                                                                class="rounded border border-amber-200 bg-white px-3 py-2 text-xs font-medium text-amber-700 hover:bg-amber-100"
                                                                                @click="openCustomFormBuilderModal(task)"
                                                                            >
                                                                                {{ tr('Crear formulario', 'Create form') }}
                                                                            </button>
                                                                            <span class="text-xs text-blue-700">
                                                                                {{
                                                                                    !hasFormForTask(task) && task.can_edit_form_definition
                                                                                        ? tr('Esta tarea necesita un formulario antes de poder completarse.', 'This task needs a form before it can be completed.')
                                                                                        : tr('Completa esta tarea desde el formulario asignado.', 'Complete this task from the assigned form.')
                                                                                }}
                                                                            </span>
                                                                        </div>

                                                                        <div class="flex flex-wrap items-center gap-2">
                                                                            <span
                                                                                v-if="editingTaskId !== task.id && task.description"
                                                                                class="inline-flex items-center rounded-full border border-sky-200 bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-sky-700 cursor-pointer"
                                                                                :title="task.description"
                                                                            @click.prevent.stop="openTaskInfoModal(task)"
                                                                        >
                                                                            Info
                                                                        </span>
                                                                        <span
                                                                            v-if="isPermissionSlipTask(task)"
                                                                            class="inline-flex items-center rounded-full border border-blue-200 bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700"
                                                                            :title="tr('Permisos recibidos', 'Permission slips received')"
                                                                        >
                                                                            {{ permissionSlipStats.received }} / {{ permissionSlipStats.required }} {{ tr('permisos', 'slips') }}
                                                                        </span>
                                                                        <span
                                                                            v-if="hasFormForTask(task)"
                                                                            class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700"
                                                                            :title="tr('Accion disponible', 'Action available')"
                                                                        >
                                                                            {{ taskCompletionBadgeLabel(task) }}
                                                                        </span>
                                                                        <button
                                                                            v-else-if="task.can_edit_form_definition"
                                                                            type="button"
                                                                            class="inline-flex items-center rounded-full border border-rose-200 bg-rose-50 px-2 py-0.5 text-[11px] font-medium text-rose-700 hover:bg-rose-100"
                                                                            :title="tr('Crear formulario para esta tarea', 'Create form for this task')"
                                                                            @click="openCustomFormBuilderModal(task)"
                                                                        >
                                                                            {{ tr('Sin formulario', 'No form') }}
                                                                        </button>
                                                                        <span
                                                                            v-else
                                                                            class="inline-flex items-center rounded-full border border-rose-200 bg-rose-50 px-2 py-0.5 text-[11px] font-medium text-rose-700"
                                                                            :title="tr('Sin formulario disponible', 'No form available')"
                                                                        >
                                                                            {{ tr('Sin formulario', 'No form') }}
                                                                        </span>
                                                                    </div>

                                                                    <div
                                                                        v-if="task.can_manage_definition && taskTrackingTree(task).length"
                                                                        class="space-y-2 rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs text-gray-600"
                                                                    >
                                                                        <div class="font-semibold text-gray-700">
                                                                            {{ tr('Seguimiento por nivel asignado', 'Assigned-level tracking') }}
                                                                        </div>
                                                                        <div class="space-y-2">
                                                                            <details
                                                                                v-for="group in taskTrackingTree(task)"
                                                                                :key="group.key"
                                                                                class="rounded border border-gray-200 bg-white"
                                                                            >
                                                                                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2">
                                                                                    <span class="font-medium text-gray-700">{{ group.label }}</span>
                                                                                    <span class="text-[11px] font-semibold text-gray-500">
                                                                                        {{ group.summary.done }} / {{ group.summary.total }} {{ tr('completadas', 'completed') }}
                                                                                    </span>
                                                                                </summary>
                                                                                <div class="border-t border-gray-200 px-3 py-2">
                                                                                    <div v-if="group.rows.length" class="space-y-1">
                                                                                        <div v-for="row in group.rows" :key="row.id" class="flex items-center justify-between gap-2">
                                                                                            <span class="text-gray-700">{{ row.scope_label }}</span>
                                                                                            <span :class="row.status === 'done' ? 'text-emerald-700' : 'text-amber-700'" class="font-medium">
                                                                                                {{ row.status }}
                                                                                            </span>
                                                                                        </div>
                                                                                    </div>
                                                                                    <div v-if="group.children.length" class="space-y-2">
                                                                                        <details
                                                                                            v-for="child in group.children"
                                                                                            :key="child.key"
                                                                                            class="rounded border border-gray-200 bg-gray-50"
                                                                                        >
                                                                                            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2">
                                                                                                <span class="text-gray-700">{{ child.label }}</span>
                                                                                                <span class="text-[11px] font-semibold text-gray-500">
                                                                                                    {{ child.summary.done }} / {{ child.summary.total }} {{ tr('completadas', 'completed') }}
                                                                                                </span>
                                                                                            </summary>
                                                                                            <div class="border-t border-gray-200 px-3 py-2">
                                                                                                <div v-if="child.rows.length" class="space-y-1">
                                                                                                    <div v-for="row in child.rows" :key="row.id" class="flex items-center justify-between gap-2">
                                                                                                        <span class="text-gray-700">{{ row.scope_label }}</span>
                                                                                                        <span :class="row.status === 'done' ? 'text-emerald-700' : 'text-amber-700'" class="font-medium">
                                                                                                            {{ row.status }}
                                                                                                        </span>
                                                                                                    </div>
                                                                                                </div>
                                                                                                <div v-if="child.children.length" class="space-y-1">
                                                                                                    <div v-for="rowChild in child.children" :key="rowChild.key" class="flex items-center justify-between gap-2 rounded border border-gray-200 bg-white px-3 py-2">
                                                                                                        <span class="text-gray-700">{{ rowChild.label }}</span>
                                                                                                        <span :class="rowChild.rows[0]?.status === 'done' ? 'text-emerald-700' : 'text-amber-700'" class="font-medium">
                                                                                                            {{ rowChild.rows[0]?.status || 'todo' }}
                                                                                                        </span>
                                                                                                    </div>
                                                                                                </div>
                                                                                            </div>
                                                                                        </details>
                                                                                    </div>
                                                                                </div>
                                                                            </details>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                                                        <div class="space-y-3">
                                                            <div v-if="task.can_manage_definition && taskResponsibilityOptions.length > 1" class="space-y-1.5">
                                                                <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                                                                    {{ tr('Responsable', 'Owner') }}
                                                                </div>
                                                                <select
                                                                    class="w-full rounded border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700"
                                                                    :value="task.responsibility_level || 'organizer'"
                                                                    @change="updateTaskResponsibility(task, $event.target.value)"
                                                                >
                                                                    <option v-for="option in taskResponsibilityOptions" :key="option.value" :value="option.value">
                                                                        {{ option.label }}
                                                                    </option>
                                                                </select>
                                                            </div>

                                                            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-1">
                                                                <button
                                                                    v-if="editingTaskId !== task.id && task.can_manage_definition"
                                                                    type="button"
                                                                    class="w-full rounded border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700 hover:bg-white"
                                                                    @click.prevent.stop="startTaskTitleEdit(task)"
                                                                >
                                                                    {{ tr('Renombrar', 'Rename') }}
                                                                </button>
                                                                <button
                                                                    v-if="isFinalizeAttendeeTask(task)"
                                                                    type="button"
                                                                    class="w-full rounded border border-blue-200 px-3 py-2 text-xs font-medium text-blue-700 hover:bg-blue-50"
                                                                    @click="activeTab = 'participants'"
                                                                >
                                                                    Go to Participants
                                                                </button>
                                                                <button
                                                                    v-if="task.can_edit_form_definition && hasAiSuggestedForm(task)"
                                                                    type="button"
                                                                    class="w-full rounded border border-amber-200 px-3 py-2 text-xs font-medium text-amber-700 hover:bg-amber-50"
                                                                    @click="openCustomFormBuilderModal(task)"
                                                                >
                                                                    Edit Form
                                                                </button>
                                                                <button
                                                                    v-else-if="task.can_edit_form_definition"
                                                                    type="button"
                                                                    class="w-full rounded border border-amber-200 px-3 py-2 text-xs font-medium text-amber-700 hover:bg-amber-50"
                                                                    @click="openCustomFormBuilderModal(task)"
                                                                >
                                                                    Create Form
                                                                </button>
                                                                <button
                                                                    v-if="task.can_manage_definition"
                                                                    type="button"
                                                                    class="w-full rounded border border-rose-200 px-3 py-2 text-xs font-medium text-rose-700 hover:bg-rose-50"
                                                                    @click="removeChecklistTask(task)"
                                                                >
                                                                    Remove
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </details>
                                    </div>
                                </div>
                                <div v-if="canManageTasks" class="mt-3 flex items-center gap-2">
                                    <input v-model="newChecklistItem" type="text" class="flex-1 rounded border border-gray-300 px-2 py-1 text-sm" :placeholder="tr('Agregar elemento al checklist', 'Add checklist item')" />
                                    <button type="button" class="px-3 py-1 rounded text-sm bg-blue-600 text-white" @click="addChecklistItem">{{ tr('Agregar', 'Add') }}</button>
                                </div>
                                </div>
                            </details>
                        </div>
                        <div v-else-if="supportsClubOperations && activeTab === 'budget'">
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
                                :club-id="eventState.club_id"
                                :accounts="accountsState"
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
                                :event-scope-type="eventState.scope_type || 'club'"
                                :members="membersState"
                                :classes="classesState"
                                :staff="staffState"
                                :parents="parentsState"
                                :payment-summary="paymentSummaryState"
                                :payment-config="paymentConfigState"
                                :can-manage="props.canManageParticipants"
                                :club-summary="participantClubSummaryState"
                                :participant-roster="participantRosterState"
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

        <div
            v-if="taskInfoModal.open"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="closeTaskInfoModal"
        >
            <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <h3 class="text-base font-semibold text-gray-800">{{ taskInfoModal.title }}</h3>
                    <button type="button" class="text-gray-400 hover:text-gray-600" @click="closeTaskInfoModal">✕</button>
                </div>
                <div class="text-sm text-gray-700 whitespace-pre-line">
                    {{ taskInfoModal.description }}
                </div>
                <div class="mt-4 flex justify-end">
                    <button type="button" class="px-3 py-1 rounded text-sm bg-blue-600 text-white" @click="closeTaskInfoModal">
                        {{ tr('Cerrar', 'Close') }}
                    </button>
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
                <div class="mb-3">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input
                            type="checkbox"
                            class="rounded border-gray-300 text-blue-600"
                            :checked="customFormMode === 'registry'"
                            @change="customFormMode = $event.target.checked ? 'registry' : 'single'"
                        />
                        {{ tr('Modo registro en tabla', 'Registry table mode') }}
                    </label>
                </div>
                <div class="mb-3 text-xs text-gray-500">
                    {{ tr('Marca la casilla en la columna "Obligatorio" para requerir un campo en el formulario.', 'Check the "Required" column to make a field mandatory in the form.') }}
                </div>
                <div class="space-y-2 max-h-[55vh] overflow-auto pr-1">
                    <div class="grid grid-cols-12 gap-2 items-center text-[11px] font-medium uppercase tracking-wide text-gray-500 px-1">
                        <div class="col-span-4">{{ tr('Etiqueta', 'Label') }}</div>
                        <div class="col-span-3">Key</div>
                        <div class="col-span-3">{{ tr('Tipo', 'Type') }}</div>
                        <div class="col-span-1 text-center">{{ tr('Obligatorio', 'Required') }}</div>
                        <div class="col-span-1"></div>
                    </div>
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
                            <option value="time">time</option>
                            <option value="select">select</option>
                            <option value="checkbox">checkbox</option>
                            <option value="image">image/banner</option>
                        </select>
                        <label class="col-span-1 flex items-center justify-center">
                            <input type="checkbox" v-model="field.required" class="rounded border-gray-300 text-blue-600" />
                        </label>
                        <div class="col-span-1 flex flex-col items-end gap-1">
                            <button type="button" class="text-[11px] text-gray-500 hover:text-gray-700" @click="moveCustomFormField(idx, -1)">↑</button>
                            <button type="button" class="text-[11px] text-gray-500 hover:text-gray-700" @click="moveCustomFormField(idx, 1)">↓</button>
                            <button type="button" class="text-[11px] text-red-600" @click="removeCustomFormField(idx)">{{ tr('Eliminar', 'Remove') }}</button>
                        </div>
                        <input
                            v-model="field.help"
                            class="col-span-6 rounded border border-gray-300 px-2 py-1 text-sm"
                            :placeholder="tr('Ayuda o instrucción corta', 'Short help text')"
                        />
                        <div
                            v-if="field.type === 'image'"
                            class="col-span-6 rounded border border-dashed border-gray-300 px-3 py-2 text-xs text-gray-500"
                        >
                            {{ tr('Este campo mostrará un botón para subir imagen cuando se abra el formulario de la tarea.', 'This field will show an image upload button when the task form is opened.') }}
                        </div>
                        <select
                            v-if="field.type === 'select'"
                            v-model="field.source"
                            class="col-span-3 rounded border border-gray-300 px-2 py-1 text-sm"
                        >
                            <option value="">{{ tr('Opciones manuales', 'Manual options') }}</option>
                            <option value="members">{{ tr('Miembros', 'Members') }}</option>
                            <option value="staff">{{ tr('Staff', 'Staff') }}</option>
                            <option value="classes">{{ tr('Clases', 'Classes') }}</option>
                            <option value="participants">{{ tr('Participantes', 'Participants') }}</option>
                            <option value="task_data">{{ tr('Datos de otra tarea', 'Data from another task') }}</option>
                        </select>
                        <select
                            v-if="field.type === 'select' && field.source === 'task_data'"
                            v-model="field.sourceTaskId"
                            class="col-span-3 rounded border border-gray-300 px-2 py-1 text-sm"
                        >
                            <option value="">{{ tr('Selecciona la tarea origen', 'Select source task') }}</option>
                            <option
                                v-for="taskOption in taskDataSourceTasks.filter((taskOption) => taskOption.id !== customFormTask?.id)"
                                :key="taskOption.id"
                                :value="taskOption.id"
                            >
                                {{ taskOption.title }}
                            </option>
                        </select>
                        <select
                            v-if="field.type === 'select' && field.source === 'task_data' && field.sourceTaskId"
                            v-model="field.sourceLabelField"
                            class="col-span-3 rounded border border-gray-300 px-2 py-1 text-sm"
                        >
                            <option value="">{{ tr('Campo visible', 'Display field') }}</option>
                            <option
                                v-for="sourceField in taskSourceFieldChoices(field.sourceTaskId)"
                                :key="sourceField.value"
                                :value="sourceField.value"
                            >
                                {{ sourceField.label }}
                            </option>
                        </select>
                        <select
                            v-if="field.type === 'select' && field.source === 'participants'"
                            v-model="field.sourceParticipantRole"
                            class="col-span-3 rounded border border-gray-300 px-2 py-1 text-sm"
                        >
                            <option value="">{{ tr('Todos los roles', 'All roles') }}</option>
                            <option value="kid">{{ tr('Menores', 'Kids') }}</option>
                            <option value="staff">{{ tr('Staff', 'Staff') }}</option>
                            <option value="parent">{{ tr('Padres', 'Parents') }}</option>
                            <option value="invitee">{{ tr('Invitados', 'Invitees') }}</option>
                            <option value="driver">{{ tr('Conductores', 'Drivers') }}</option>
                        </select>
                        <select
                            v-if="field.type === 'select' && field.source === 'participants'"
                            v-model="field.sourceParticipantStatus"
                            class="col-span-3 rounded border border-gray-300 px-2 py-1 text-sm"
                        >
                            <option value="">{{ tr('Todos los estados', 'All statuses') }}</option>
                            <option value="invited">{{ tr('Invitado', 'Invited') }}</option>
                            <option value="confirmed">{{ tr('Confirmado', 'Confirmed') }}</option>
                            <option value="declined">{{ tr('Declinado', 'Declined') }}</option>
                            <option value="waitlisted">{{ tr('En espera', 'Waitlisted') }}</option>
                        </select>
                        <input
                            v-if="field.type === 'select' && !field.source"
                            v-model="field.optionsText"
                            class="col-span-3 rounded border border-gray-300 px-2 py-1 text-sm"
                            :placeholder="tr('Opciones separadas por coma', 'Comma-separated options')"
                        />
                        <label
                            v-if="field.type === 'select'"
                            class="col-span-3 inline-flex items-center gap-2 text-xs text-gray-600"
                        >
                            <input type="checkbox" v-model="field.multiple" class="rounded border-gray-300 text-blue-600" />
                            {{ tr('Selección múltiple', 'Multiple selection') }}
                        </label>
                    </div>
                </div>
                <div class="mt-3">
                    <div class="flex items-center gap-2">
                        <button type="button" class="px-3 py-1 rounded text-xs bg-gray-200 text-gray-700" @click="addCustomFormField">
                            Add field
                        </button>
                        <button
                            type="button"
                            class="px-3 py-1 rounded text-xs bg-blue-100 text-blue-700"
                            :disabled="customFormSuggesting"
                            @click="suggestCustomFormDefinition"
                        >
                            {{ customFormSuggesting ? tr('Sugiriendo...', 'Suggesting...') : tr('Sugerir con IA', 'Suggest with AI') }}
                        </button>
                    </div>
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
                    <div
                        v-if="isRegistryForm"
                        class="rounded-lg border bg-gray-50 p-3 space-y-3"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-sm font-semibold text-gray-800">{{ tr('Registros guardados', 'Saved rows') }}</div>
                            <div class="text-xs text-gray-500">{{ registryRows.length }} {{ tr('filas', 'rows') }}</div>
                        </div>
                        <div v-if="registryRows.length" class="overflow-x-auto">
                            <table class="min-w-full text-xs">
                                <thead class="bg-gray-100 text-gray-600">
                                    <tr>
                                        <th v-for="field in formSchema.fields" :key="field.key" class="px-2 py-1 text-left">
                                            {{ field.label }}
                                        </th>
                                        <th class="px-2 py-1 text-right">{{ tr('Acciones', 'Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, index) in registryRows" :key="index" class="border-t">
                                        <td v-for="field in formSchema.fields" :key="field.key" class="px-2 py-1 align-top">
                                            {{ formatFieldValue(field, row[field.key]) }}
                                        </td>
                                        <td class="px-2 py-1 text-right whitespace-nowrap">
                                            <button type="button" class="text-blue-600 hover:text-blue-700 mr-2" @click="editRegistryRow(index)">
                                                {{ tr('Editar', 'Edit') }}
                                            </button>
                                            <button type="button" class="text-red-600 hover:text-red-700" @click="removeRegistryRow(index)">
                                                {{ tr('Eliminar', 'Delete') }}
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="text-xs text-gray-500">
                            {{ tr('Aún no hay filas registradas.', 'No rows saved yet.') }}
                        </div>
                        <div class="text-sm font-semibold text-gray-800">
                            {{ registryEditIndex === null ? tr('Agregar registro', 'Add row') : tr('Editar registro', 'Edit row') }}
                        </div>
                    </div>
                    <div v-for="field in formSchema.fields" :key="field.key" class="space-y-1">
                        <label class="text-sm font-medium text-gray-700">
                            {{ field.label }}
                            <span v-if="field.required" class="text-red-500">*</span>
                        </label>
                        <div
                            v-if="field.type === 'image'"
                            class="rounded-lg border border-gray-200 bg-gray-50 p-3 space-y-3"
                        >
                            <div v-if="formData[field.key]" class="overflow-hidden rounded border border-gray-200 bg-white">
                                <img
                                    :src="typeof formData[field.key] === 'object' ? formData[field.key]?.url : formData[field.key]"
                                    :alt="field.label || 'Task image'"
                                    class="h-auto max-h-64 w-full object-cover"
                                />
                            </div>
                            <div class="flex items-center gap-3">
                                <label class="inline-flex cursor-pointer items-center justify-center rounded border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                    <input
                                        type="file"
                                        accept="image/*"
                                        class="hidden"
                                        @change="uploadTaskFormImage(field, $event.target.files?.[0])"
                                    />
                                    {{ formImageUploadingField === field.key ? tr('Subiendo...', 'Uploading...') : tr('Subir imagen', 'Upload image') }}
                                </label>
                                <button
                                    v-if="formData[field.key]"
                                    type="button"
                                    class="rounded border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-700 hover:bg-blue-100"
                                    @click="copyImageFieldUrl(field)"
                                >
                                    {{ tr('Copiar URL', 'Copy URL') }}
                                </button>
                                <button
                                    v-if="formData[field.key]"
                                    type="button"
                                    class="text-sm text-red-600 hover:text-red-700"
                                    @click="formData = { ...formData, [field.key]: '' }"
                                >
                                    {{ tr('Quitar imagen', 'Remove image') }}
                                </button>
                            </div>
                            <div v-if="field.help" class="text-xs text-gray-600">
                                {{ field.help }}
                            </div>
                            <div v-if="formData[field.key]" class="space-y-1">
                                <div class="text-xs font-medium text-gray-600">
                                    {{ tr('URL pública compartible', 'Public shareable URL') }}
                                </div>
                                <input
                                    :value="typeof formData[field.key] === 'object' ? formData[field.key]?.url : formData[field.key]"
                                    readonly
                                    class="w-full rounded border border-gray-300 bg-white px-3 py-2 text-xs text-gray-700"
                                />
                            </div>
                        </div>
                        <input
                            v-else-if="field.type === 'text' || field.type === 'date' || field.type === 'time' || field.type === 'number'"
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
                            v-else-if="field.type === 'select' && !field.multiple"
                            v-model="formData[field.key]"
                            class="w-full rounded border border-gray-300 px-3 py-2 text-sm"
                        >
                            <option value="" disabled>{{ tr('Selecciona...', 'Select...') }}</option>
                            <option v-for="option in availableOptionsForField(field)" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                        <div v-else-if="field.type === 'select' && field.multiple" class="space-y-2">
                            <div class="flex flex-wrap items-center gap-2 rounded border border-gray-300 px-3 py-2 min-h-[44px]">
                                <span
                                    v-for="selectedValue in selectedValuesForField(field)"
                                    :key="`${field.key}-${selectedValue}`"
                                    class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-1 text-xs text-blue-800"
                                >
                                    {{ formatFieldValue(field, [selectedValue]).replace(/^|$/g, '').replace(/^, |, $/g, '') }}
                                    <button
                                        type="button"
                                        class="text-blue-700 hover:text-blue-900"
                                        @click="removeMultiSelectValue(field, selectedValue)"
                                    >
                                        ×
                                    </button>
                                </span>
                                <input
                                    v-model="multiSelectQueries[field.key]"
                                    type="text"
                                    class="flex-1 min-w-[180px] border-0 p-0 text-sm focus:ring-0"
                                    :placeholder="tr('Escribe para buscar...', 'Type to search...')"
                                    @keydown.enter.prevent="filteredMultiSelectOptions(field).length ? addMultiSelectValue(field, filteredMultiSelectOptions(field)[0].value) : null"
                                />
                            </div>
                            <div
                                v-if="filteredMultiSelectOptions(field).length"
                                class="max-h-40 overflow-auto rounded border border-gray-200 bg-white"
                            >
                                <button
                                    v-for="option in filteredMultiSelectOptions(field)"
                                    :key="`${field.key}-${option.value}`"
                                    type="button"
                                    class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50"
                                    @click="addMultiSelectValue(field, option.value)"
                                >
                                    {{ option.label }}
                                </button>
                            </div>
                        </div>
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
                    <button
                        v-if="isRegistryForm && registryEditIndex !== null"
                        type="button"
                        class="px-3 py-1 rounded text-sm bg-gray-100 text-gray-600"
                        @click="cancelRegistryEdit"
                    >
                        {{ tr('Cancelar edición', 'Cancel edit') }}
                    </button>
                    <button type="button" class="px-3 py-1 rounded text-sm bg-gray-100 text-gray-600" @click="closeTaskForm">{{ tr('Cerrar', 'Close') }}</button>
                    <button type="button" class="px-3 py-1 rounded text-sm bg-blue-600 text-white" :disabled="formLoading" @click="saveTaskForm">
                        {{
                            isRegistryForm
                                ? (registryEditIndex === null ? tr('Agregar fila', 'Add row') : tr('Actualizar fila', 'Update row'))
                                : 'Save'
                        }}
                    </button>
                </div>
            </div>
        </div>
    </PathfinderLayout>
</template>
