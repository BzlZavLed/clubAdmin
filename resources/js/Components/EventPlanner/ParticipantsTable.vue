<script setup>
import { computed, ref } from 'vue'
import axios from 'axios'
import { Link } from '@inertiajs/vue3'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps({
    participants: {
        type: Array,
        default: () => []
    },
    eventId: {
        type: Number,
        required: true
    },
    eventScopeType: {
        type: String,
        default: 'club'
    },
    members: {
        type: Array,
        default: () => []
    },
    classes: {
        type: Array,
        default: () => []
    },
    staff: {
        type: Array,
        default: () => []
    },
    parents: {
        type: Array,
        default: () => []
    },
    paymentSummary: {
        type: Object,
        default: () => ({ total_received: 0, by_member_id: {}, by_staff_id: {} })
    },
    paymentConfig: {
        type: Object,
        default: () => ({ concept_id: null, amount: null, total_amount: null, is_payable: false, concepts: [] })
    },
    canManage: {
        type: Boolean,
        default: true
    },
    clubSummary: {
        type: Array,
        default: () => []
    },
    participantRoster: {
        type: Array,
        default: () => []
    }
})

const emit = defineEmits(['updated', 'finish-list'])
const { tr } = useLocale()
const highlightIds = ref(new Set())
const selectedParticipantIds = ref([])
const showRosterModal = ref(false)

const refresh = async () => {
    const { data } = await axios.get(route('event-participants.index', { event: props.eventId }))
    emit('updated', data.participants || [])
}

const markHighlights = (ids) => {
    if (!ids.length) return
    highlightIds.value = new Set(ids)
    setTimeout(() => {
        highlightIds.value = new Set()
    }, 2200)
}

const addParticipant = async (payload) => {
    const { data } = await axios.post(route('event-participants.store', { event: props.eventId }), payload)
    return data?.participant?.id || null
}

const participantType = ref('club_member')
const memberSelectMode = ref('manual')
const selectedMemberIds = ref([])
const selectedClassIds = ref([])
const selectedStaffIds = ref([])
const selectedParentIds = ref([])
const customParticipant = ref({ name: '', role: 'invitee', status: 'invited', note: '' })

const existingMemberIds = computed(() => new Set(props.participants.map((p) => p.member_id).filter(Boolean)))
const existingStaffIds = computed(() => new Set(props.participants.map((p) => p.staff_id).filter(Boolean).map(Number)))
const existingStaffNames = computed(() => new Set(props.participants.filter((p) => p.role === 'staff').map((p) => p.participant_name)))
const existingParentNames = computed(() => new Set(props.participants.filter((p) => p.role === 'parent').map((p) => p.participant_name)))

const availableMembers = computed(() => {
    const existing = existingMemberIds.value
    return props.members.filter((member) => !existing.has(member.member_id))
})

const classNameById = computed(() => {
    const map = new Map()
    for (const clubClass of props.classes) {
        map.set(clubClass.id, clubClass.class_name)
    }
    return map
})

const memberById = computed(() => {
    const map = new Map()
    for (const member of props.members) {
        map.set(member.member_id, member)
    }
    return map
})

const parentByName = computed(() => {
    const map = new Map()
    for (const parent of props.parents) {
        if (parent.name) {
            map.set(parent.name, parent)
        }
    }
    return map
})

const paymentByMember = computed(() => {
    const map = new Map()
    const entries = props.paymentSummary?.by_member_required_id || props.paymentSummary?.by_member_id || {}
    for (const [id, total] of Object.entries(entries)) {
        map.set(Number(id), Number(total))
    }
    return map
})

const paymentByStaff = computed(() => {
    const map = new Map()
    const entries = props.paymentSummary?.by_staff_required_id || props.paymentSummary?.by_staff_id || {}
    for (const [id, total] of Object.entries(entries)) {
        map.set(Number(id), Number(total))
    }
    return map
})

const staffByName = computed(() => {
    const map = new Map()
    for (const staffMember of props.staff) {
        if (staffMember?.name) {
            map.set(staffMember.name, staffMember)
        }
    }
    return map
})

const staffById = computed(() => {
    const map = new Map()
    for (const staffMember of props.staff) {
        map.set(Number(staffMember.id), staffMember)
    }
    return map
})

const asNumber = (value) => Number(value || 0)
const formatMoney = (value) => {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(asNumber(value))
}
const rowEnrolledMemberCount = (row) => asNumber(row.enrolled_member_count ?? row.paid_member_count ?? 0)
const rowConfirmedMemberCount = (row) => {
    if (row.has_required_payment) {
        return asNumber(row.confirmed_unpaid_member_count)
    }

    return asNumber(row.manual_confirmed_member_count ?? row.confirmed_member_count ?? 0)
}
const rowMemberCapacity = (row) => asNumber(row.member_count)
const rowEnrolledStaffCount = (row) => asNumber(row.enrolled_staff_count ?? row.paid_staff_count ?? 0)
const rowConfirmedStaffCount = (row) => {
    if (row.has_required_payment) {
        return asNumber(row.confirmed_unpaid_staff_count ?? row.confirmed_staff_count ?? 0)
    }

    return asNumber(row.confirmed_staff_count)
}
const rowStaffCapacity = (row) => asNumber(row.staff_participant_count)

const showEnrolledMembersColumn = computed(() => props.clubSummary.some((row) => {
    return row.has_required_payment
        || asNumber(row.expected_member_payment) > 0
        || rowEnrolledMemberCount(row) > 0
        || asNumber(row.paid_member_count) > 0
}))
const showConfirmedMembersColumn = computed(() => props.clubSummary.some((row) => rowConfirmedMemberCount(row) > 0))
const showEnrolledStaffColumn = computed(() => props.clubSummary.some((row) => rowEnrolledStaffCount(row) > 0 || asNumber(row.paid_staff_count) > 0))
const showConfirmedStaffColumn = computed(() => props.clubSummary.some((row) => rowConfirmedStaffCount(row) > 0))
const summaryColumnCount = computed(() => {
    return 2
        + (showEnrolledMembersColumn.value ? 1 : 0)
        + (showConfirmedMembersColumn.value ? 1 : 0)
        + (showEnrolledStaffColumn.value ? 1 : 0)
        + (showConfirmedStaffColumn.value ? 1 : 0)
})

const summaryTotals = computed(() => props.clubSummary.reduce((totals, row) => ({
    clubs: totals.clubs + 1,
    enrolledMembers: totals.enrolledMembers + rowEnrolledMemberCount(row),
    confirmedMembers: totals.confirmedMembers + rowConfirmedMemberCount(row),
    enrolledStaff: totals.enrolledStaff + rowEnrolledStaffCount(row),
    confirmedStaff: totals.confirmedStaff + rowConfirmedStaffCount(row),
}), {
    clubs: 0,
    enrolledMembers: 0,
    confirmedMembers: 0,
    enrolledStaff: 0,
    confirmedStaff: 0,
}))
const showRosterAssociationColumn = computed(() => props.eventScopeType === 'union' && props.participantRoster.some((row) => row.association_name))
const rosterTotals = computed(() => props.participantRoster.reduce((totals, row) => ({
    participants: totals.participants + 1,
    enrolled: totals.enrolled + (row.is_enrolled ? 1 : 0),
    confirmed: totals.confirmed + (row.is_confirmed ? 1 : 0),
    totalPaid: totals.totalPaid + asNumber(row.total_paid),
    optionalPaid: totals.optionalPaid + asNumber(row.optional_paid),
}), {
    participants: 0,
    enrolled: 0,
    confirmed: 0,
    totalPaid: 0,
    optionalPaid: 0,
}))
const optionalStatusLabel = (status) => ({
    paid: tr('Opcionales pagados', 'Optionals paid'),
    partial: tr('Opcionales parciales', 'Partial optionals'),
    not_paid: tr('Opcionales pendientes', 'Optionals pending'),
    not_available: tr('Sin opcionales', 'No optionals'),
}[status] || tr('Sin opcionales', 'No optionals'))
const optionalStatusClass = (status) => ({
    paid: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    partial: 'border-amber-200 bg-amber-50 text-amber-700',
    not_paid: 'border-gray-200 bg-gray-50 text-gray-600',
    not_available: 'border-gray-200 bg-gray-50 text-gray-500',
}[status] || 'border-gray-200 bg-gray-50 text-gray-500')

const paymentStatusFor = (participant) => {
    const totalExpected = Number(props.paymentConfig?.required_total_amount ?? props.paymentConfig?.total_amount ?? props.paymentConfig?.amount ?? 0)
    if (!props.paymentConfig?.is_payable || totalExpected <= 0) {
        return { status: 'na', amount: 0, payerType: null, payerId: null }
    }

    if ((participant?.role || '').toLowerCase() === 'parent') {
        return { status: 'na', amount: 0, payerType: null, payerId: null }
    }

    if (participant.member_id) {
        const amount = paymentByMember.value.get(Number(participant.member_id)) || 0
        return {
            status: amount >= totalExpected ? 'paid' : 'unpaid',
            amount,
            payerType: 'member',
            payerId: participant.member_id
        }
    }

    if (participant.staff_id) {
        const amount = paymentByStaff.value.get(Number(participant.staff_id)) || 0
        return {
            status: amount >= totalExpected ? 'paid' : 'unpaid',
            amount,
            payerType: 'staff',
            payerId: participant.staff_id
        }
    }

    return { status: 'na', amount: 0, payerType: null, payerId: null }
}

const paymentLinkFor = (participant) => {
    const status = paymentStatusFor(participant)
    if (!props.paymentConfig?.concept_id || !status.payerType || !status.payerId || status.payerType === 'readonly') {
        if (!props.paymentConfig?.is_payable || !status.payerType || !status.payerId) {
            return null
        }
    }
    if (status.status === 'paid') return null
    let participantClubId = 0
    if (participant.member_id) {
        participantClubId = Number(memberById.value.get(participant.member_id)?.club_id || 0)
    } else if (participant.staff_id) {
        participantClubId = Number(staffById.value.get(Number(participant.staff_id))?.club_id || 0)
    }
    const clubConcepts = Array.isArray(props.paymentConfig?.concepts)
        ? props.paymentConfig.concepts.filter((concept) => Number(concept.club_id) === Number(participantClubId))
        : []
    const conceptId = clubConcepts[0]?.id
        || (Array.isArray(props.paymentConfig?.concepts) && props.paymentConfig.concepts.length === 1
            ? props.paymentConfig.concepts[0].id
            : props.paymentConfig?.concept_id)

    return route('club.director.payments', {
        club_id: participantClubId || null,
        concept_id: conceptId || null,
        amount: props.paymentConfig.required_total_amount ?? props.paymentConfig.total_amount ?? props.paymentConfig.amount ?? null,
        member_id: status.payerType === 'member' ? status.payerId : null,
        staff_id: status.payerType === 'staff' ? status.payerId : null,
    })
}

const restrictedClassIds = computed(() => {
    if (participantType.value !== 'club_member') return []
    if (memberSelectMode.value === 'class') return selectedClassIds.value
    if (memberSelectMode.value === 'club') {
        return props.members.map((m) => m.class_id).filter(Boolean)
    }
    return []
})

const restrictedParentIds = computed(() => {
    if (!restrictedClassIds.value.length) return []
    const classSet = new Set(restrictedClassIds.value)
    return props.parents
        .filter((parent) => (parent.children || []).some((child) => classSet.has(child.class_id)))
        .map((parent) => parent.id)
})

const restrictedStaffIds = computed(() => {
    if (!restrictedClassIds.value.length) return []
    const classSet = new Set(restrictedClassIds.value)
    return props.staff
        .filter((staffMember) => (staffMember.classes || []).some((c) => classSet.has(c.id)))
        .map((staffMember) => staffMember.id)
})

const availableParents = computed(() => {
    if (!restrictedParentIds.value.length) return props.parents
    const restricted = new Set(restrictedParentIds.value)
    return props.parents.filter((parent) => !restricted.has(parent.id))
})

const availableStaff = computed(() => {
    const existing = existingStaffIds.value
    const eligible = !restrictedStaffIds.value.length
        ? props.staff
        : props.staff.filter((staffMember) => !new Set(restrictedStaffIds.value).has(staffMember.id))

    return eligible.filter((staffMember) => !existing.has(Number(staffMember.id)))
})

const addMembers = async (memberIds) => {
    const addedIds = []
    for (const memberId of memberIds) {
        if (existingMemberIds.value.has(memberId)) continue
        const member = props.members.find((item) => item.member_id === memberId)
        const name = member?.applicant_name || `Member #${memberId}`
        const id = await addParticipant({
            member_id: memberId,
            participant_name: name,
            role: 'kid',
            status: 'invited',
        })
        if (id) addedIds.push(id)

        const parentId = member?.parent_id
        if (parentId) {
            const parent = props.parents.find((p) => p.id === parentId)
            const parentName = parent?.name
            if (parentName && !existingParentNames.value.has(parentName)) {
                const parentAddedId = await addParticipant({
                    participant_name: parentName,
                    role: 'parent',
                    status: 'invited',
                })
                if (parentAddedId) addedIds.push(parentAddedId)
            }
        }
    }
    return addedIds
}

const addStaffForClasses = async (classIds) => {
    for (const staffMember of props.staff) {
        const staffClasses = staffMember.classes || []
        const isMatch = staffClasses.some((item) => classIds.includes(item.id))
        if (!isMatch) continue
        const name = staffMember.name || `Staff #${staffMember.id}`
        if (existingStaffIds.value.has(Number(staffMember.id)) || existingStaffNames.value.has(name)) continue
        await axios.post(route('event-participants.store', { event: props.eventId }), {
            staff_id: staffMember.id,
            participant_name: name,
            role: 'staff',
            status: 'invited',
        })
    }
}

const addMembersManual = async () => {
    const addedIds = await addMembers(selectedMemberIds.value)
    selectedMemberIds.value = []
    await refresh()
    markHighlights(addedIds)
}

const addMembersByClass = async () => {
    const memberIds = props.members
        .filter((m) => selectedClassIds.value.includes(m.class_id))
        .map((m) => m.member_id)
    const addedIds = await addMembers(memberIds)
    await addStaffForClasses(selectedClassIds.value)
    selectedClassIds.value = []
    await refresh()
    markHighlights(addedIds)
}

const addMembersWholeClub = async () => {
    const memberIds = props.members.map((m) => m.member_id)
    const classIds = [...new Set(props.members.map((m) => m.class_id).filter(Boolean))]
    const addedIds = await addMembers(memberIds)
    await addStaffForClasses(classIds)
    await refresh()
    markHighlights(addedIds)
}

const addStaff = async () => {
    const addedIds = []
    for (const staffId of selectedStaffIds.value) {
        const staffMember = props.staff.find((item) => item.id === staffId)
        const name = staffMember?.name || `Staff #${staffId}`
        if (existingStaffIds.value.has(Number(staffId)) || existingStaffNames.value.has(name)) continue
        const id = await addParticipant({
            staff_id: staffId,
            participant_name: name,
            role: 'staff',
            status: 'invited',
        })
        if (id) addedIds.push(id)
    }
    selectedStaffIds.value = []
    await refresh()
    markHighlights(addedIds)
}

const addParents = async () => {
    const addedIds = []
    for (const parentId of selectedParentIds.value) {
        const parent = props.parents.find((item) => item.id === parentId)
        const name = parent?.name || `Parent #${parentId}`
        if (existingParentNames.value.has(name)) continue
        const id = await addParticipant({
            participant_name: name,
            role: 'parent',
            status: 'invited',
        })
        if (id) addedIds.push(id)
    }
    selectedParentIds.value = []
    await refresh()
    markHighlights(addedIds)
}

const addCustom = async () => {
    if (!customParticipant.value.name.trim()) return
    const id = await addParticipant({
        participant_name: customParticipant.value.name.trim(),
        role: customParticipant.value.role,
        status: customParticipant.value.status,
        emergency_contact_json: customParticipant.value.note
            ? { note: customParticipant.value.note }
            : null,
    })
    customParticipant.value = { name: '', role: 'invitee', status: 'invited', note: '' }
    await refresh()
    markHighlights(id ? [id] : [])
}

const updateStatus = async (participant, status, refreshAfter = true) => {
    await axios.put(route('event-participants.update', { eventParticipant: participant.id }), {
        member_id: participant.member_id ?? null,
        staff_id: participant.staff_id ?? null,
        participant_name: participant.participant_name,
        role: participant.role,
        status,
        permission_received: participant.permission_received ?? false,
        medical_form_received: participant.medical_form_received ?? false,
        emergency_contact_json: participant.emergency_contact_json ?? null,
    })
    if (refreshAfter) {
        await refresh()
    }
}

const removeParticipant = async (participant) => {
    if (!confirm(tr(`¿Eliminar a ${participant.participant_name}?`, `Remove ${participant.participant_name}?`))) return
    await axios.delete(route('event-participants.destroy', { eventParticipant: participant.id }))
    await refresh()
}

const confirmAll = async () => {
    if (!props.participants.length) return
    if (!confirm(tr('¿Confirmar todos los participantes?', 'Confirm all participants?'))) return
    for (const participant of props.participants) {
        if (participant.status === 'confirmed') continue
        await updateStatus(participant, 'confirmed', false)
    }
    await refresh()
}

const deleteAll = async () => {
    if (!props.participants.length) return
    if (!confirm(tr('¿Eliminar todos los participantes? Esta acción no se puede deshacer.', 'Remove all participants? This cannot be undone.'))) return
    for (const participant of props.participants) {
        await axios.delete(route('event-participants.destroy', { eventParticipant: participant.id }))
    }
    await refresh()
}

const deleteSelected = async () => {
    if (!selectedParticipantIds.value.length) return
    if (!confirm(tr('¿Eliminar participantes seleccionados?', 'Remove selected participants?'))) return
    for (const id of selectedParticipantIds.value) {
        await axios.delete(route('event-participants.destroy', { eventParticipant: id }))
    }
    selectedParticipantIds.value = []
    await refresh()
}

const confirmSelected = async () => {
    if (!selectedParticipantIds.value.length) return
    if (!confirm(tr('¿Confirmar participantes seleccionados?', 'Confirm selected participants?'))) return
    for (const participant of props.participants.filter((p) => selectedParticipantIds.value.includes(p.id))) {
        if (participant.status === 'confirmed') continue
        await updateStatus(participant, 'confirmed', false)
    }
    selectedParticipantIds.value = []
    await refresh()
}
</script>

<template>
    <div class="space-y-4">
        <template v-if="!canManage">
            <div class="rounded-lg border bg-white p-4">
                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ tr('Resumen por club', 'Club summary') }}</h3>
                        <p class="mt-1 max-w-3xl text-xs text-gray-500">
                            {{ tr('Para miembros, la inscripción se calcula por pago obligatorio cuando el evento tiene cobro. El staff se cuenta por confirmación de asistencia.', 'For members, enrollment is based on required payment when the event has fees. Staff is counted by attendance confirmation.') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="rounded border border-blue-200 bg-white px-3 py-1 text-xs font-semibold text-blue-700 hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="!participantRoster.length"
                            @click="showRosterModal = true"
                        >
                            {{ tr('Lista general', 'General list') }} · {{ participantRoster.length }}
                        </button>
                        <div class="rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
                            {{ tr('Solo lectura', 'Read only') }}
                        </div>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded border border-gray-200 bg-gray-50 px-3 py-2">
                        <div class="flex items-center text-[11px] font-semibold uppercase text-gray-500">
                            {{ tr('Clubes', 'Clubs') }}
                            <span class="ml-1 inline-flex h-4 w-4 items-center justify-center rounded-full bg-gray-200 text-[10px] font-bold text-gray-600" :title="tr('Clubes visibles para tu nivel en este evento.', 'Clubs visible to your level for this event.')">i</span>
                        </div>
                        <div class="mt-1 text-xl font-semibold text-gray-900">{{ summaryTotals.clubs }}</div>
                    </div>
                    <div v-if="showEnrolledMembersColumn" class="rounded border border-gray-200 bg-gray-50 px-3 py-2">
                        <div class="flex items-center text-[11px] font-semibold uppercase text-gray-500">
                            {{ tr('Miembros inscritos', 'Enrolled members') }}
                            <span class="ml-1 inline-flex h-4 w-4 items-center justify-center rounded-full bg-emerald-100 text-[10px] font-bold text-emerald-700" :title="tr('Miembros que ya cubrieron el pago obligatorio del evento. Si el evento no tiene cobro obligatorio, se toma la confirmación.', 'Members who already covered the required event payment. If the event has no required payment, confirmation is used.')">i</span>
                        </div>
                        <div class="mt-1 text-xl font-semibold text-gray-900">{{ summaryTotals.enrolledMembers }}</div>
                    </div>
                    <div v-if="showConfirmedMembersColumn" class="rounded border border-gray-200 bg-gray-50 px-3 py-2">
                        <div class="flex items-center text-[11px] font-semibold uppercase text-gray-500">
                            {{ tr('Miembros confirmados', 'Confirmed members') }}
                            <span class="ml-1 inline-flex h-4 w-4 items-center justify-center rounded-full bg-amber-100 text-[10px] font-bold text-amber-700" :title="tr('Miembros marcados como confirmados por el club que todavía no cuentan como inscritos por pago obligatorio.', 'Members marked confirmed by the club who do not yet count as enrolled by required payment.')">i</span>
                        </div>
                        <div class="mt-1 text-xl font-semibold text-gray-900">{{ summaryTotals.confirmedMembers }}</div>
                    </div>
                    <div v-if="showEnrolledStaffColumn" class="rounded border border-gray-200 bg-gray-50 px-3 py-2">
                        <div class="flex items-center text-[11px] font-semibold uppercase text-gray-500">
                            {{ tr('Staff inscrito', 'Enrolled staff') }}
                            <span class="ml-1 inline-flex h-4 w-4 items-center justify-center rounded-full bg-emerald-100 text-[10px] font-bold text-emerald-700" :title="tr('Staff con pago obligatorio completo para el evento.', 'Staff with completed required event payment.')">i</span>
                        </div>
                        <div class="mt-1 text-xl font-semibold text-gray-900">{{ summaryTotals.enrolledStaff }}</div>
                    </div>
                    <div v-if="showConfirmedStaffColumn" class="rounded border border-gray-200 bg-gray-50 px-3 py-2">
                        <div class="flex items-center text-[11px] font-semibold uppercase text-gray-500">
                            {{ tr('Staff confirmado', 'Confirmed staff') }}
                            <span class="ml-1 inline-flex h-4 w-4 items-center justify-center rounded-full bg-blue-100 text-[10px] font-bold text-blue-700" :title="tr('Staff confirmado por el club que todavía no cuenta como inscrito por pago obligatorio.', 'Staff confirmed by the club who does not yet count as enrolled by required payment.')">i</span>
                        </div>
                        <div class="mt-1 text-xl font-semibold text-gray-900">{{ summaryTotals.confirmedStaff }}</div>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto rounded-lg border bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-left text-gray-600">
                        <tr>
	                            <th class="px-4 py-3 font-medium">{{ tr('Club', 'Club') }}</th>
	                            <th class="px-4 py-3 font-medium">{{ tr('Estado', 'Status') }}</th>
	                            <th v-if="showEnrolledMembersColumn" class="px-4 py-3 font-medium">
	                                <span class="inline-flex items-center">
	                                    {{ tr('Miembros inscritos', 'Enrolled members') }}
	                                    <span class="ml-1 inline-flex h-4 w-4 items-center justify-center rounded-full bg-emerald-100 text-[10px] font-bold text-emerald-700" :title="tr('Cuenta miembros con pago obligatorio completo.', 'Counts members with completed required payment.')">i</span>
	                                </span>
	                            </th>
	                            <th v-if="showConfirmedMembersColumn" class="px-4 py-3 font-medium">
	                                <span class="inline-flex items-center">
	                                    {{ tr('Miembros confirmados', 'Confirmed members') }}
	                                    <span class="ml-1 inline-flex h-4 w-4 items-center justify-center rounded-full bg-amber-100 text-[10px] font-bold text-amber-700" :title="tr('Cuenta confirmados que todavía no aparecen como inscritos por pago obligatorio.', 'Counts confirmed members who do not yet appear as enrolled by required payment.')">i</span>
	                                </span>
	                            </th>
	                            <th v-if="showEnrolledStaffColumn" class="px-4 py-3 font-medium">
	                                <span class="inline-flex items-center">
	                                    {{ tr('Staff inscrito', 'Enrolled staff') }}
	                                    <span class="ml-1 inline-flex h-4 w-4 items-center justify-center rounded-full bg-emerald-100 text-[10px] font-bold text-emerald-700" :title="tr('Cuenta staff con pago obligatorio completo.', 'Counts staff with completed required payment.')">i</span>
	                                </span>
	                            </th>
	                            <th v-if="showConfirmedStaffColumn" class="px-4 py-3 font-medium">
	                                <span class="inline-flex items-center">
	                                    {{ tr('Staff confirmado', 'Confirmed staff') }}
	                                    <span class="ml-1 inline-flex h-4 w-4 items-center justify-center rounded-full bg-blue-100 text-[10px] font-bold text-blue-700" :title="tr('Cuenta staff confirmado que todavía no está inscrito por pago obligatorio.', 'Counts confirmed staff who is not yet enrolled by required payment.')">i</span>
	                                </span>
	                            </th>
	                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="club in clubSummary" :key="club.club_id" class="border-t align-top">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900">{{ club.club_name }}</div>
                                <div class="text-xs text-gray-500">{{ club.district_name || club.church_name || '—' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold"
                                    :class="club.signup_status === 'signed_up' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : (club.signup_status === 'declined' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-gray-200 bg-gray-50 text-gray-600')"
                                >
                                    {{ club.signup_status || 'targeted' }}
                                </span>
                                <div v-if="club.signed_up_at" class="mt-1 text-xs text-gray-500">{{ club.signed_up_at }}</div>
                            </td>
                            <td v-if="showEnrolledMembersColumn" class="px-4 py-3 text-gray-700">
                                <span class="font-semibold text-gray-900">{{ rowEnrolledMemberCount(club) }}</span>
                                <span class="text-gray-400"> / {{ rowMemberCapacity(club) }}</span>
                            </td>
                            <td v-if="showConfirmedMembersColumn" class="px-4 py-3 text-gray-700">
                                <span class="font-semibold text-gray-900">{{ rowConfirmedMemberCount(club) }}</span>
                                <span class="text-gray-400"> / {{ rowMemberCapacity(club) }}</span>
                            </td>
                            <td v-if="showEnrolledStaffColumn" class="px-4 py-3 text-gray-700">
                                <span class="font-semibold text-gray-900">{{ rowEnrolledStaffCount(club) }}</span>
                                <span class="text-gray-400"> / {{ rowStaffCapacity(club) }}</span>
                            </td>
                            <td v-if="showConfirmedStaffColumn" class="px-4 py-3 text-gray-700">
                                <span class="font-semibold text-gray-900">{{ rowConfirmedStaffCount(club) }}</span>
                                <span class="text-gray-400"> / {{ rowStaffCapacity(club) }}</span>
                            </td>
                        </tr>
                        <tr v-if="!clubSummary.length">
                            <td :colspan="summaryColumnCount" class="px-4 py-8 text-center text-gray-500">
                                {{ tr('No hay clubes visibles para este evento.', 'No visible clubs for this event.') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="showRosterModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                <div class="flex max-h-[90vh] w-full max-w-6xl flex-col overflow-hidden rounded-lg bg-white shadow-xl">
                    <div class="flex flex-col gap-3 border-b px-5 py-4 md:flex-row md:items-start md:justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">{{ tr('Lista general de participantes', 'General participant list') }}</h3>
                            <p class="mt-1 text-sm text-gray-500">
                                {{ tr('Miembros y staff confirmados por el club, junto con los inscritos por pago obligatorio completo.', 'Members and staff confirmed by the club, plus participants enrolled by completed required payment.') }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <a
                                v-if="participantRoster.length"
                                :href="route('events.participant-roster.pdf', { event: eventId })"
                                class="rounded bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700"
                            >
                                {{ tr('Exportar PDF', 'Export PDF') }}
                            </a>
                            <button
                                type="button"
                                class="rounded border border-gray-200 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50"
                                @click="showRosterModal = false"
                            >
                                {{ tr('Cerrar', 'Close') }}
                            </button>
                        </div>
                    </div>

                    <div class="grid gap-3 border-b bg-gray-50 px-5 py-3 text-sm sm:grid-cols-2 lg:grid-cols-5">
                        <div>
                            <div class="text-[11px] font-semibold uppercase text-gray-500">{{ tr('Participantes', 'Participants') }}</div>
                            <div class="text-lg font-semibold text-gray-900">{{ rosterTotals.participants }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] font-semibold uppercase text-gray-500">{{ tr('Inscritos', 'Enrolled') }}</div>
                            <div class="text-lg font-semibold text-gray-900">{{ rosterTotals.enrolled }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] font-semibold uppercase text-gray-500">{{ tr('Confirmados', 'Confirmed') }}</div>
                            <div class="text-lg font-semibold text-gray-900">{{ rosterTotals.confirmed }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] font-semibold uppercase text-gray-500">{{ tr('Pagado total', 'Total paid') }}</div>
                            <div class="text-lg font-semibold text-gray-900">{{ formatMoney(rosterTotals.totalPaid) }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] font-semibold uppercase text-gray-500">{{ tr('Opcionales', 'Optionals') }}</div>
                            <div class="text-lg font-semibold text-gray-900">{{ formatMoney(rosterTotals.optionalPaid) }}</div>
                        </div>
                    </div>

                    <div class="overflow-auto">
                        <table class="min-w-full text-sm">
                            <thead class="sticky top-0 bg-gray-50 text-left text-gray-600">
                                <tr>
                                    <th class="px-4 py-3 font-medium">{{ tr('Participante', 'Participant') }}</th>
                                    <th class="px-4 py-3 font-medium">{{ tr('Club', 'Club') }}</th>
                                    <th class="px-4 py-3 font-medium">{{ tr('Distrito', 'District') }}</th>
                                    <th v-if="showRosterAssociationColumn" class="px-4 py-3 font-medium">{{ tr('Asociación', 'Association') }}</th>
                                    <th class="px-4 py-3 font-medium">{{ tr('Estado', 'Status') }}</th>
                                    <th class="px-4 py-3 text-right font-medium">{{ tr('Pagado', 'Paid') }}</th>
                                    <th class="px-4 py-3 font-medium">{{ tr('Opcionales', 'Optionals') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in participantRoster" :key="row.participant_key || row.member_id || row.staff_id" class="border-t align-top">
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-gray-900">{{ row.name }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ row.participant_type_label || '—' }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-gray-700">{{ row.club_name || '—' }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ row.district_name || '—' }}</td>
                                    <td v-if="showRosterAssociationColumn" class="px-4 py-3 text-gray-700">{{ row.association_name || '—' }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap gap-1">
                                            <span
                                                v-if="row.is_enrolled"
                                                class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700"
                                            >
                                                {{ tr('Inscrito', 'Enrolled') }}
                                            </span>
                                            <span
                                                v-if="row.is_confirmed"
                                                class="rounded-full border border-blue-200 bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700"
                                            >
                                                {{ tr('Confirmado', 'Confirmed') }}
                                            </span>
                                        </div>
                                        <div v-if="row.required_expected > 0" class="mt-1 text-xs text-gray-500">
                                            {{ tr('Obligatorio', 'Required') }}: {{ formatMoney(row.required_paid) }} / {{ formatMoney(row.required_expected) }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold text-gray-900">
                                        {{ formatMoney(row.total_paid) }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold" :class="optionalStatusClass(row.optional_status)">
                                            {{ optionalStatusLabel(row.optional_status) }}
                                        </span>
                                        <div v-if="row.optional_components?.length" class="mt-2 space-y-1 text-xs text-gray-600">
                                            <div
                                                v-for="component in row.optional_components"
                                                :key="`${row.participant_key || row.member_id || row.staff_id}-${component.label}`"
                                                class="flex items-start justify-between gap-3"
                                            >
                                                <span class="break-words">{{ component.label }}</span>
                                                <span class="whitespace-nowrap" :class="component.is_paid ? 'text-emerald-700' : 'text-gray-500'">
                                                    {{ formatMoney(component.paid_amount) }} / {{ formatMoney(component.expected_amount) }}
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!participantRoster.length">
                                    <td :colspan="showRosterAssociationColumn ? 7 : 6" class="px-4 py-8 text-center text-gray-500">
                                        {{ tr('Todavía no hay participantes confirmados o inscritos.', 'There are no confirmed or enrolled participants yet.') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </template>

        <template v-else>
        <div class="bg-white rounded-lg border p-4 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 items-end">
                <div>
                    <div class="text-xs font-semibold text-gray-600 mb-1">{{ tr('Tipo de participante', 'Participant Type') }}</div>
                    <select v-model="participantType" class="w-full border rounded px-2 py-1 text-sm">
                        <option value="club_member">{{ tr('Miembro del club', 'Club Member') }}</option>
                        <option value="club_staff">{{ tr('Personal del club', 'Club Staff') }}</option>
                        <option value="club_parent">{{ tr('Padre del club', 'Club Parent') }}</option>
                        <option value="driver">{{ tr('Conductor', 'Driver') }}</option>
                        <option value="invitee">{{ tr('Invitado', 'Invitee') }}</option>
                        <option value="other">{{ tr('Otro', 'Other') }}</option>
                    </select>
                </div>
                <div v-if="participantType === 'club_member'">
                    <div class="text-xs font-semibold text-gray-600 mb-1">{{ tr('Modo de selección de miembros', 'Member Selection Mode') }}</div>
                    <select v-model="memberSelectMode" class="w-full border rounded px-2 py-1 text-sm">
                        <option value="manual">{{ tr('Seleccionar miembros', 'Select Members') }}</option>
                        <option value="class">{{ tr('Por clase', 'By Class') }}</option>
                        <option value="club">{{ tr('Todo el club', 'Whole Club') }}</option>
                    </select>
                </div>
            </div>

            <div v-if="participantType === 'club_member' && memberSelectMode === 'manual'" class="space-y-2">
                <div class="text-xs font-semibold text-gray-600">{{ tr('Miembros', 'Members') }}</div>
                <select v-model="selectedMemberIds" multiple class="w-full border rounded px-2 py-1 text-sm">
                    <option v-for="member in availableMembers" :key="member.member_id" :value="member.member_id">
                        {{ member.applicant_name }} ({{ classNameById.get(member.class_id) || 'Class —' }})
                    </option>
                </select>
                <button type="button" class="px-3 py-1 rounded text-sm bg-blue-600 text-white" @click="addMembersManual">
                    {{ tr('Agregar miembros seleccionados', 'Add Selected Members') }}
                </button>
            </div>

            <div v-if="participantType === 'club_member' && memberSelectMode === 'class'" class="space-y-2">
                <div class="text-xs font-semibold text-gray-600">{{ tr('Clases', 'Classes') }}</div>
                <select v-model="selectedClassIds" multiple class="w-full border rounded px-2 py-1 text-sm">
                    <option v-for="clubClass in classes" :key="clubClass.id" :value="clubClass.id">
                        {{ clubClass.class_name }}
                    </option>
                </select>
                <button type="button" class="px-3 py-1 rounded text-sm bg-blue-600 text-white" @click="addMembersByClass">
                    {{ tr('Agregar miembros + padres + personal', 'Add Members + Parents + Staff') }}
                </button>
            </div>

            <div v-if="participantType === 'club_member' && memberSelectMode === 'club'" class="space-y-2">
                <div class="text-xs text-gray-600">{{ tr('Agrega todos los miembros, sus padres y el personal de clase.', 'Adds all members, their parents, and class staff.') }}</div>
                <button type="button" class="px-3 py-1 rounded text-sm bg-blue-600 text-white" @click="addMembersWholeClub">
                    {{ tr('Agregar todo el club', 'Add Whole Club') }}
                </button>
            </div>

            <div v-if="participantType === 'club_staff'" class="space-y-2">
                <div class="text-xs font-semibold text-gray-600">{{ tr('Personal', 'Staff') }}</div>
                <select v-model="selectedStaffIds" multiple class="w-full border rounded px-2 py-1 text-sm">
                    <option v-for="staffMember in availableStaff" :key="staffMember.id" :value="staffMember.id">
                        {{ staffMember.name }} ({{ staffMember.type }})
                    </option>
                </select>
                <button type="button" class="px-3 py-1 rounded text-sm bg-blue-600 text-white" @click="addStaff">
                    {{ tr('Agregar personal', 'Add Staff') }}
                </button>
            </div>

            <div v-if="participantType === 'club_parent'" class="space-y-2">
                <div class="text-xs font-semibold text-gray-600">{{ tr('Padres', 'Parents') }}</div>
                <select v-model="selectedParentIds" multiple class="w-full border rounded px-2 py-1 text-sm">
                    <option v-for="parent in availableParents" :key="parent.id" :value="parent.id">
                        {{ parent.name }} ({{ parent.email || 'no email' }})
                    </option>
                </select>
                <button type="button" class="px-3 py-1 rounded text-sm bg-blue-600 text-white" @click="addParents">
                    {{ tr('Agregar padres', 'Add Parents') }}
                </button>
            </div>

            <div v-if="['driver','invitee','other'].includes(participantType)" class="border-t pt-3">
                <div class="text-xs font-semibold text-gray-600 mb-1">{{ tr('Participante personalizado', 'Custom Participant') }}</div>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-2">
                    <input v-model="customParticipant.name" class="border rounded px-2 py-1 text-sm" :placeholder="tr('Nombre', 'Name')" />
                    <select v-model="customParticipant.role" class="border rounded px-2 py-1 text-sm">
                        <option value="driver">{{ tr('Conductor', 'Driver') }}</option>
                        <option value="invitee">{{ tr('Invitado', 'Invitee') }}</option>
                        <option value="other">{{ tr('Otro', 'Other') }}</option>
                        <option value="guest">{{ tr('Huésped', 'Guest') }}</option>
                    </select>
                    <select v-model="customParticipant.status" class="border rounded px-2 py-1 text-sm">
                        <option value="invited">{{ tr('Invitado', 'Invited') }}</option>
                        <option value="confirmed">{{ tr('Confirmado', 'Confirmed') }}</option>
                        <option value="cancelled">{{ tr('Cancelado', 'Cancelled') }}</option>
                    </select>
                    <input v-model="customParticipant.note" class="border rounded px-2 py-1 text-sm" :placeholder="tr('Nota (opcional)', 'Note (optional)')" />
                </div>
                <button type="button" class="mt-2 px-3 py-1 rounded text-sm bg-gray-200 text-gray-700" @click="addCustom">
                    {{ tr('Agregar participante', 'Add Participant') }}
                </button>
            </div>
        </div>

        <div class="bg-white rounded-lg border p-3 flex flex-wrap items-center gap-2 text-xs">
            <button type="button" class="px-2 py-1 rounded bg-blue-600 text-white" @click="emit('finish-list')">
                {{ tr('Finalizar lista', 'Finish List') }}
            </button>
            <button type="button" class="px-2 py-1 rounded bg-green-600 text-white" @click="confirmAll">
                {{ tr('Confirmar todos', 'Confirm All') }}
            </button>
            <button type="button" class="px-2 py-1 rounded bg-gray-200 text-gray-700" @click="confirmSelected" :disabled="!selectedParticipantIds.length">
                {{ tr('Confirmar seleccionados', 'Confirm Selected') }}
            </button>
            <button type="button" class="px-2 py-1 rounded bg-red-600 text-white" @click="deleteAll">
                {{ tr('Eliminar todos', 'Delete All') }}
            </button>
            <button type="button" class="px-2 py-1 rounded bg-red-100 text-red-700" @click="deleteSelected" :disabled="!selectedParticipantIds.length">
                {{ tr('Eliminar seleccionados', 'Delete Selected') }}
            </button>
        </div>

        <div class="overflow-x-auto bg-white rounded-lg border">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
                <tr>
                    <th class="text-left px-4 py-2">
                        <input
                            type="checkbox"
                            :checked="selectedParticipantIds.length && selectedParticipantIds.length === participants.length"
                            @change="(e) => { selectedParticipantIds = e.target.checked ? participants.map(p => p.id) : [] }"
                        />
                    </th>
                    <th class="text-left px-4 py-2">{{ tr('Nombre', 'Name') }}</th>
                    <th class="text-left px-4 py-2">{{ tr('Rol', 'Role') }}</th>
                    <th class="text-left px-4 py-2">{{ tr('Estado', 'Status') }}</th>
                    <th class="text-left px-4 py-2">{{ tr('Pago', 'Payment') }}</th>
                    <th class="text-right px-4 py-2">{{ tr('Acciones', 'Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="participant in participants" :key="participant.id" class="border-t" :class="highlightIds.has(participant.id) ? 'participant-highlight' : ''">
                    <td class="px-4 py-2">
                        <input
                            type="checkbox"
                            :value="participant.id"
                            v-model="selectedParticipantIds"
                        />
                    </td>
                    <td class="px-4 py-2">
                        <div>{{ participant.participant_name }}</div>
                        <div v-if="participant.member_id" class="text-xs text-gray-500">
                            {{ classNameById.get(memberById.get(participant.member_id)?.class_id) || 'Class —' }}
                        </div>
                        <div v-else-if="participant.role === 'parent'" class="text-xs text-gray-500">
                            {{ (parentByName.get(participant.participant_name)?.children || [])[0]?.name || 'Child —' }}
                        </div>
                    </td>
                    <td class="px-4 py-2 capitalize">{{ participant.role }}</td>
                    <td class="px-4 py-2">
                        <select class="border rounded px-2 py-1 text-xs" :value="participant.status" @change="(e) => updateStatus(participant, e.target.value)">
                            <option value="invited">{{ tr('Invitado', 'Invited') }}</option>
                            <option value="confirmed">{{ tr('Confirmado', 'Confirmed') }}</option>
                            <option value="cancelled">{{ tr('Cancelado', 'Cancelled') }}</option>
                        </select>
                    </td>
                    <td class="px-4 py-2">
                        <div v-if="participant.role === 'parent'" class="text-xs text-gray-400">—</div>
                        <div v-else class="flex items-center gap-2 text-xs">
                            <span
                                class="inline-flex items-center justify-center w-2.5 h-2.5 rounded-full"
                                :class="{
                                    'bg-emerald-500': paymentStatusFor(participant).status === 'paid',
                                    'bg-red-500': paymentStatusFor(participant).status === 'unpaid',
                                    'bg-gray-300': paymentStatusFor(participant).status === 'na',
                                }"
                                :title="paymentStatusFor(participant).status === 'paid'
                                    ? tr('Pago recibido', 'Partial payment received')
                                    : (paymentStatusFor(participant).status === 'unpaid' ? tr('Sin pago registrado', 'No payment recorded') : tr('No aplica', 'Not applicable'))"
                            />
                            <span
                                v-if="paymentStatusFor(participant).status === 'paid' && participant.member_id"
                                class="text-emerald-600 text-xs"
                            >
                                {{ tr('Pago registrado', 'Payment submitted') }}
                            </span>
                            <Link
                                v-else-if="paymentLinkFor(participant)"
                                :href="paymentLinkFor(participant)"
                                class="text-blue-600 hover:text-blue-700"
                            >
                                {{ tr('Registrar pago', 'Record payment') }}
                            </Link>
                            <span v-else class="text-gray-400">—</span>
                        </div>
                    </td>
                    <td class="px-4 py-2 text-right">
                        <button type="button" class="text-xs text-red-600" @click="removeParticipant(participant)">
                            {{ tr('Eliminar', 'Remove') }}
                        </button>
                    </td>
                </tr>
                <tr v-if="!participants.length">
                    <td colspan="6" class="px-4 py-6 text-center text-gray-500">{{ tr('Aún no hay participantes.', 'No participants yet.') }}</td>
                </tr>
            </tbody>
        </table>
        </div>
        </template>
    </div>
</template>

<style scoped>
.participant-highlight {
    animation: highlight-fade 2.2s ease-out;
    box-shadow: inset 0 0 0 2px rgba(34, 197, 94, 0.55);
    background-color: rgba(34, 197, 94, 0.08);
}

@keyframes highlight-fade {
    0% {
        background-color: rgba(34, 197, 94, 0.18);
        box-shadow: inset 0 0 0 2px rgba(34, 197, 94, 0.75);
    }
    100% {
        background-color: rgba(34, 197, 94, 0);
        box-shadow: inset 0 0 0 0 rgba(34, 197, 94, 0);
    }
}
</style>
